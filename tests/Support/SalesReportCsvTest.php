<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\SalesReportCsv;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SalesReportCsv::rows() and ::warningRows().
 *
 * Fixtures are hand-built literal arrays matching the shared weekly-sales
 * aggregate shape, independent of WeeklySalesAggregator, so these tests do
 * not depend on that class being finished. Expected cell values are
 * hand-computed literals with the arithmetic shown in a comment, never
 * derived by re-running the code under test.
 *
 * @see \App\Support\SalesReportCsv
 */
class SalesReportCsvTest extends TestCase
{
    /**
     * The exact header row must list week_start/iso_week, then the six
     * per-channel fields for shop/quote/doordash, then quote_deposit_collected,
     * then the six total_* fields.
     */
    public function testHeaderRow(): void
    {
        $rows = SalesReportCsv::rows($this->oneWeekAggregate());

        $this->assertSame([
            'week_start', 'iso_week',
            'shop_merchandise', 'shop_delivery', 'shop_tax', 'shop_fees', 'shop_recognized_sales', 'shop_order_count',
            'quote_merchandise', 'quote_delivery', 'quote_tax', 'quote_fees', 'quote_recognized_sales', 'quote_order_count',
            'doordash_merchandise', 'doordash_delivery', 'doordash_tax', 'doordash_fees', 'doordash_recognized_sales', 'doordash_order_count',
            'quote_deposit_collected',
            'total_merchandise', 'total_delivery', 'total_tax', 'total_fees', 'total_recognized_sales', 'total_order_count',
        ], $rows[0]);
    }

    /**
     * A known one-week aggregate produces the exact expected cell values.
     */
    public function testOneWeekDataRow(): void
    {
        $rows = SalesReportCsv::rows($this->oneWeekAggregate());

        $this->assertSame([
            '2026-07-06', '2026-W28',
            '100.00', '10.00', '8.52', '0.00', '118.52', 2,
            '112.00', '15.00', '10.82', '0.00', '137.82', 1,
            '210.00', '18.00', '17.89', '35.70', '210.19', 1,
            '63.50',
            '422.00', '43.00', '37.23', '35.70', '466.53', 4,
        ], $rows[1]);
    }

    /**
     * Money cells are formatted with no `$` and no thousands comma, since
     * either would corrupt or de-numericise a CSV cell.
     */
    public function testMoneyCellsHaveNoDollarSignOrComma(): void
    {
        $rows = SalesReportCsv::rows($this->oneWeekAggregate());

        foreach (array_slice($rows[1], 2) as $index => $cell) {
            if (is_int($cell)) {
                continue; // order_count columns
            }

            $this->assertStringNotContainsString('$', (string) $cell, "cell index {$index}");
            $this->assertStringNotContainsString(',', (string) $cell, "cell index {$index}");
        }
    }

    /**
     * The last row is the TOTAL row, and its values match the aggregate's
     * `totals` entry exactly.
     */
    public function testLastRowIsTotal(): void
    {
        $rows = SalesReportCsv::rows($this->oneWeekAggregate());
        $last = $rows[count($rows) - 1];

        $this->assertSame('TOTAL', $last[0]);
        $this->assertSame('', $last[1]);

        // Same figures as the single week, since there is only one week.
        $this->assertSame([
            'TOTAL', '',
            '100.00', '10.00', '8.52', '0.00', '118.52', 2,
            '112.00', '15.00', '10.82', '0.00', '137.82', 1,
            '210.00', '18.00', '17.89', '35.70', '210.19', 1,
            '63.50',
            '422.00', '43.00', '37.23', '35.70', '466.53', 4,
        ], $last);
    }

    /**
     * Every row, including the header and the TOTAL row, has identical
     * width — a ragged CSV is a real bug.
     */
    public function testAllRowsHaveIdenticalWidth(): void
    {
        $rows  = SalesReportCsv::rows($this->oneWeekAggregate());
        $width = count($rows[0]);

        foreach ($rows as $index => $row) {
            $this->assertCount($width, $row, "row {$index} width differs from header width");
        }
    }

