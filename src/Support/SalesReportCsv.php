<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure formatter that turns the weekly sales aggregate into CSV-ready row
 * arrays for the owner's spreadsheet workflow.
 *
 * This class never touches the filesystem and never calls `fputcsv()` itself
 * — it only builds `list<list<scalar>>` shapes that the calling script hands
 * straight to `fputcsv()` in a loop. Two CSVs are produced from the same
 * aggregate: {@see self::rows()} is the primary weekly report, and
 * {@see self::warningRows()} is a companion audit file that lists every
 * warning and every classifier-assigned line so the classification heuristic
 * can be checked against real dollar amounts.
 *
 * Every money cell is formatted with `number_format($v, 2, '.', '')` —
 * deliberately with **no thousands separator and no currency symbol**. A
 * thousands comma would be indistinguishable from a column separator in a
 * comma-delimited file, silently corrupting the row it appears in, and a
 * leading `$` stops most spreadsheet software from recognising the cell as a
 * number for sums and formatting.
 *
 * No filesystem access, no database access, no side effects.
 *
 * @see \App\Support\SalesChannel        Defines the channel order and the accounting identity.
 * @see \App\Support\SalesReportHtml     Renders the same aggregate as a shareable HTML page.
 * @see \App\Support\WeeklySalesAggregator Produces the `$aggregate` shape consumed here.
 */
