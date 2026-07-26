<?php

declare(strict_types=1);

/**
 * Builds the weekly sales report combining web-store sales (MySQL), quote
 * sales (MySQL), and DoorDash sales (a manually downloaded CSV) into one
 * gap-filled, ISO-week-by-week series, keeping flower sales separate from
 * delivery fees, sales tax, and DoorDash commission.
 *
 * There is no DoorDash API integration in this codebase, so DoorDash figures
 * are only included when the owner has downloaded a Merchant Portal financial
 * CSV and passed it via --doordash; without it, the report still runs but
 * says so prominently rather than silently under-reporting revenue.
 *
 * The database connection is pinned to `SET SESSION time_zone = '+00:00'`
 * (a numeric offset, not a named zone) before any read: named zones require
 * the `mysql.time_zone` tables, which are not populated on this shared host,
 * so `CONVERT_TZ` with a zone name silently returns NULL. Pinning to a
 * numeric offset makes every `TIMESTAMP` read back as true UTC, which
 * {@see \App\Support\WeekBucket} requires — the server's `SYSTEM` zone here
 * resolves to US Eastern while the app runs America/Chicago, a one-hour skew
 * large enough to move a late-evening sale into the wrong week.
 *
 * Only the read-only database connection is ever used ({@see \App\Core\Database::ro()});
 * this script never writes to the database.
 *
 * Usage:
 *   php bin/sales-report.php [--doordash=FILE.csv] [--out=DIR] [--since=YYYY-MM-DD]
 *                            [--html] [--dry-run|-n] [--help|-h]
 *
 * Exit codes:
 *   0 - clean run, no warnings.
 *   1 - the report was produced but at least one warning was raised (a data
 *       inconsistency the report surfaces rather than hides).
 *   2 - a usage or configuration error; nothing was read or written.
 *
 * @see \App\Support\SalesChannel          The channel constants and accounting identity.
 * @see \App\Support\WeekBucket            UTC-to-business-timezone week bucketing.
 * @see \App\Support\QuoteSalesBreakdown   Splits a quote row into merchandise/delivery/fee/tax.
 * @see \App\Support\DoorDashSalesCsv      Parses the DoorDash Merchant Portal CSV export.
 * @see \App\Support\WeeklySalesAggregator Aggregates channel rows into the weekly series.
 * @see \App\Support\SalesReportCsv        Formats the aggregate as CSV-ready rows.
 * @see \App\Support\SalesReportHtml       Renders the aggregate as a self-contained HTML page.
 */

use App\Core\Database;
use App\Support\DoorDashSalesCsv;
use App\Support\QuoteSalesBreakdown;
use App\Support\SalesChannel;
use App\Support\SalesLineClassifier;
use App\Support\SalesReportCsv;
use App\Support\SalesReportHtml;
use App\Support\WeekBucket;
use App\Support\WeeklySalesAggregator;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

/**
 * Full usage/help text, printed to STDOUT for --help and to STDERR (after a
 * specific error line) for any other usage mistake.
 *
 * @return string The multi-line usage text, without a trailing extra blank line.
 */
function usageText(): string
{
    return <<<TXT
    Usage: php bin/sales-report.php [--doordash=FILE.csv] [--out=DIR] [--since=YYYY-MM-DD] [--html] [--dry-run|-n] [--help|-h]

      --doordash=FILE.csv   DoorDash Merchant Portal financial CSV export to include.
                            Without this flag, DoorDash sales are omitted and the
                            report says so prominently rather than under-reporting.
      --out=DIR             Directory to write the report files into. Defaults to
                            SALES_REPORT_DIR from the environment, or
                            \$HOME/private/flowers-sales. Refused if it resolves to
                            inside this project (the project root is the site's
                            public docroot).
      --since=YYYY-MM-DD    Only include sales recognized on or after this date.
      --html                Also write a self-contained HTML copy of the report.
      --dry-run, -n         Print the terminal summary only; write no files.
      --help, -h            Show this message and exit.

    Exit codes: 0 clean run, 1 report produced with warnings, 2 usage/configuration error.

    TXT;
}

/**
 * Parse the script's CLI arguments into a typed options array.
 *
 * @param list<string> $args The argument vector, without the script name (`$argv[1..]`).
 *
 * @return array{doordash: ?string, out: ?string, since: ?string, html: bool,
 *              dryRun: bool, help: bool} The parsed options; string options are
 *         null when not supplied and not yet validated or path-expanded.
 *
 * @throws \InvalidArgumentException When an argument is not one of the
 *         recognised flags, naming the offending argument.
 *
 * @example
 *   parseArgs(['--doordash=export.csv', '--html']);
 *   // ['doordash' => 'export.csv', 'out' => null, 'since' => null,
 *   //  'html' => true, 'dryRun' => false, 'help' => false]
 */
