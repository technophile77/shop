<?php

declare(strict_types=1);

/**
 * Reconciles Stripe charges (and, where needed, Checkout Sessions) against
 * this codebase's own record of what it believes it collected — the
 * `quotes` and `orders` tables — so a human can answer "which Stripe
 * payments correspond to which quotes/orders, and what doesn't line up"
 * without trusting either side blindly.
 *
 * All the matching logic lives in the DB-free, network-free
 * {@see \App\Support\StripeReconciler}; this script's job is only to fetch
 * Stripe's side ({@see \App\Services\StripeService::listCharges()} /
 * {@see \App\Services\StripeService::listCheckoutSessions()}), build the
 * local side from the database, and render the result.
 *
 * The database connection is pinned to `SET SESSION time_zone = '+00:00'`
 * (a numeric offset, not a named zone) before any read, for the same reason
 * `bin/sales-report.php` does — named zones require the `mysql.time_zone`
 * tables, which are not populated on this shared host, so a `TIMESTAMP`
 * would read back in the server's SYSTEM zone (US Eastern) rather than true
 * UTC, which {@see \App\Support\StripeReconciler}'s date-window matching
 * requires.
 *
 * Only the read-only database connection is ever used
 * ({@see \App\Core\Database::ro()}); this script never writes to the
 * database, and it never calls any Stripe endpoint that could mutate data.
 *
 * Usage:
 *   php bin/stripe-reconcile.php [--since=YYYY-MM-DD] [--out=DIR] [--csv] [--dry-run|-n] [--help|-h]
 *
 * Exit codes:
 *   0 - clean run, no warnings.
 *   1 - the reconciliation ran but raised at least one warning (a heuristic
 *       match, refund, dispute, or unexplained charge/local record).
 *   2 - a usage or configuration error; nothing was read from Stripe or the database.
 *
 * @see \App\Support\StripeReconciler          The DB-free, SDK-free matching engine.
 * @see \App\Services\StripeService             Stripe API access.
 * @see \App\Support\ReportFileOutput           Shared output-directory/CSV-writing helpers.
 * @see docs/stripe-reconciliation.md           Full write-up, including known findings.
 */

use App\Core\Database;
use App\Services\StripeService;
use App\Support\ReportFileOutput;
use App\Support\StripeReconciler;

// Refuse to run under a web SAPI, before loading anything. The site's docroot
// is the project root and .htaccess serves any existing file directly, so this
// script is reachable at https://…/bin/stripe-reconcile.php. It reads every
// Stripe charge and every quote/order the business has ever taken payment on,
// so it must never be executable by an anonymous HTTP request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

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
    Usage: php bin/stripe-reconcile.php [--since=YYYY-MM-DD] [--out=DIR] [--csv] [--dry-run|-n] [--help|-h]

      --since=YYYY-MM-DD    Only include Stripe charges/sessions and local quotes/orders
                            recognized on or after this UTC date.
      --out=DIR             Directory to write the CSV into (with --csv). Defaults to
                            SALES_REPORT_DIR from the environment, or
                            \$HOME/private/flowers-sales. Refused if it resolves to
                            inside this project (the project root is the site's
                            public docroot).
      --csv                 Also write stripe-reconciliation-YYYY-MM-DD.csv to --out.
      --dry-run, -n         Print the terminal summary only; write no files.
      --help, -h            Show this message and exit.

    Exit codes: 0 clean run, 1 reconciliation produced with warnings, 2 usage/configuration error.

    TXT;
}

/**
 * Parse the script's CLI arguments into a typed options array.
 *
 * @param list<string> $args The argument vector, without the script name (`$argv[1..]`).
 *
 * @return array{since: ?string, out: ?string, csv: bool, dryRun: bool, help: bool}
 *         The parsed options; string options are null when not supplied and
 *         not yet validated or path-expanded.
 *
 * @throws \InvalidArgumentException When an argument is not one of the
 *         recognised flags, naming the offending argument.
 *
 * @example
 *   parseArgs(['--since=2026-07-01', '--csv']);
 *   // ['since' => '2026-07-01', 'out' => null, 'csv' => true, 'dryRun' => false, 'help' => false]
 */
