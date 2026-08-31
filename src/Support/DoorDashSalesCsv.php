<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure parser that turns an already-read DoorDash Merchant Portal financial
 * CSV export into ChannelRow entries for the weekly sales report.
 *
 * DoorDash sales never touch this codebase's database — there is no DoorDash
 * API integration, only a Storefront link-out — so the owner manually
 * downloads the CSV from the Merchant Portal (Reports → Create report →
 * Financial → Transaction details → CSV) and hands it to the report script.
 * DoorDash's column headers are not publicly documented and change over time
 * without notice (they restructured the financial report columns in April
 * 2025, for one). A naive importer would silently read a renamed column as
 * missing and treat it as zero, quietly understating revenue — the single
 * worst failure mode for a financial report. This class therefore **fails
 * loudly**: {@see self::parse()} throws when a required column can't be
 * found, with a message that names every canonical field still missing and
 * every header actually present in the file, so a human can fix
 * `config/doordash-report-map.php` in one pass.
 *
 * The recognized sale for DoorDash is the **net payout**: commission and
 * marketing fees are broken out into their own `fees` figure — the amount
 * {@see \App\Support\SalesChannel::recognize()} subtracts — rather than being
 * folded silently into merchandise. That figure is normally positive, but it
 * is signed rather than a magnitude: a marketing credit or a payout
 * `Adjustment` in the merchant's favour makes it negative.
 *
 * This class takes already-parsed rows, never a file path — no filesystem
 * access, no database access, no side effects. The caller is responsible for
 * `fopen`/`fgetcsv`-ing the export and `require`-ing the map file from
 * {@see self::defaultMapPath()}.
 *
 * @see \App\Support\SalesChannel     Defines the accounting identity this class checks against.
 * @see \App\Support\WeekBucket       Supplies the business timezone for date-only timestamps.
 * @see \config\doordash-report-map.php The header-spelling map this class is driven by.
 */