function parseArgs(array $args): array
{
    $options = [
        'doordash' => null,
        'out'      => null,
        'since'    => null,
        'html'     => false,
        'dryRun'   => false,
        'help'     => false,
    ];

    foreach ($args as $arg) {
        match (true) {
            $arg === '--help' || $arg === '-h'     => $options['help'] = true,
            $arg === '--html'                       => $options['html'] = true,
            $arg === '--dry-run' || $arg === '-n'   => $options['dryRun'] = true,
            str_starts_with($arg, '--doordash=')    => $options['doordash'] = substr($arg, strlen('--doordash=')),
            str_starts_with($arg, '--out=')         => $options['out'] = substr($arg, strlen('--out=')),
            str_starts_with($arg, '--since=')       => $options['since'] = substr($arg, strlen('--since=')),
            default => throw new \InvalidArgumentException("Unknown option: {$arg}"),
        };
    }

    return $options;
}

/**
 * Expand a leading `~/` (or bare `~`) in a path to the user's home directory.
 *
 * Exists because the shell does not expand `~` when it appears after an `=`
 * (e.g. `--out=~/private/flowers-sales`), so this script must do it itself.
 *
 * @param string $path A path that may start with `~`.
 *
 * @return string The path with a leading `~` replaced by the home directory,
 *         or the original path unchanged when it doesn't start with `~`, or
 *         when no home directory can be determined.
 *
 * @example
 *   expandHome('~/private/flowers-sales'); // '/home/owner/private/flowers-sales'
 *   expandHome('/already/absolute');       // '/already/absolute'
 */
function expandHome(string $path): string
{
    if ($path !== '~' && !str_starts_with($path, '~/') && !str_starts_with($path, '~\\')) {
        return $path;
    }

    $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: getenv('USERPROFILE'));

    if ($home === false || $home === null || $home === '') {
        return $path;
    }

    return $path === '~' ? $home : $home . substr($path, 1);
}

/**
 * Validate a `--since` value as a real `YYYY-MM-DD` calendar date.
 *
 * @param string $value The candidate date string.
 *
 * @return bool True when `$value` is exactly `YYYY-MM-DD` and a real calendar date.
 *
 * @example
 *   isValidIsoDate('2026-07-25'); // true
 *   isValidIsoDate('2026-02-30'); // false (not a real date)
 *   isValidIsoDate('07/25/2026'); // false (wrong format)
 */
function isValidIsoDate(string $value): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

/**
 * Resolve the output directory from `--out`, the `SALES_REPORT_DIR`
 * environment variable, or the built-in default, in that priority order.
 *
 * The built-in default deliberately lives under the user's home directory
 * rather than anywhere inside the project: this project's root IS the site's
 * public docroot, and `.htaccess` serves any existing file directly, so a
 * report CSV written into the project would be publicly downloadable.
 *
 * @param ?string $outOption The raw `--out` value, or null when not supplied.
 *
 * @return string The resolved, `~`-expanded output directory (not yet
 *         validated to exist or to be outside the project).
 *
 * @throws never Exits the process directly with code 2 when no home
 *         directory can be determined for the default.
 *
 * @example
 *   resolveOutDir('~/reports'); // '/home/owner/reports'
 *   resolveOutDir(null);        // e.g. '/home/owner/private/flowers-sales'
 */
function resolveOutDir(?string $outOption): string
{
    if ($outOption !== null) {
        return expandHome($outOption);
    }

    $envDir = getenv('SALES_REPORT_DIR');
    if ($envDir !== false && $envDir !== '') {
        return expandHome($envDir);
    }

    $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: getenv('USERPROFILE'));

    if ($home === false || $home === null || $home === '') {
        fwrite(STDERR, "Cannot determine a default --out directory: neither SALES_REPORT_DIR nor HOME is set.\n");
        exit(2);
    }

    return rtrim($home, '/\\') . '/private/flowers-sales';
}

/**
 * Refuse an output directory that resolves to inside the project root.
 *
 * This is a real security guard, not a nicety: this project's root is the
 * site's public docroot, and `.htaccess` serves any existing file directly,
 * so a financial CSV written inside it would be silently publicly
 * downloadable. Walks up from `$outDir` to the nearest ancestor that already
 * exists (since `$outDir` itself is typically created later by
 * {@see ensureOutDir()}) and compares its `realpath()` against the project
 * root's `realpath()`.
 *
 * @param string $outDir      The resolved (but not yet created) output directory.
 * @param string $projectRoot The project root to guard against, e.g. `dirname(__DIR__)`.
 *
 * @throws never Exits the process directly with code 2 and an explicit
 *         refusal message when `$outDir` resolves inside `$projectRoot`.
 *
 * @example
 *   assertOutsideProject('/home/owner/private/flowers-sales', '/home/owner/public_html/site');
 *   // returns normally
 *   assertOutsideProject('/home/owner/public_html/site/reports', '/home/owner/public_html/site');
 *   // prints a refusal to STDERR and exits with code 2
 */