    /**
     * With nothing to report, warningRows() returns just the header row —
     * never an empty array, so the file always exists.
     */
    public function testWarningRowsHeaderOnlyWhenNothingToReport(): void
    {
        $rows = SalesReportCsv::warningRows([
            'weeks' => [],
            'totals' => ['channels' => [], 'total' => []],
            'warnings' => [],
            'first_sale_week' => null,
            'last_week' => null,
        ]);

        $this->assertSame([
            ['type', 'week_start', 'source_id', 'description', 'amount', 'bucket', 'detail'],
        ], $rows);
    }

    /**
     * warningRows() includes one row per warning (week-level and top-level)
     * and one row per audit line, with the correct `type` discriminator.
     */
    public function testWarningRowsIncludesWarningsAndClassifiedLines(): void
    {
        $aggregate = $this->oneWeekAggregate();
        $aggregate['warnings'] = ['DoorDash CSV: optional "marketing fee" column not present.'];

        $auditLines = [[
            'source_id'   => 'quote-19:1',
            'week_start'  => '2026-07-06',
            'description' => 'Delivery',
            'amount'      => 15.00,
            'bucket'      => 'delivery',
            'reason'      => 'matched keyword "delivery"',
        ]];

        $rows = SalesReportCsv::warningRows($aggregate, $auditLines);

        $this->assertSame([
            ['type', 'week_start', 'source_id', 'description', 'amount', 'bucket', 'detail'],
            ['warning', '2026-07-06', '', '', '', '', 'quote-19: delivery line "Delivery" ($15.00) was taxed (pre-migration-018 behaviour)'],
            ['warning', '', '', '', '', '', 'DoorDash CSV: optional "marketing fee" column not present.'],
            ['classified_line', '2026-07-06', 'quote-19:1', 'Delivery', '15.00', 'delivery', 'matched keyword "delivery"'],
        ], $rows);
    }

    /**
     * One week of hand-built ChannelTotals figures, verified to close the
     * SalesChannel identity for every channel and for the combined total.
     *
     * shop:     100.00 + 10.00 + 8.52 - 0.00 = 118.52
     * quote:    112.00 + 15.00 + 10.82 - 0.00 = 137.82
     * doordash: 210.00 + 18.00 + 17.89 - 35.70 = 210.19
     * total:    422.00 + 43.00 + 37.23 - 35.70 = 466.53
     *
     * @return array{weeks: list<array<string, mixed>>, totals: array<string, mixed>,
     *              warnings: list<string>, first_sale_week: ?string, last_week: ?string}
     */
    private function oneWeekAggregate(): array
    {
        $channels = [
            'shop' => [
                'merchandise' => 100.00, 'delivery' => 10.00, 'tax' => 8.52, 'fees' => 0.00,
                'recognized_sales' => 118.52, 'deposit_collected' => 0.00, 'order_count' => 2,
            ],
            'quote' => [
                'merchandise' => 112.00, 'delivery' => 15.00, 'tax' => 10.82, 'fees' => 0.00,
                'recognized_sales' => 137.82, 'deposit_collected' => 63.50, 'order_count' => 1,
            ],
            'doordash' => [
                'merchandise' => 210.00, 'delivery' => 18.00, 'tax' => 17.89, 'fees' => 35.70,
                'recognized_sales' => 210.19, 'deposit_collected' => 0.00, 'order_count' => 1,
            ],
        ];

        $total = [
            'merchandise' => 422.00, 'delivery' => 43.00, 'tax' => 37.23, 'fees' => 35.70,
            'recognized_sales' => 466.53, 'deposit_collected' => 63.50, 'order_count' => 4,
        ];

        $week = [
            'week_start' => '2026-07-06',
            'iso_week'   => '2026-W28',
            'channels'   => $channels,
            'total'      => $total,
            'warnings'   => ['quote-19: delivery line "Delivery" ($15.00) was taxed (pre-migration-018 behaviour)'],
        ];

        return [
            'weeks'           => [$week],
            'totals'          => ['channels' => $channels, 'total' => $total],
            'warnings'        => [],
            'first_sale_week' => '2026-07-06',
            'last_week'       => '2026-07-06',
        ];
    }
}