final class DoorDashSalesCsv
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Accepted `Y-m-d`-family and `m/d/Y`-family date-only formats, tried in
     * order. A match here has no time-of-day information, so it is anchored
     * at noon business time by {@see self::parseTimestamp()}.
     *
     * @var list<string>
     */
    private const DATE_ONLY_FORMATS = ['Y-m-d', 'm/d/Y'];

    /**
     * Accepted date-and-time formats, tried in order, after the date-only
     * formats have failed to match.
     *
     * @var list<string>
     */
    private const DATE_TIME_FORMATS = ['Y-m-d H:i:s', 'm/d/Y H:i'];

    /**
     * Path to the header-spelling map this class is driven by.
     *
     * This is the one place a filesystem path is named in this class; the
     * caller does the `require` (this class takes no filesystem action of
     * its own, to stay unit-testable with in-memory rows).
     *
     * @return string Absolute path to `config/doordash-report-map.php`.
     *
     * @example
     *   $map = require DoorDashSalesCsv::defaultMapPath();
     *   DoorDashSalesCsv::parse($rows, $map);
     */
    public static function defaultMapPath(): string
    {
        return dirname(__DIR__, 2) . '/config/doordash-report-map.php';
    }

    /**
     * Parse a DoorDash Merchant Portal CSV export into ChannelRow entries.
     *
     * @param list<list<string>> $rows Rows exactly as successive `fgetcsv()`
     *        calls would return them, with the header as the first row.
     * @param array{fields: array<string, list<string>>, fee_columns: list<string>,
     *              ignored_columns: list<string>, optional_fields: list<string>} $map
     *        The header-spelling map, e.g. `require self::defaultMapPath()`.
     *
     * @return array{rows: list<array{channel: string, source_id: string,
     *              recognized_at: \DateTimeImmutable, merchandise: float,
     *              delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float,
     *              warnings: list<string>}>,
     *              warnings: list<string>, unmapped_headers: list<string>}
     *         `rows` are ChannelRow entries with `channel` always
     *         {@see \App\Support\SalesChannel::DOORDASH}; `warnings` are
     *         CSV-structural issues (a skipped malformed row) rather than
     *         per-order issues, which instead live on the row's own
     *         `warnings` entry; `unmapped_headers` lists columns present in
     *         the file that matched nothing and aren't in `ignored_columns`.
     *
     * @throws \RuntimeException When a required field in `$map['fields']`
     *         (any field not also listed in `$map['optional_fields']`) has no
     *         matching header in the file. The message names every missing
     *         field with the spellings that were tried, every header found
     *         in the file, and `config/doordash-report-map.php` as the fix.
     *
     * @example
     *   $map  = require DoorDashSalesCsv::defaultMapPath();
     *   $rows = [
     *       ['Order ID', 'Order date', 'Subtotal', 'Delivery fee', 'Tax', 'Commission', 'Net total'],
     *       ['D-1', '2026-07-20', '90.00', '10.00', '7.67', '(13.50)', '94.17'],
     *   ];
     *   DoorDashSalesCsv::parse($rows, $map);
     *   // ['rows' => [[...'merchandise' => 90.00, 'fees' => 13.50, 'recognized_sales' => 94.17...]],
     *   //  'warnings' => [], 'unmapped_headers' => []]
     */
    public static function parse(array $rows, array $map): array
    {
        if ($rows === []) {
            return ['rows' => [], 'warnings' => [], 'unmapped_headers' => []];
        }

        $header  = array_shift($rows);
        $columns = self::resolveColumns($header, $map);

        $parsedRows = [];
        $warnings   = [];

        foreach ($rows as $offset => $row) {
            // +1 because $offset is 0-based within the data rows, +1 because
            // the header itself counts as line 1.
            $lineNumber = $offset + 2;

            if (self::isBlankLine($row)) {
                continue;
            }

            if (count($row) !== count($header)) {
                $warnings[] = sprintf(
                    'Line %d: skipped — expected %d column(s), found %d.',
                    $lineNumber,
                    count($header),
                    count($row)
                );
                continue;
            }

            $parsedRows[] = self::buildRow($row, $columns);
        }

        return [
            'rows'             => $parsedRows,
            'warnings'         => $warnings,
            'unmapped_headers' => $columns['unmapped'],
        ];
    }

    /**
     * Resolve every canonical field and fee column to a column index in the
     * header, and collect any headers that matched nothing.
     *
     * @param list<string> $header Raw header row from the CSV.
     * @param array{fields: array<string, list<string>>, fee_columns: list<string>,
     *              ignored_columns: list<string>, optional_fields: list<string>} $map
     *        The header-spelling map.
     *
     * @return array{fields: array<string, int>, fees: array<string, int>, unmapped: list<string>}
     *         `fields` and `fees` map a canonical/fee name to its column
     *         index; `unmapped` lists raw header text with no match.
     *
     * @throws \RuntimeException See {@see self::parse()}.
     */
    private static function resolveColumns(array $header, array $map): array
    {
        $normalizedHeader = array_map(self::normalizeHeader(...), $header);

        $fieldIndex = [];
        $missing    = [];

        foreach ($map['fields'] as $field => $spellings) {
            $index = self::findColumn($normalizedHeader, $spellings);

            if ($index !== null) {
                $fieldIndex[$field] = $index;
            } elseif (!in_array($field, $map['optional_fields'], true)) {
                $missing[$field] = $spellings;
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException(self::missingFieldsMessage($missing, $header));
        }

        $feeIndex = [];
        foreach ($map['fee_columns'] as $spelling) {
            $index = self::findColumn($normalizedHeader, [$spelling]);

            if ($index !== null) {
                $feeIndex[$spelling] = $index;
            }
        }

        $matched = [...array_values($fieldIndex), ...array_values($feeIndex)];
        $ignored = array_map(self::normalizeHeader(...), $map['ignored_columns']);

        $unmapped = [];
        foreach ($header as $index => $rawHeader) {
            if (in_array($index, $matched, true)) {
                continue;
            }
            if (in_array($normalizedHeader[$index], $ignored, true)) {
                continue;
            }
            $unmapped[] = $rawHeader;
        }

        return ['fields' => $fieldIndex, 'fees' => $feeIndex, 'unmapped' => $unmapped];
    }

    /**
     * Find the first accepted spelling present in the header, ignoring case
     * and whitespace differences.
     *
     * @param list<string> $normalizedHeader Header cells already run through
     *        {@see self::normalizeHeader()}.
     * @param list<string> $spellings Accepted header spellings, tried in order.
     *
     * @return int|null The matching column index, or null when none of the
     *         spellings appear in the header.
     */
    private static function findColumn(array $normalizedHeader, array $spellings): ?int
    {
        foreach ($spellings as $spelling) {
            $index = array_search(self::normalizeHeader($spelling), $normalizedHeader, true);

            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Normalise a header cell for comparison: collapse repeated inner
     * whitespace, trim the ends, and lowercase — so `" Net  Total "` matches
     * the configured spelling `'Net total'`.
     *
     * @param string $value Raw header text.
     *
     * @return string Normalised text used only for comparison, never displayed.
     */
    private static function normalizeHeader(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    /**
     * Build the actionable exception message for missing required fields.
     *
     * @param array<string, list<string>> $missing Canonical field name =>
     *        spellings that were tried and not found.
     * @param list<string> $header Every header actually present in the file.
     *
     * @return string A multi-line message naming each missing field, the
     *         spellings tried, the headers found, and where to fix it.
     */
    private static function missingFieldsMessage(array $missing, array $header): string
    {
        $lines = ['DoorDash CSV is missing required column(s):'];

        foreach ($missing as $field => $spellings) {
            $lines[] = sprintf('  - %s (tried: %s)', $field, implode(', ', $spellings));
        }

        $lines[] = sprintf('Headers found in file: %s', implode(', ', $header));
        $lines[] = 'Add the actual header spelling to config/doordash-report-map.php to fix this.';

        return implode("\n", $lines);
    }

    /**
     * Detect the blank-line shape `fgetcsv()` returns for an empty line in
     * the file (a single-element row whose only cell is null or empty).
     *
     * @param list<string> $row A row as returned by `fgetcsv()`.
     *
     * @return bool True when the row represents a blank line to skip silently.
     */
    private static function isBlankLine(array $row): bool
    {
        return count($row) === 1 && trim((string) ($row[0] ?? '')) === '';
    }

    /**
     * Build one ChannelRow from a validated data row (already checked for
     * blank-ness and correct cell count).
     *
     * @param list<string> $row Data row, same length as the header.
     * @param array{fields: array<string, int>, fees: array<string, int>, unmapped: list<string>} $columns
     *        Column mapping from {@see self::resolveColumns()}.
     *
     * @return array{channel: string, source_id: string, recognized_at: \DateTimeImmutable,
     *              merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float, warnings: list<string>}
     *         One ChannelRow for the DoorDash channel.
     *
     * @throws \RuntimeException When a money or date cell in this row is
     *         genuinely unparseable; see {@see self::parseMoney()} and
     *         {@see self::parseTimestamp()}.
     */
    private static function buildRow(array $row, array $columns): array
    {
        $cell = static fn (string $field): string => $row[$columns['fields'][$field]] ?? '';

        $orderId     = trim($cell('order_id'));
        $merchandise = round(self::parseMoney($cell('merchandise'), 'merchandise'), 2);
        $delivery    = isset($columns['fields']['delivery'])
            ? round(self::parseMoney($cell('delivery'), 'delivery'), 2)
            : 0.00;
        $tax = isset($columns['fields']['tax'])
            ? round(self::parseMoney($cell('tax'), 'tax'), 2)
            : 0.00;
        $recognizedSales = round(self::parseMoney($cell('net_payout'), 'net_payout'), 2);
        $recognizedAt    = self::parseTimestamp($cell('occurred_at'), 'occurred_at');

        // Fee columns are negated, not abs()'d. DoorDash signs these columns
        // from the merchant's point of view: money withheld from the payout is
        // negative (commission, marketing fees), and money added to it is
        // positive (a marketing credit, or an `Adjustment` transaction paying
        // the merchant back). The ChannelRow contract stores `fees` as the
        // amount SalesChannel::recognize() subtracts, so negating preserves
        // both directions — a withheld fee becomes a positive `fees` figure,
        // and a credit becomes a negative one that adds to recognized sales.
        // Taking the magnitude instead would turn every credit into a second
        // deduction and break the identity by twice the credit.
        $fees = 0.00;
        foreach ($columns['fees'] as $name => $index) {
            $fees -= self::parseMoney($row[$index] ?? '', $name);
        }
        $fees = round($fees, 2);

        $warnings = [];

        // DoorDash's net payout is authoritative for cash actually received,
        // so it is never overwritten with our own computed figure. A row
        // that doesn't close means the column mapping is incomplete — almost
        // always a fee column DoorDash added that isn't yet listed in
        // fee_columns — which is exactly what the operator needs told.
        if (!SalesChannel::closes($merchandise, $delivery, $tax, $fees, $recognizedSales)) {
            $expected   = SalesChannel::recognize($merchandise, $delivery, $tax, $fees);
            $warnings[] = sprintf(
                '%s: DoorDash net payout ($%.2f) does not match merchandise+delivery+tax-fees ($%.2f);'
                    . ' check for a missing fee column in config/doordash-report-map.php.',
                $orderId,
                $recognizedSales,
                $expected
            );
        }

        return [
            'channel'           => SalesChannel::DOORDASH,
            'source_id'         => $orderId,
            'recognized_at'     => $recognizedAt,
            'merchandise'       => $merchandise,
            'delivery'          => $delivery,
            'tax'               => $tax,
            'fees'              => $fees,
            'recognized_sales'  => $recognizedSales,
            'deposit_collected' => 0.00,
            'warnings'          => $warnings,
        ];
    }

    /**
     * Parse a DoorDash money cell into a dollar amount.
     *
     * Handles the real-world messiness of Merchant Portal exports: thousands
     * separators, parenthesised negatives, a leading minus sign, a stray
     * leading/trailing currency symbol, and surrounding whitespace. An empty
     * cell is treated as zero, since DoorDash leaves optional fee columns
     * blank when the charge didn't apply to a given order — that is
     * different from a genuinely malformed value, which throws instead of
     * silently becoming zero.
     *
     * @param string $value  Raw cell text, e.g. `'$1,234.56'`, `'(12.34)'`,
     *        `'-12.34'`, `' 45.00 '`, or `''`.
     * @param string $column Column this value came from, used only to make a
     *        parse failure actionable.
     *
     * @return float Dollar amount; negative when the source was parenthesised
     *         or minus-prefixed.
     *
     * @throws \RuntimeException When the cell is non-empty and matches none
     *         of the recognised money formats, naming both the value and the
     *         column.
     *
     * @example
     *   DoorDashSalesCsv::parseMoney('$1,234.56', 'Subtotal');  // 1234.56
     *   DoorDashSalesCsv::parseMoney('(12.34)', 'Commission');  // -12.34
     *   DoorDashSalesCsv::parseMoney('', 'Tax');                // 0.00
     */
    private static function parseMoney(string $value, string $column): float
    {
        $text = trim($value);

        if ($text === '') {
            return 0.00;
        }

        $negative = false;

        // DoorDash notates a negative amount by wrapping it in parentheses.
        if (preg_match('/^\((.+)\)$/', $text, $matches) === 1) {
            $negative = true;
            $text     = trim($matches[1]);
        }

        // Strip a leading or trailing currency symbol (and any whitespace it
        // leaves behind), keeping digits, the decimal point, thousands
        // separators, and a leading minus sign intact.
        $text = preg_replace('/^[^0-9\-]+/', '', $text) ?? $text;
        $text = preg_replace('/[^0-9]+$/', '', $text) ?? $text;

        if (str_starts_with($text, '-')) {
            $negative = true;
            $text     = substr($text, 1);
        }

        if (preg_match('/^\d{1,3}(,\d{3})*(\.\d+)?$|^\d+(\.\d+)?$/', $text) !== 1) {
            throw new \RuntimeException(sprintf(
                'DoorDash CSV: unparseable money value "%s" in column "%s".',
                $value,
                $column
            ));
        }

        $numeric = (float) str_replace(',', '', $text);

        return $negative ? -$numeric : $numeric;
    }

    /**
     * Parse a DoorDash timestamp cell, accepting the several formats the
     * export varies between, and convert it to UTC.
     *
     * A date-only value (no time of day) is anchored at **noon business
     * time** — {@see \App\Support\WeekBucket::businessZone()} — rather than
     * midnight, before converting to UTC. This is a deliberate
     * approximation: midnight sits exactly on a day boundary, so a small
     * timezone shift could push a date-only order into the wrong day (and
     * therefore the wrong week) once converted to UTC; noon has the largest
     * possible margin on both sides.
     *
     * @param string $value  Raw cell text, e.g. `'2026-07-20'`,
     *        `'2026-07-20 14:05:00'`, `'07/20/2026'`, or `'07/20/2026 14:05'`.
     * @param string $column Column this value came from, used only to make a
     *        parse failure actionable.
     *
     * @return \DateTimeImmutable The timestamp, always in UTC, per the
     *         ChannelRow contract.
     *
     * @throws \RuntimeException When the value matches none of the accepted
     *         formats, naming the value, the column, and the formats tried.
     *
     * @example
     *   DoorDashSalesCsv::parseTimestamp('2026-07-20 14:05:00', 'occurred_at');
     *   // 2026-07-20T19:05:00+00:00 (America/Chicago is UTC-5 in July)
     */
    private static function parseTimestamp(string $value, string $column): \DateTimeImmutable
    {
        $text         = trim($value);
        $businessZone = WeekBucket::businessZone();
        $utc          = new \DateTimeZone('UTC');

        foreach (self::DATE_ONLY_FORMATS as $format) {
            $parsed = self::tryParseFormat($format, $text, $businessZone);

            if ($parsed !== null) {
                return $parsed->setTime(12, 0, 0)->setTimezone($utc);
            }
        }

        foreach (self::DATE_TIME_FORMATS as $format) {
            $parsed = self::tryParseFormat($format, $text, $businessZone);

            if ($parsed !== null) {
                return $parsed->setTimezone($utc);
            }
        }

        throw new \RuntimeException(sprintf(
            'DoorDash CSV: unrecognised date/time "%s" in column "%s"'
                . ' (expected one of Y-m-d, Y-m-d H:i:s, m/d/Y, m/d/Y H:i).',
            $value,
            $column
        ));
    }

    /**
     * Attempt to strictly parse a value against one date/time format.
     *
     * Strict in the sense that trailing data, out-of-range components (e.g.
     * day 32), and other `DateTimeImmutable::createFromFormat()` warnings are
     * treated as a non-match rather than a silently-rolled-over date.
     *
     * @param string $format Format string as accepted by `createFromFormat()`.
     * @param string $value  Candidate text to parse.
     * @param \DateTimeZone $zone Timezone fields not present in the format
     *        (and the parsed value itself) are interpreted in.
     *
     * @return \DateTimeImmutable|null The parsed value, or null when it
     *         doesn't strictly match the format.
     */
    private static function tryParseFormat(string $format, string $value, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($parsed === false) {
            return null;
        }

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $parsed;
    }
}