function assertOutsideProject(string $outDir, string $projectRoot): void
{
    $projectReal = realpath($projectRoot);
    if ($projectReal === false) {
        return;
    }

    $probe = $outDir;
    while ($probe !== '' && !is_dir($probe)) {
        $parent = dirname($probe);
        if ($parent === $probe) {
            break;
        }
        $probe = $parent;
    }

    $probeReal = is_dir($probe) ? realpath($probe) : false;
    $target    = $probeReal !== false
        ? $probeReal
        : rtrim(str_replace('\\', '/', $outDir), '/');
    $project = rtrim(str_replace('\\', '/', $projectReal), '/');
    $target  = rtrim(str_replace('\\', '/', $target), '/');

    $isInside = $target === $project || str_starts_with($target . '/', $project . '/');

    if ($isInside) {
        fwrite(STDERR, "Refusing to write to \"{$outDir}\": it resolves inside the project root ({$projectReal}).\n");
        fwrite(STDERR, "The project root is the site's public docroot and .htaccess serves any existing file\n");
        fwrite(STDERR, "directly, so a report written there would be publicly downloadable. Choose a directory\n");
        fwrite(STDERR, "outside the project (see --out in --help).\n");
        exit(2);
    }
}

/**
 * Parse a MySQL `'Y-m-d H:i:s'` string, already known to be UTC (the
 * connection is pinned to `SET SESSION time_zone = '+00:00'`), into a UTC
 * `\DateTimeImmutable`.
 *
 * @param string $value   The raw timestamp string from the database.
 * @param string $context A human-readable id (e.g. `'order-42'`) used only to
 *        make a parse failure actionable.
 *
 * @return \DateTimeImmutable The timestamp, tagged UTC.
 *
 * @throws \RuntimeException When `$value` cannot be parsed as `'Y-m-d H:i:s'`,
 *         naming both the value and `$context`.
 *
 * @example
 *   parseUtcTimestamp('2026-07-24 01:13:38', 'order-42');
 *   // DateTimeImmutable for 2026-07-24T01:13:38+00:00
 */
function parseUtcTimestamp(string $value, string $context): \DateTimeImmutable
{
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

    if ($parsed === false) {
        throw new \RuntimeException("Unparseable UTC timestamp \"{$value}\" for {$context}.");
    }

    return $parsed;
}

/**
 * Build one shop-order ChannelRow from a raw `orders` row.
 *
 * @param array{id: int|string, subtotal: float|string, delivery_fee: float|string,
 *              tax_amount: float|string, total: float|string, recognized_at: string} $order
 *        One row from the shop-orders query.
 *
 * @return array{channel: string, source_id: string, recognized_at: \DateTimeImmutable,
 *              merchandise: float, delivery: float, tax: float, fees: float,
 *              recognized_sales: float, deposit_collected: float, warnings: list<string>}
 *         One ChannelRow for the SHOP channel.
 *
 * @example
 *   buildShopRow(['id' => 13, 'subtotal' => '90.00', 'delivery_fee' => '10.00',
 *       'tax_amount' => '7.67', 'total' => '107.67', 'recognized_at' => '2026-07-20 15:00:00']);
 *   // ['channel' => 'shop', 'source_id' => 'order-13', ...
 *   //  'merchandise' => 90.00, 'delivery' => 10.00, 'tax' => 7.67, 'fees' => 0.00,
 *   //  'recognized_sales' => 107.67, 'deposit_collected' => 0.00, 'warnings' => []]
 */
function buildShopRow(array $order): array
{
    $merchandise = round((float) $order['subtotal'], 2);
    $delivery    = round((float) $order['delivery_fee'], 2);
    $tax         = round((float) $order['tax_amount'], 2);
    $recognized  = SalesChannel::recognize($merchandise, $delivery, $tax, 0.00);
    $storedTotal = round((float) $order['total'], 2);

    $warnings = [];
    if (abs($recognized - $storedTotal) > 0.01) {
        $warnings[] = sprintf(
            'order-%s: recognized sales ($%.2f) disagrees with orders.total ($%.2f) by more than a cent.',
            $order['id'],
            $recognized,
            $storedTotal
        );
    }

    return [
        'channel'           => SalesChannel::SHOP,
        'source_id'         => "order-{$order['id']}",
        'recognized_at'     => parseUtcTimestamp((string) $order['recognized_at'], "order-{$order['id']}"),
        'merchandise'       => $merchandise,
        'delivery'          => $delivery,
        'tax'               => $tax,
        'fees'              => 0.00,
        'recognized_sales'  => $recognized,
        'deposit_collected' => 0.00,
        'warnings'          => $warnings,
    ];
}

/**
 * Load every paid shop-cart order as ChannelRows.
 *
 * `items_json IS NOT NULL` is the shop-order discriminator: the `orders`
 * table also holds bouquet *requests*, which are not sales. Retries once with
 * plain `created_at` when `orders.paid_at` doesn't exist yet (migration 019
 * unapplied), rather than letting an unapplied migration break the report.
 *
 * @param \PDO $pdo A connection pinned to `SET SESSION time_zone = '+00:00'`.
 *
 * @return array{rows: list<array{channel: string, source_id: string,
 *              recognized_at: \DateTimeImmutable, merchandise: float, delivery: float,
 *              tax: float, fees: float, recognized_sales: float,
 *              deposit_collected: float, warnings: list<string>}>, warnings: list<string>}
 *         The SHOP ChannelRows and any structural warning (currently only the
 *         migration-019 fallback notice).
 *
 * @see buildShopRow()
 */