function parseArgs(array $args): array
{
    $options = [
        'since'  => null,
        'out'    => null,
        'csv'    => false,
        'dryRun' => false,
        'help'   => false,
    ];

    foreach ($args as $arg) {
        match (true) {
            $arg === '--help' || $arg === '-h'   => $options['help'] = true,
            $arg === '--csv'                      => $options['csv'] = true,
            $arg === '--dry-run' || $arg === '-n' => $options['dryRun'] = true,
            str_starts_with($arg, '--since=')     => $options['since'] = substr($arg, strlen('--since=')),
            str_starts_with($arg, '--out=')       => $options['out'] = substr($arg, strlen('--out=')),
            default => throw new \InvalidArgumentException("Unknown option: {$arg}"),
        };
    }

    return $options;
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
 */
function isValidIsoDate(string $value): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

/**
 * Compute the Unix timestamp of UTC midnight for a `--since` date, for use
 * as Stripe's `created[gte]` filter and as the lower bound on local records.
 *
 * @param string $since A validated `YYYY-MM-DD` date string.
 *
 * @return int The Unix timestamp of `$since` at `00:00:00 UTC`.
 *
 * @example
 *   sinceGteUnixUtc('2026-07-20'); // 1784592000
 */
function sinceGteUnixUtc(string $since): int
{
    return (new \DateTimeImmutable("{$since} 00:00:00", new \DateTimeZone('UTC')))->getTimestamp();
}

/**
 * Build one local-payment record from a raw `quotes` row (already joined to `customers`).
 *
 * @param array{id: int|string, subtotal: float|string, tax_amount: float|string,
 *              delivery_fee: float|string, status: string, deposit_confirmed_at: ?string,
 *              stripe_checkout_session_id: ?string, stripe_payment_intent_id: ?string,
 *              customer_name: ?string} $quote One row from the quotes query.
 *
 * @return array{kind: string, id: int, label: string, expected: float, status: string,
 *              recognized_at: ?string, session_id: string, payment_intent_id: string,
 *              customer_name: string}
 *         One local-payment record, as {@see \App\Support\StripeReconciler::reconcile()} expects.
 *
 * @example
 *   buildQuoteLocal(['id' => 17, 'subtotal' => '110.00', 'tax_amount' => '8.18',
 *       'delivery_fee' => '0.00', 'status' => 'deposit_confirmed',
 *       'deposit_confirmed_at' => '2026-07-20 22:51:20', 'stripe_checkout_session_id' => null,
 *       'stripe_payment_intent_id' => 'pi_17', 'customer_name' => 'Jane Doe']);
 *   // ['kind' => 'quote', 'id' => 17, 'label' => 'quote 17', 'expected' => 118.18, ...]
 */
function buildQuoteLocal(array $quote): array
{
    $expected = round(
        (float) $quote['subtotal'] + (float) $quote['tax_amount'] + (float) $quote['delivery_fee'],
        2
    );

    return [
        'kind'               => 'quote',
        'id'                 => (int) $quote['id'],
        'label'              => "quote {$quote['id']}",
        'expected'           => $expected,
        'status'             => $quote['status'],
        'recognized_at'      => $quote['deposit_confirmed_at'],
        'session_id'         => (string) ($quote['stripe_checkout_session_id'] ?? ''),
        'payment_intent_id'  => (string) ($quote['stripe_payment_intent_id'] ?? ''),
        'customer_name'      => (string) ($quote['customer_name'] ?? ''),
    ];
}

/**
 * Build one local-payment record from a raw `orders` row (already joined to `customers`).
 *
 * @param array{id: int|string, total: float|string, payment_status: string,
 *              recognized_at: ?string, stripe_checkout_session_id: ?string,
 *              stripe_payment_intent_id: ?string, customer_name: ?string} $order
 *        One row from the orders query, `recognized_at` already computed as
 *        `COALESCE(paid_at, created_at)` in SQL.
 *
 * @return array{kind: string, id: int, label: string, expected: float, status: string,
 *              recognized_at: ?string, session_id: string, payment_intent_id: string,
 *              customer_name: string}
 *         One local-payment record, as {@see \App\Support\StripeReconciler::reconcile()} expects.
 *
 * @example
 *   buildOrderLocal(['id' => 13, 'total' => '107.67', 'payment_status' => 'paid',
 *       'recognized_at' => '2026-07-24 00:13:58', 'stripe_checkout_session_id' => 'cs_13',
 *       'stripe_payment_intent_id' => 'pi_13', 'customer_name' => 'Jane Doe']);
 *   // ['kind' => 'order', 'id' => 13, 'label' => 'order 13', 'expected' => 107.67, ...]
 */
function buildOrderLocal(array $order): array
{
    return [
        'kind'               => 'order',
        'id'                 => (int) $order['id'],
        'label'              => "order {$order['id']}",
        'expected'           => round((float) $order['total'], 2),
        'status'             => $order['payment_status'],
        'recognized_at'      => $order['recognized_at'],
        'session_id'         => (string) ($order['stripe_checkout_session_id'] ?? ''),
        'payment_intent_id'  => (string) ($order['stripe_payment_intent_id'] ?? ''),
        'customer_name'      => (string) ($order['customer_name'] ?? ''),
    ];
}

/**
 * Load every local-payment record (deposit-confirmed/completed quotes, plus
 * paid shop orders) that this codebase believes was paid.
 *
 * @param \PDO $pdo A connection pinned to `SET SESSION time_zone = '+00:00'`.
 * @param ?int $sinceGte Unix timestamp; when non-null, only records recognized
 *        on or after this instant are included.
 *
 * @return list<array{kind: string, id: int, label: string, expected: float, status: string,
 *              recognized_at: ?string, session_id: string, payment_intent_id: string,
 *              customer_name: string}>
 *         Every local-payment record, quotes and orders combined.
 *
 * @see buildQuoteLocal()
 * @see buildOrderLocal()
 */
function loadLocalPayments(\PDO $pdo, ?int $sinceGte): array
{
    $quoteSql = "SELECT q.id, q.status, q.subtotal, q.tax_amount, q.delivery_fee,
                        q.deposit_confirmed_at, q.stripe_checkout_session_id,
                        q.stripe_payment_intent_id, c.name AS customer_name
                   FROM quotes q
                   LEFT JOIN customers c ON c.id = q.customer_id
                  WHERE q.status IN ('deposit_confirmed', 'completed')";
    if ($sinceGte !== null) {
        $quoteSql .= ' AND q.deposit_confirmed_at >= :since';
    }
    $quoteSql .= ' ORDER BY q.deposit_confirmed_at';

    $quoteStmt = $pdo->prepare($quoteSql);
    if ($sinceGte !== null) {
        $quoteStmt->bindValue(':since', gmdate('Y-m-d H:i:s', $sinceGte));
    }
    $quoteStmt->execute();

    $orderSql = "SELECT o.id, o.payment_status, o.total, o.stripe_checkout_session_id,
                        o.stripe_payment_intent_id, c.name AS customer_name,
                        COALESCE(o.paid_at, o.created_at) AS recognized_at
                   FROM orders o
                   LEFT JOIN customers c ON c.id = o.customer_id
                  WHERE o.payment_status = 'paid' AND o.items_json IS NOT NULL";
    if ($sinceGte !== null) {
        $orderSql .= ' HAVING recognized_at >= :since';
    }
    $orderSql .= ' ORDER BY recognized_at';

    $orderStmt = $pdo->prepare($orderSql);
    if ($sinceGte !== null) {
        $orderStmt->bindValue(':since', gmdate('Y-m-d H:i:s', $sinceGte));
    }
    $orderStmt->execute();

    $locals = array_map(buildQuoteLocal(...), $quoteStmt->fetchAll());
    $locals = [...$locals, ...array_map(buildOrderLocal(...), $orderStmt->fetchAll())];

    return $locals;
}

/**
 * Format a dollar amount for the fixed-width terminal table (no currency
 * symbol, comma thousands separator, right-aligned by the caller's field width).
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
 * Render the `MATCHED (n)` table: one row per matched pair, showing both
 * sides' amounts, the signed delta, which rule matched them, and the charge id.
 *
 * @param list<array{local: array{label: string, customer_name: string, expected: float},
 *              charge: array{id: string, amount: float}, method: string, delta: float}> $matched
 *
 * @return string The complete table, header through last row, newline-terminated per line.
 */
function renderMatchedTable(array $matched): string
{
    $lines = sprintf("MATCHED (%d)\n", count($matched));

    if ($matched === []) {
        return $lines . "  (none)\n";
    }

    $lines .= sprintf(
        "%-14s %-24s %10s %10s %8s %-16s %s\n",
        'Local', 'Customer', 'Expected', 'Stripe', 'Delta', 'Method', 'Charge'
    );
    $lines .= str_repeat('-', 100) . "\n";

    foreach ($matched as $pair) {
        $lines .= sprintf(
            "%-14s %-24s %10s %10s %8s %-16s %s\n",
            $pair['local']['label'],
            $pair['local']['customer_name'] !== '' ? $pair['local']['customer_name'] : '(unknown)',
            formatMoneyColumn($pair['local']['expected']),
            formatMoneyColumn($pair['charge']['amount']),
            formatMoneyColumn($pair['delta']),
            $pair['method'],
            $pair['charge']['id']
        );
    }

    return $lines;
}

/**
 * Render the `UNMATCHED STRIPE CHARGES (n)` section: one line per charge
 * Stripe settled with no explaining local quote/order.
 *
 * @param list<array{charge: array{id: string, created_utc: string, amount: float}, note: string}> $unmatchedStripe
 *
 * @return string The section, newline-terminated per line.
 */
function renderUnmatchedStripeSection(array $unmatchedStripe): string
{
    $lines = sprintf("UNMATCHED STRIPE CHARGES (%d)\n", count($unmatchedStripe));

    if ($unmatchedStripe === []) {
        return $lines . "  (none)\n";
    }

    foreach ($unmatchedStripe as $entry) {
        $lines .= sprintf(
            "  - %s: $%.2f on %s\n",
            $entry['charge']['id'],
            $entry['charge']['amount'],
            $entry['charge']['created_utc']
        );
    }

    return $lines;
}

/**
 * Render the `UNMATCHED LOCAL RECORDS (n)` section: one line per quote/order
 * this codebase believes was paid, with no explaining Stripe charge.
 *
 * @param list<array{local: array{label: string, expected: float, recognized_at: ?string}, note: string}> $unmatchedLocal
 *
 * @return string The section, newline-terminated per line.
 */
function renderUnmatchedLocalSection(array $unmatchedLocal): string
{
    $lines = sprintf("UNMATCHED LOCAL RECORDS (%d)\n", count($unmatchedLocal));

    if ($unmatchedLocal === []) {
        return $lines . "  (none)\n";
    }

    foreach ($unmatchedLocal as $entry) {
        $lines .= sprintf(
            "  - %s: expected $%.2f, recognized %s\n",
            $entry['local']['label'],
            $entry['local']['expected'],
            $entry['local']['recognized_at'] ?? '(none)'
        );
    }

    return $lines;
}

/**
 * Render the `AMOUNT MISMATCHES (n)` section: matched pairs whose amounts
 * disagree by more than half a cent.
 *
 * @param list<array{local: array{label: string}, charge: array{id: string, amount: float},
 *              delta: float, method: string}> $amountMismatches
 *
 * @return string The section, newline-terminated per line.
 */
function renderAmountMismatchesSection(array $amountMismatches): string
{
    $lines = sprintf("AMOUNT MISMATCHES (%d)\n", count($amountMismatches));

    if ($amountMismatches === []) {
        return $lines . "  (none)\n";
    }

    foreach ($amountMismatches as $entry) {
        $lines .= sprintf(
            "  - %s vs %s: delta $%.2f (matched via %s)\n",
            $entry['local']['label'],
            $entry['charge']['id'],
            $entry['delta'],
            $entry['method']
        );
    }

    return $lines;
}

/**
 * Render the `REFUNDS (n)` section: every charge with a nonzero `amount_refunded`.
 *
 * @param list<array{charge: array{id: string}, amount_refunded: float}> $refunds
 *
 * @return string The section, newline-terminated per line.
 */
function renderRefundsSection(array $refunds): string
{
    $lines = sprintf("REFUNDS (%d)\n", count($refunds));

    if ($refunds === []) {
        return $lines . "  (none)\n";
    }

    foreach ($refunds as $entry) {
        $lines .= sprintf("  - %s: $%.2f refunded\n", $entry['charge']['id'], $entry['amount_refunded']);
    }

    return $lines;
}

/**
 * Render the `DISPUTES (n)` section: every charge Stripe flags as disputed.
 *
 * @param list<array{charge: array{id: string}}> $disputes
 *
 * @return string The section, newline-terminated per line.
 */
function renderDisputesSection(array $disputes): string
{
    $lines = sprintf("DISPUTES (%d)\n", count($disputes));

    if ($disputes === []) {
        return $lines . "  (none)\n";
    }

    foreach ($disputes as $entry) {
        $lines .= sprintf("  - %s\n", $entry['charge']['id']);
    }

    return $lines;
}

/**
 * Render the totals footer: what Stripe settled, what the local records
 * expected, and the signed difference.
 *
 * @param array{stripe_settled: float, local_expected: float, delta: float} $totals
 *
 * @return string A multi-line summary block, newline-terminated per line.
 */
function renderTotalsFooter(array $totals): string
{
    return sprintf(
        "TOTALS\n" .
        "  Stripe settled (net of refunds): \$%s\n" .
        "  Local expected:                  \$%s\n" .
        "  Delta (settled - expected):      \$%s\n",
        number_format($totals['stripe_settled'], 2),
        number_format($totals['local_expected'], 2),
        number_format($totals['delta'], 2)
    );
}

/**
 * Render the `WARNINGS (n)` block, listing every warning in full — never
 * truncated, and always printed even when empty.
 *
 * @param list<string> $warnings
 *
 * @return string The warnings block, newline-terminated per line.
 */
function renderWarningsBlock(array $warnings): string
{
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
 * Print the complete terminal summary: matched, unmatched, mismatch, refund
 * and dispute sections, the totals footer, then warnings.
 *
 * @param array{matched: list<array>, unmatched_stripe: list<array>, unmatched_local: list<array>,
 *              amount_mismatches: list<array>, refunds: list<array>, disputes: list<array>,
 *              totals: array{stripe_settled: float, local_expected: float, delta: float},
 *              warnings: list<string>} $result The full {@see \App\Support\StripeReconciler::reconcile()} result.
 */
function renderTerminalSummary(array $result): void
{
    fwrite(STDOUT, "\n" . renderMatchedTable($result['matched']));
    fwrite(STDOUT, "\n" . renderUnmatchedStripeSection($result['unmatched_stripe']));
    fwrite(STDOUT, "\n" . renderUnmatchedLocalSection($result['unmatched_local']));
    fwrite(STDOUT, "\n" . renderAmountMismatchesSection($result['amount_mismatches']));
    fwrite(STDOUT, "\n" . renderRefundsSection($result['refunds']));
    fwrite(STDOUT, "\n" . renderDisputesSection($result['disputes']));
    fwrite(STDOUT, "\n" . renderTotalsFooter($result['totals']));
    fwrite(STDOUT, "\n" . renderWarningsBlock($result['warnings']));
}

/**
 * Build the CSV rows for `--csv`: a header row, one row per matched pair,
 * then one row per unmatched Stripe charge and unmatched local record.
 *
 * @param array{matched: list<array{local: array{kind: string, id: int, label: string,
 *              customer_name: string, expected: float}, charge: array{id: string},
 *              method: string, delta: float}>,
 *         unmatched_stripe: list<array{charge: array{id: string, created_utc: string, amount: float}}>,
 *         unmatched_local: list<array{local: array{kind: string, id: int, label: string,
 *              expected: float, recognized_at: ?string}}>} $result
 *        The full {@see \App\Support\StripeReconciler::reconcile()} result.
 *
 * @return list<list<scalar>> CSV-ready rows, header first.
 */
function buildCsvRows(array $result): array
{
    $rows = [['kind', 'local', 'customer', 'expected', 'stripe_amount', 'delta', 'method', 'charge_id', 'note']];

    foreach ($result['matched'] as $pair) {
        $rows[] = [
            $pair['local']['kind'],
            $pair['local']['label'],
            $pair['local']['customer_name'],
            $pair['local']['expected'],
            $pair['charge']['amount'],
            $pair['delta'],
            $pair['method'],
            $pair['charge']['id'],
            '',
        ];
    }

    foreach ($result['unmatched_stripe'] as $entry) {
        $rows[] = ['', '', '', '', $entry['charge']['amount'], '', '', $entry['charge']['id'], $entry['note']];
    }

    foreach ($result['unmatched_local'] as $entry) {
        $rows[] = [
            $entry['local']['kind'],
            $entry['local']['label'],
            '',
            $entry['local']['expected'],
            '',
            '',
            '',
            '',
            $entry['note'],
        ];
    }

    return $rows;
}

/**
 * Entry point: parse arguments, fetch Stripe's side, build the local side
 * from the database, reconcile, print the terminal summary, and (with
 * `--csv`, unless `--dry-run`) write the CSV file.
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

    if (!StripeService::isConfigured()) {
        fwrite(STDERR, "STRIPE_SECRET is not configured; cannot reconcile against Stripe.\n");

        return 2;
    }

    $outDir = ReportFileOutput::resolveOutDir($options['out'], 'SALES_REPORT_DIR', 'private/flowers-sales');
    ReportFileOutput::assertOutsideProject($outDir, dirname(__DIR__));

    $sinceGte = $options['since'] !== null ? sinceGteUnixUtc($options['since']) : null;

    $charges  = StripeService::listCharges($sinceGte ?? 0);
    $sessions = StripeService::listCheckoutSessions($sinceGte ?? 0);

    $pdo = Database::ro();

    // Numeric offset, not a named zone: named zones require the
    // mysql.time_zone tables, which are not populated on this shared host, so
    // CONVERT_TZ with a name silently returns NULL. This makes every
    // TIMESTAMP read back as true UTC, which StripeReconciler's date-window
    // matching requires — MySQL's SYSTEM zone here resolves to US Eastern.
    $pdo->exec("SET SESSION time_zone = '+00:00'");

    $localPayments = loadLocalPayments($pdo, $sinceGte);

    $result = StripeReconciler::reconcile($charges, $sessions, $localPayments);

    renderTerminalSummary($result);

    $hasWarnings = $result['warnings'] !== [];

    if ($options['dryRun']) {
        fwrite(STDOUT, "\nDry run: no files written.\n");

        return $hasWarnings ? 1 : 0;
    }

    if ($options['csv']) {
        ReportFileOutput::ensureOutDir($outDir);
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        ReportFileOutput::writeCsvFile(
            rtrim($outDir, '/\\') . "/stripe-reconciliation-{$stamp}.csv",
            buildCsvRows($result)
        );
    }

    return $hasWarnings ? 1 : 0;
}

exit(main($argv));