final class SalesReportCsv
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Per-channel (and per-total) field order, shared by the header and every
     * data row so the two can never drift out of sync.
     *
     * @var list<string>
     */
    private const CHANNEL_FIELDS = [
        'merchandise',
        'delivery',
        'tax',
        'fees',
        'recognized_sales',
        'order_count',
    ];

    /** Literal `week_start` cell used for the trailing totals row. */
    private const TOTAL_ROW_LABEL = 'TOTAL';

    /**
     * Build the primary weekly report as CSV-ready rows.
     *
     * Row 0 is the header. Every subsequent row is one week, in the order
     * `$aggregate['weeks']` already provides, followed by a final `TOTAL` row
     * built from `$aggregate['totals']`. Columns are `week_start`, `iso_week`,
     * then for each channel in {@see \App\Support\SalesChannel::ALL} the
     * prefixed columns `{channel}_merchandise`, `_delivery`, `_tax`, `_fees`,
     * `_recognized_sales`, `_order_count`, then a single `quote_deposit_collected`
     * column (deposits are quote-only; the other channels are always 0.00),
     * then the `total_*` equivalents of the six per-channel fields.
     *
     * @param array{
     *     weeks: list<array{week_start: string, iso_week: string,
     *                       channels: array<string, array{merchandise: float, delivery: float,
     *                           tax: float, fees: float, recognized_sales: float,
     *                           deposit_collected: float, order_count: int}>,
     *                       total: array{merchandise: float, delivery: float, tax: float,
     *                           fees: float, recognized_sales: float,
     *                           deposit_collected: float, order_count: int},
     *                       warnings: list<string>}>,
     *     totals: array{channels: array<string, array{merchandise: float, delivery: float,
     *                       tax: float, fees: float, recognized_sales: float,
     *                       deposit_collected: float, order_count: int}>,
     *                   total: array{merchandise: float, delivery: float, tax: float,
     *                       fees: float, recognized_sales: float,
     *                       deposit_collected: float, order_count: int}},
     *     warnings: list<string>,
     *     first_sale_week: ?string,
     *     last_week: ?string,
     * } $aggregate The weekly sales aggregate.
     *
     * @return list<list<scalar>> Ready to pass to `fputcsv()` in a loop. Every
     *         row (including the header and the `TOTAL` row) has identical width.
     *
     * @example
     *   $rows = SalesReportCsv::rows($aggregate);
     *   $fh = fopen($path, 'wb');
     *   foreach ($rows as $row) {
     *       fputcsv($fh, $row);
     *   }
     */
    public static function rows(array $aggregate): array
    {
        $rows = [self::header()];

        foreach ($aggregate['weeks'] as $week) {
            $rows[] = self::dataRow($week['week_start'], $week['iso_week'], $week['channels'], $week['total']);
        }

        $rows[] = self::dataRow(
            self::TOTAL_ROW_LABEL,
            '',
            $aggregate['totals']['channels'],
            $aggregate['totals']['total']
        );

        return $rows;
    }

    /**
     * Build the companion warnings/audit CSV as CSV-ready rows.
     *
     * Row 0 is always the header, even when there is nothing to report — so
     * the file always exists and an empty report is visibly a single-row
     * file rather than an absent one. Two kinds of row follow the header:
     * one `warning` row per warning **occurrence** — carrying `week_start` when
     * the warning belongs to a week and empty when it is global — and one
     * `classified_line` row per entry in `$auditLines` — every line the
     * classifier heuristic assigned to a bucket, so the assignment can be
     * eyeballed against its dollar amount.
     *
     * `$aggregate['warnings']` is a superset of the per-week lists (see
     * {@see \App\Support\WeeklySalesAggregator::aggregate()}), so it is
     * deliberately *not* emitted verbatim — only the warnings in it that no
     * week already accounted for get a row. Otherwise every week-attributable
     * warning would be listed twice.
     *
     * @param array{weeks: list<array{week_start: string, warnings: list<string>}>,
     *              warnings: list<string>} $aggregate The weekly sales aggregate
     *        (only `weeks[].week_start`, `weeks[].warnings`, and `warnings` are read).
     * @param list<array{source_id: string, week_start: string, description: string,
     *              amount: float, bucket: string, reason: string}> $auditLines
     *        Every line the classifier heuristic assigned to a bucket, e.g. the
     *        `lines` entries out of {@see \App\Support\QuoteSalesBreakdown::fromQuote()}.
     *
     * @return list<list<scalar>> Header row `['type', 'week_start', 'source_id',
     *         'description', 'amount', 'bucket', 'detail']` followed by one row
     *         per warning and per audit line. Never an empty array.
     *
     * @example
     *   SalesReportCsv::warningRows(['weeks' => [], 'warnings' => []]);
     *   // [['type', 'week_start', 'source_id', 'description', 'amount', 'bucket', 'detail']]
     */
    public static function warningRows(array $aggregate, array $auditLines = []): array
    {
        $rows = [['type', 'week_start', 'source_id', 'description', 'amount', 'bucket', 'detail']];

        // The aggregate's top-level `warnings` is a superset of every week's own
        // list — WeeklySalesAggregator files each row warning under its week AND
        // in the flat list, so the terminal summary can print all of them from
        // one place. Emitting both lists verbatim would therefore print every
        // week-attributable warning twice. Count what the week pass emitted and
        // let the global pass emit only the surplus, so a warning appears exactly
        // as many times as it occurred: attributed to its week where there is
        // one, and with an empty `week_start` where there is not.
        $emitted = [];

        foreach ($aggregate['weeks'] as $week) {
            foreach ($week['warnings'] as $warning) {
                $rows[]            = ['warning', $week['week_start'], '', '', '', '', $warning];
                $emitted[$warning] = ($emitted[$warning] ?? 0) + 1;
            }
        }

        foreach ($aggregate['warnings'] as $warning) {
            if (($emitted[$warning] ?? 0) > 0) {
                --$emitted[$warning];
                continue;
            }

            $rows[] = ['warning', '', '', '', '', '', $warning];
        }

        foreach ($auditLines as $line) {
            $rows[] = [
                'classified_line',
                $line['week_start'],
                $line['source_id'],
                $line['description'],
                self::formatMoney($line['amount']),
                $line['bucket'],
                $line['reason'],
            ];
        }

        return $rows;
    }

    /**
     * Build the CSV header row, in the same field order {@see self::dataRow()}
     * fills in — kept as one source of truth so the two can never drift.
     *
     * @return list<string> The header row.
     */
    private static function header(): array
    {
        $columns = ['week_start', 'iso_week'];

        foreach (SalesChannel::ALL as $channel) {
            foreach (self::CHANNEL_FIELDS as $field) {
                $columns[] = "{$channel}_{$field}";
            }
        }

        $columns[] = 'quote_deposit_collected';

        foreach (self::CHANNEL_FIELDS as $field) {
            $columns[] = "total_{$field}";
        }

        return $columns;
    }

    /**
     * Build one data row (a week, or the trailing `TOTAL` row) in the same
     * column order as {@see self::header()}.
     *
     * @param string $weekStart The `week_start` cell, or `'TOTAL'` for the totals row.
     * @param string $isoWeek   The `iso_week` cell, or `''` for the totals row.
     * @param array<string, array{merchandise: float, delivery: float, tax: float,
     *              fees: float, recognized_sales: float, deposit_collected: float,
     *              order_count: int}> $channels Per-channel totals, keyed by
     *        {@see \App\Support\SalesChannel::ALL}.
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float,
     *              order_count: int} $total Combined totals across channels.
     *
     * @return list<scalar> One CSV row, the same width as {@see self::header()}.
     */
    private static function dataRow(string $weekStart, string $isoWeek, array $channels, array $total): array
    {
        $row = [$weekStart, $isoWeek];

        foreach (SalesChannel::ALL as $channel) {
            $row = [...$row, ...self::channelCells($channels[$channel])];
        }

        $row[] = self::formatMoney($channels[SalesChannel::QUOTE]['deposit_collected']);

        return [...$row, ...self::channelCells($total)];
    }

    /**
     * Build the six field cells (merchandise through order_count) for one
     * channel or totals figure.
     *
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float,
     *              order_count: int} $totals One channel's (or the combined) figures.
     *
     * @return list<scalar> `[merchandise, delivery, tax, fees, recognized_sales, order_count]`,
     *         money cells formatted per {@see self::formatMoney()}.
     */
    private static function channelCells(array $totals): array
    {
        return [
            self::formatMoney($totals['merchandise']),
            self::formatMoney($totals['delivery']),
            self::formatMoney($totals['tax']),
            self::formatMoney($totals['fees']),
            self::formatMoney($totals['recognized_sales']),
            $totals['order_count'],
        ];
    }

    /**
     * Format a dollar amount for a CSV cell.
     *
     * No thousands separator and no currency symbol — see the class DocBlock
     * for why both would corrupt or de-numericise the cell.
     *
     * @param float $amount Dollar amount, already rounded to 2dp upstream.
     *
     * @return string Fixed 2-decimal string, e.g. `'1234.50'`.
     *
     * @example
     *   self::formatMoney(1234.5);   // '1234.50'
     *   self::formatMoney(1234.567); // '1234.57'
     */
    private static function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