function loadShopRows(\PDO $pdo): array
{
    $warnings = [];

    try {
        $stmt = $pdo->query(
            "SELECT id, subtotal, delivery_fee, tax_amount, total,
                    COALESCE(paid_at, created_at) AS recognized_at
               FROM orders
              WHERE payment_status = 'paid' AND items_json IS NOT NULL
              ORDER BY recognized_at"
        );
    } catch (\PDOException $e) {
        $stmt = $pdo->query(
            "SELECT id, subtotal, delivery_fee, tax_amount, total,
                    created_at AS recognized_at
               FROM orders
              WHERE payment_status = 'paid' AND items_json IS NOT NULL
              ORDER BY recognized_at"
        );
        $warnings[] = 'migration 019 (orders.paid_at) is not applied; used created_at as recognized_at for every shop order.';
    }

    $rows = array_map(buildShopRow(...), $stmt->fetchAll());

    return ['rows' => $rows, 'warnings' => $warnings];
}

/**
 * Build one quote ChannelRow (and its non-merchandise audit lines) from a
 * raw `quotes` row.
 *
 * `other_fee` from {@see QuoteSalesBreakdown::fromQuote()} is folded into the
 * ChannelRow's `merchandise` figure, because Shape A (ChannelRow) has no
 * `other_fee` field of its own — folding keeps `merchandise + delivery + tax
 * - fees = recognized_sales` true for this row, exactly matching how
 * `fromQuote()` already derived `recognized_sales`.
 *
 * @param array{id: int|string, items_json: string, subtotal: float|string,
 *              tax_amount: float|string, delivery_fee: float|string,
 *              deposit_amount: float|string, status: string,
 *              deposit_confirmed_at: string} $quote One row from the quotes query.
 *
 * @return array{0: array{channel: string, source_id: string, recognized_at: \DateTimeImmutable,
 *              merchandise: float, delivery: float, tax: float, fees: float,
 *              recognized_sales: float, deposit_collected: float, warnings: list<string>},
 *         1: list<array{source_id: string, week_start: string, description: string,
 *              amount: float, bucket: string, reason: string}>}
 *         The QUOTE ChannelRow, followed by the audit-line entries for lines
 *         classified as delivery or other-fee only (see
 *         {@see \App\Support\SalesReportCsv::warningRows()}).
 *
 * @see \App\Support\QuoteSalesBreakdown::fromQuote()
 */
function buildQuoteRow(array $quote): array
{
    $breakdown    = QuoteSalesBreakdown::fromQuote($quote);
    $recognizedAt = parseUtcTimestamp((string) $quote['deposit_confirmed_at'], "quote-{$quote['id']}");
    $weekStart    = WeekBucket::fromUtc($recognizedAt)['week_start'];

    $row = [
        'channel'           => SalesChannel::QUOTE,
        'source_id'         => "quote-{$quote['id']}",
        'recognized_at'     => $recognizedAt,
        'merchandise'       => round($breakdown['merchandise'] + $breakdown['other_fee'], 2),
        'delivery'          => $breakdown['delivery'],
        'tax'               => $breakdown['tax'],
        'fees'              => 0.00,
        'recognized_sales'  => $breakdown['recognized_sales'],
        'deposit_collected' => $breakdown['deposit_collected'],
        'warnings'          => $breakdown['warnings'],
    ];

    $auditLines = [];
    foreach ($breakdown['lines'] as $line) {
        if ($line['bucket'] !== SalesLineClassifier::DELIVERY && $line['bucket'] !== SalesLineClassifier::OTHER_FEE) {
            continue;
        }

        $auditLines[] = [
            'source_id'   => $row['source_id'],
            'week_start'  => $weekStart,
            'description' => $line['description'],
            'amount'      => $line['amount'],
            'bucket'      => $line['bucket'],
            'reason'      => $line['reason'],
        ];
    }

    return [$row, $auditLines];
}

/**
 * Load every deposit-confirmed quote as ChannelRows, recognized in full on
 * the week its deposit was confirmed.
 *
 * @param \PDO $pdo A connection pinned to `SET SESSION time_zone = '+00:00'`.
 *
 * @return array{rows: list<array{channel: string, source_id: string,
 *              recognized_at: \DateTimeImmutable, merchandise: float, delivery: float,
 *              tax: float, fees: float, recognized_sales: float,
 *              deposit_collected: float, warnings: list<string>}>,
 *         auditLines: list<array{source_id: string, week_start: string, description: string,
 *              amount: float, bucket: string, reason: string}>}
 *         The QUOTE ChannelRows, and every quote line classified as delivery
 *         or other-fee, for the warnings CSV's audit trail.
 *
 * @see buildQuoteRow()
 */
function loadQuoteRows(\PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, items_json, subtotal, tax_amount, delivery_fee, deposit_amount,
                status, deposit_confirmed_at
           FROM quotes
          WHERE status IN ('deposit_confirmed', 'completed')
            AND deposit_confirmed_at IS NOT NULL
          ORDER BY deposit_confirmed_at"
    );

    $rows       = [];
    $auditLines = [];

    foreach ($stmt->fetchAll() as $quote) {
        [$row, $lines] = buildQuoteRow($quote);
        $rows[]     = $row;
        $auditLines = [...$auditLines, ...$lines];
    }

    return ['rows' => $rows, 'auditLines' => $auditLines];
}

/**
 * Load every quote in an inconsistent deposit-confirmation state, as
 * self-contained warning sentences.
 *
 * These rows never become ChannelRows (their status/timestamp combination
 * makes their money position ambiguous) but must never be silently dropped
 * from the report either.
 *
 * @param \PDO $pdo A connection pinned to `SET SESSION time_zone = '+00:00'`.
 *
 * @return list<string> One warning per inconsistent quote, naming its id,
 *         status, and `deposit_confirmed_at` value.
 *
 * @example
 *   // quote-7 has status 'draft' but a deposit_confirmed_at timestamp anyway:
 *   loadExceptions($pdo);
 *   // ['quote-7: status "draft" with deposit_confirmed_at 2026-07-01 12:00:00 is an inconsistent state; excluded from the report.']
 */
function loadExceptions(\PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, status, deposit_confirmed_at FROM quotes
          WHERE (status IN ('deposit_confirmed','completed') AND deposit_confirmed_at IS NULL)
             OR (status NOT IN ('deposit_confirmed','completed') AND deposit_confirmed_at IS NOT NULL)"
    );

    $warnings = [];
    foreach ($stmt->fetchAll() as $quote) {
        $warnings[] = sprintf(
            'quote-%s: status "%s" with deposit_confirmed_at %s is an inconsistent state; excluded from the report.',
            $quote['id'],
            $quote['status'],
            $quote['deposit_confirmed_at'] ?? 'NULL'
        );
    }

    return $warnings;
}

/**
 * Read, parse, and validate a DoorDash Merchant Portal CSV export into
 * ChannelRows.
 *
 * This is the only place `fopen`/`fgetcsv` happens: {@see DoorDashSalesCsv}
 * itself takes already-parsed rows and does no filesystem access.
 *
 * @param string $path The `--doordash` value, possibly `~`-prefixed.
 *
 * @return array{rows: list<array{channel: string, source_id: string,
 *              recognized_at: \DateTimeImmutable, merchandise: float, delivery: float,
 *              tax: float, fees: float, recognized_sales: float,
 *              deposit_collected: float, warnings: list<string>}>,
 *         warnings: list<string>, unmapped_headers: list<string>, source: string}
 *         The DOORDASH ChannelRows, CSV-structural warnings, unmapped header
 *         text, and the basename of the file that was imported.
 *
 * @throws never Exits the process directly with code 2 when the file is
 *         missing/unreadable, or when {@see DoorDashSalesCsv::parse()} throws
 *         because a required column mapping is missing.
 */
function loadDoorDashRows(string $path): array
{
    $expanded = expandHome($path);

    if (!is_file($expanded) || !is_readable($expanded)) {
        fwrite(STDERR, "DoorDash CSV not found or unreadable: {$expanded}\n");
        exit(2);
    }

    $handle = fopen($expanded, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Unable to open DoorDash CSV: {$expanded}\n");
        exit(2);
    }

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    $map = require DoorDashSalesCsv::defaultMapPath();

    try {
        $parsed = DoorDashSalesCsv::parse($rows, $map);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }

    return [
        'rows'             => $parsed['rows'],
        'warnings'         => $parsed['warnings'],
        'unmapped_headers' => $parsed['unmapped_headers'],
        'source'           => basename($expanded),
    ];
}

/**
 * Compute the UTC instant of midnight, business-timezone, for a `--since` date.
 *
 * @param string $since A validated `YYYY-MM-DD` date string.
 *
 * @return \DateTimeImmutable Midnight of `$since` in {@see WeekBucket::businessZone()}, converted to UTC.
 *
 * @example
 *   sinceThresholdUtc('2026-07-20'); // 2026-07-20T05:00:00+00:00 (CDT, UTC-5)
 */
function sinceThresholdUtc(string $since): \DateTimeImmutable
{
    return (new \DateTimeImmutable("{$since} 00:00:00", WeekBucket::businessZone()))
        ->setTimezone(new \DateTimeZone('UTC'));
}

/**
 * Filter a flat list of ChannelRows to those recognized on or after `$since`.
 *
 * @param list<array{recognized_at: \DateTimeImmutable}> $channelRows The rows to filter.
 * @param ?string $since A validated `YYYY-MM-DD` date, or null to skip filtering.
 *
 * @return list<array{recognized_at: \DateTimeImmutable}> `$channelRows` unchanged
 *         when `$since` is null, otherwise only the rows recognized on/after
 *         midnight business-time on that date.
 */
function filterSince(array $channelRows, ?string $since): array
{
    if ($since === null) {
        return $channelRows;
    }

    $threshold = sinceThresholdUtc($since);

    return array_values(array_filter(
        $channelRows,
        static fn (array $row): bool => $row['recognized_at'] >= $threshold
    ));
}

/**
 * Append global (not week-specific) warning strings onto an aggregate.
 *
 * Used for warnings that don't belong to any one ChannelRow or week — the
 * migration-019 fallback notice, orphaned-quote exceptions, and DoorDash CSV
 * structural warnings.
 *
 * @param array{warnings: list<string>} $aggregate The aggregate from
 *        {@see WeeklySalesAggregator::aggregate()} (only `warnings` is read/written).
 * @param list<string> $extraWarnings Warnings to append.
 *
 * @return array{warnings: list<string>} `$aggregate` with `warnings` extended.
 */
function appendGlobalWarnings(array $aggregate, array $extraWarnings): array
{
    $aggregate['warnings'] = [...$aggregate['warnings'], ...$extraWarnings];

    return $aggregate;
}

/**
 * Format a dollar amount for the fixed-width terminal table (no currency
 * symbol, comma thousands separator, right-aligned by the caller's `%12s`).
 *
 * @param float $amount Dollar amount, already rounded to 2dp upstream.
 *
 * @return string A comma-thousands, 2-decimal string, e.g. `'1,234.50'`.
 */
function formatMoneyColumn(float $amount): string
{
    return number_format($amount, 2);
}

/**
 * Render one row of the terminal weekly table (a week, or the `TOTAL` footer).
 *
 * @param string $label The `week_start` cell, or `'TOTAL'`.
 * @param array{merchandise: float, delivery: float, tax: float, fees: float,
 *              recognized_sales: float} $total Combined totals for the row.
 *
 * @return string One right-aligned, fixed-width table row, newline-terminated.
 */
function renderWeekRow(string $label, array $total): string
{
    return sprintf(
        "%-12s %12s %12s %12s %12s %14s\n",
        $label,
        formatMoneyColumn($total['merchandise']),
        formatMoneyColumn($total['delivery']),
        formatMoneyColumn($total['tax']),
        formatMoneyColumn($total['fees']),
        formatMoneyColumn($total['recognized_sales'])
    );
}

/**
 * Render the fixed-width, aligned weekly table with a trailing `TOTAL` row.
 *
 * @param array{weeks: list<array{week_start: string, total: array{merchandise: float,
 *              delivery: float, tax: float, fees: float, recognized_sales: float}}>,
 *         totals: array{total: array{merchandise: float, delivery: float, tax: float,
 *              fees: float, recognized_sales: float}}} $aggregate The weekly sales aggregate.
 *
 * @return string The complete table, header through `TOTAL` row, newline-terminated per row.
 */
function renderWeeklyTable(array $aggregate): string
{
    $header = sprintf(
        "%-12s %12s %12s %12s %12s %14s\n",
        'Week',
        'Merchandise',
        'Delivery',
        'Tax',
        'Fees',
        'Recognized'
    );
    $separator = str_repeat('-', 78) . "\n";

    $lines = $header . $separator;

    foreach ($aggregate['weeks'] as $week) {
        $lines .= renderWeekRow($week['week_start'], $week['total']);
    }

    $lines .= $separator;
    $lines .= renderWeekRow('TOTAL', $aggregate['totals']['total']);

    return $lines;
}

/**
 * Render the total-recognized-sales summary and its flowers/delivery/tax/
 * DoorDash-commission split.
 *
 * @param array{totals: array{channels: array<string, array{fees: float}>,
 *              total: array{merchandise: float, delivery: float, tax: float,
 *              recognized_sales: float}}} $aggregate The weekly sales aggregate.
 *
 * @return string A multi-line summary block, newline-terminated per line.
 */
function renderTotalsSummary(array $aggregate): string
{
    $total        = $aggregate['totals']['total'];
    $doordashFees = $aggregate['totals']['channels'][SalesChannel::DOORDASH]['fees'];

    return sprintf(
        "Total recognized sales: \$%s\n" .
        "  Flowers:             \$%s\n" .
        "  Delivery:            \$%s\n" .
        "  Tax:                 \$%s\n" .
        "  DoorDash commission: \$%s\n",
        number_format($total['recognized_sales'], 2),
        number_format($total['merchandise'], 2),
        number_format($total['delivery'], 2),
        number_format($total['tax'], 2),
        number_format($doordashFees, 2)
    );
}

/**
 * Render the DoorDash inclusion note — prominent when DoorDash was omitted,
 * so a partial report is never mistakable for a complete one.
 *
 * @param array{doordash_included: bool, doordash_source: ?string,
 *              doordash_row_count: int} $meta Report metadata.
 *
 * @return string A single newline-terminated line.
 */
function renderDoorDashNote(array $meta): string
{
    if (!$meta['doordash_included']) {
        return "DoorDash: NOT INCLUDED (no CSV supplied via --doordash)\n";
    }

    return sprintf(
        "DoorDash: included from %s (%d row(s) imported)\n",
        $meta['doordash_source'],
        $meta['doordash_row_count']
    );
}

/**
 * Render the `WARNINGS (n)` block, listing every warning in full — never truncated.
 *
 * @param array{warnings: list<string>} $aggregate The weekly sales aggregate.
 *
 * @return string The warnings block, newline-terminated per line.
 */
function renderWarningsBlock(array $aggregate): string
{
    $warnings = $aggregate['warnings'];

    $lines = sprintf("WARNINGS (%d)\n", count($warnings));

    if ($warnings === []) {
        return $lines . "  (none)\n";
    }

    foreach ($warnings as $warning) {
        $lines .= "  - {$warning}\n";
    }

    return $lines;
}

/**
 * Render the note about DoorDash CSV headers that matched nothing, explaining
 * they may be a new fee column that needs adding to the header map.
 *
 * @param list<string> $unmappedHeaders Raw header text from the DoorDash CSV
 *        that matched no configured field or fee column.
 *
 * @return string The note block, or `''` when there are none.
 */
function renderUnmappedHeadersNote(array $unmappedHeaders): string
{
    if ($unmappedHeaders === []) {
        return '';
    }

    return sprintf(
        "NOTE: unmapped DoorDash CSV column(s): %s\n" .
        "These may be a new fee/column DoorDash added — review and, if so, add them to\n" .
        "config/doordash-report-map.php.\n",
        implode(', ', $unmappedHeaders)
    );
}

/**
 * Print the complete terminal summary: the weekly table, the recognized-sales
 * split, the DoorDash inclusion note, every warning, and any unmapped
 * DoorDash headers.
 *
 * @param array{weeks: list<array{week_start: string, total: array{merchandise: float,
 *              delivery: float, tax: float, fees: float, recognized_sales: float}}>,
 *         totals: array{channels: array<string, array{fees: float}>,
 *              total: array{merchandise: float, delivery: float, tax: float,
 *              fees: float, recognized_sales: float}}, warnings: list<string>} $aggregate
 *        The weekly sales aggregate (warnings already merged, see {@see appendGlobalWarnings()}).
 * @param array{doordash_included: bool, doordash_source: ?string,
 *              doordash_row_count: int, unmapped_headers: list<string>} $meta Report metadata.
 */
function renderTerminalSummary(array $aggregate, array $meta): void
{
    fwrite(STDOUT, "\n" . renderWeeklyTable($aggregate));
    fwrite(STDOUT, "\n" . renderTotalsSummary($aggregate));
    fwrite(STDOUT, "\n" . renderDoorDashNote($meta));
    fwrite(STDOUT, "\n" . renderWarningsBlock($aggregate));

    $note = renderUnmappedHeadersNote($meta['unmapped_headers']);
    if ($note !== '') {
        fwrite(STDOUT, "\n" . $note);
    }
}

/**
 * The last PHP error's message, for turning a suppressed `@`-prefixed
 * filesystem call's failure into an actionable STDERR line.
 *
 * @return string The last error message, or a generic fallback when none was recorded.
 */
function lastErrorMessage(): string
{
    $error = error_get_last();

    return $error['message'] ?? 'unknown error';
}

/**
 * Create the output directory (mode 0700 — it holds financial data on shared
 * hosting) if it doesn't already exist.
 *
 * @param string $outDir The directory to ensure exists.
 *
 * @throws never Exits the process directly with code 1 and the underlying
 *         reason on STDERR when the directory cannot be created.
 */
function ensureOutDir(string $outDir): void
{
    if (is_dir($outDir)) {
        return;
    }

    if (!@mkdir($outDir, 0700, true) && !is_dir($outDir)) {
        fwrite(STDERR, "Unable to create output directory \"{$outDir}\": " . lastErrorMessage() . "\n");
        exit(1);
    }

    @chmod($outDir, 0700);
}

/**
 * Lock down a just-written report file's permissions to 0600 (owner read/write
 * only), since it holds financial data on a shared host.
 *
 * @param string $path The file to secure.
 */
function secureFile(string $path): void
{
    if (!@chmod($path, 0600)) {
        fwrite(STDERR, "Warning: could not chmod \"{$path}\" to 0600: " . lastErrorMessage() . "\n");
    }
}

/**
 * Write a `list<list<scalar>>` of CSV-ready rows to a file via `fputcsv()`,
 * lock its permissions down, and print its absolute path.
 *
 * @param string $path The destination file path.
 * @param list<list<scalar>> $rows Rows in the shape {@see SalesReportCsv::rows()} returns.
 *
 * @return string The absolute path written.
 *
 * @throws never Exits the process directly with code 1 and the reason on
 *         STDERR when the file cannot be opened for writing.
 */
function writeCsvFile(string $path, array $rows): string
{
    $handle = @fopen($path, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "Unable to write \"{$path}\": " . lastErrorMessage() . "\n");
        exit(1);
    }

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    secureFile($path);
    $absolute = realpath($path) ?: $path;
    fwrite(STDOUT, "Wrote {$absolute}\n");

    return $absolute;
}

/**
 * Write a text file (the HTML report), lock its permissions down, and print
 * its absolute path.
 *
 * @param string $path     The destination file path.
 * @param string $contents The full file contents to write.
 *
 * @return string The absolute path written.
 *
 * @throws never Exits the process directly with code 1 and the reason on
 *         STDERR when the file cannot be written.
 */
function writeTextFile(string $path, string $contents): string
{
    if (@file_put_contents($path, $contents) === false) {
        fwrite(STDERR, "Unable to write \"{$path}\": " . lastErrorMessage() . "\n");
        exit(1);
    }

    secureFile($path);
    $absolute = realpath($path) ?: $path;
    fwrite(STDOUT, "Wrote {$absolute}\n");

    return $absolute;
}

/**
 * Write the report's CSV, warnings CSV, and (optionally) HTML files to `$outDir`.
 *
 * @param array<string, mixed> $aggregate  The weekly sales aggregate, as consumed
 *        by {@see SalesReportCsv::rows()}/{@see SalesReportCsv::warningRows()}/{@see SalesReportHtml::render()}.
 * @param list<array{source_id: string, week_start: string, description: string,
 *              amount: float, bucket: string, reason: string}> $auditLines
 *        Quote delivery/other-fee lines for the warnings CSV's audit trail.
 * @param string $outDir The output directory (created if absent by {@see ensureOutDir()}).
 * @param bool $html Whether to also write the HTML report.
 * @param array{doordash_included: bool, doordash_source: ?string} $meta Report metadata,
 *        forwarded into {@see SalesReportHtml::render()}'s `$meta`.
 *
 * @return list<string> The absolute paths written, in the order CSV, warnings CSV, (HTML).
 */
function writeOutputs(array $aggregate, array $auditLines, string $outDir, bool $html, array $meta): array
{
    ensureOutDir($outDir);

    $stamp = (new \DateTimeImmutable('now', WeekBucket::businessZone()))->format('Y-m-d');
    $base  = rtrim($outDir, '/\\') . "/weekly-sales-{$stamp}";

    $paths   = [];
    $paths[] = writeCsvFile("{$base}.csv", SalesReportCsv::rows($aggregate));
    $paths[] = writeCsvFile("{$base}-warnings.csv", SalesReportCsv::warningRows($aggregate, $auditLines));

    if ($html) {
        $paths[] = writeTextFile(
            "{$base}.html",
            SalesReportHtml::render($aggregate, [
                'generated_at'      => new \DateTimeImmutable('now'),
                'doordash_included' => $meta['doordash_included'],
                'doordash_source'   => $meta['doordash_source'],
            ])
        );
    }

    return $paths;
}

/**
 * Entry point: parse arguments, load every channel's sales, aggregate them
 * into the weekly series, print the terminal summary, and (unless
 * `--dry-run`) write the report files.
 *
 * @param list<string> $argv The raw `$argv` superglobal, including the script name.
 *
 * @return int The process exit code: 0 clean, 1 produced with warnings, 2 usage/config error.
 */
function main(array $argv): int
{
    $args = array_slice($argv, 1);

    try {
        $options = parseArgs($args);
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n\n" . usageText());

        return 2;
    }

    if ($options['help']) {
        fwrite(STDOUT, usageText());

        return 0;
    }

    if ($options['since'] !== null && !isValidIsoDate($options['since'])) {
        fwrite(STDERR, "Invalid --since date \"{$options['since']}\": expected YYYY-MM-DD.\n");

        return 2;
    }

    $outDir = resolveOutDir($options['out']);
    assertOutsideProject($outDir, dirname(__DIR__));

    $doordash = $options['doordash'] !== null ? loadDoorDashRows($options['doordash']) : null;

    $pdo = Database::ro();

    // Numeric offset, not a named zone: named zones require the
    // mysql.time_zone tables, which are not populated on this shared host, so
    // CONVERT_TZ with a name silently returns NULL. This makes every
    // TIMESTAMP read back as true UTC, which WeekBucket requires — MySQL's
    // SYSTEM zone here resolves to US Eastern while the app runs
    // America/Chicago, a one-hour skew large enough to move a late-evening
    // sale into the wrong week.
    $pdo->exec("SET SESSION time_zone = '+00:00'");

    $shop              = loadShopRows($pdo);
    $quotes            = loadQuoteRows($pdo);
    $exceptionWarnings = loadExceptions($pdo);

    $channelRows    = [...$shop['rows'], ...$quotes['rows']];
    $globalWarnings = [...$shop['warnings'], ...$exceptionWarnings];

    if ($doordash !== null) {
        $channelRows    = [...$channelRows, ...$doordash['rows']];
        $globalWarnings = [...$globalWarnings, ...$doordash['warnings']];
    }

    $channelRows = filterSince($channelRows, $options['since']);

    $currentWeekStart = WeekBucket::fromUtc(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))['week_start'];
    $aggregate         = WeeklySalesAggregator::aggregate($channelRows, $currentWeekStart);
    $aggregate         = appendGlobalWarnings($aggregate, $globalWarnings);

    $meta = [
        'doordash_included'  => $doordash !== null,
        'doordash_source'    => $doordash['source'] ?? null,
        'doordash_row_count' => $doordash !== null ? count($doordash['rows']) : 0,
        'unmapped_headers'   => $doordash['unmapped_headers'] ?? [],
    ];

    renderTerminalSummary($aggregate, $meta);

    $hasWarnings = $aggregate['warnings'] !== [];

    if ($options['dryRun']) {
        fwrite(STDOUT, "\nDry run: no files written.\n");

        return $hasWarnings ? 1 : 0;
    }

    writeOutputs($aggregate, $quotes['auditLines'], $outDir, $options['html'], $meta);

    return $hasWarnings ? 1 : 0;
}

exit(main($argv));
