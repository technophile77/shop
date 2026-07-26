<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\SalesChannel;
use App\Support\WeekBucket;
use App\Support\WeeklySalesAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Unit and property tests for WeeklySalesAggregator::aggregate() and its
 * small pure helpers.
 *
 * Covers: single- and multi-channel weeks, the derive-don't-sum rule for
 * `recognized_sales`, gap-filling across quiet weeks, both directions of
 * `$throughWeekStart`, the empty-input case, order independence, warnings
 * for a non-closing row and an unrecognized channel, warning propagation
 * from a row into its week and the top-level list, and the grand total
 * equalling the hand-computed sum of a small fixed dataset. A stochastic
 * section then asserts identity closure, conservation, continuity,
 * completeness, order invariance, and shape as laws across ~200 randomly
 * generated datasets seeded with `mt_srand(20260725)`.
 *
 * @see \App\Support\WeeklySalesAggregator
 */
class WeeklySalesAggregatorTest extends TestCase
{
    /**
     * Build one ChannelRow with sensible defaults, so individual test cases
     * only need to state the figures that matter to them.
     *
     * @param string $channel A SalesChannel::* constant, or any string to
     *        exercise the unknown-channel path.
     * @param string $sourceId Identifier named in any warning this row raises.
     * @param string $recognizedAtUtc A `'Y-m-d H:i:s'` UTC instant string.
     */
    private function row(
        string $channel,
        string $sourceId,
        string $recognizedAtUtc,
        float $merchandise,
        float $delivery,
        float $tax,
        float $fees,
        float $recognizedSales,
        float $depositCollected = 0.00,
        array $warnings = []
    ): array {
        return [
            'channel'           => $channel,
            'source_id'         => $sourceId,
            'recognized_at'     => new \DateTimeImmutable($recognizedAtUtc, new \DateTimeZone('UTC')),
            'merchandise'       => $merchandise,
            'delivery'          => $delivery,
            'tax'               => $tax,
            'fees'              => $fees,
            'recognized_sales'  => $recognizedSales,
            'deposit_collected' => $depositCollected,
            'warnings'          => $warnings,
        ];
    }

    /**
     * A single shop order in one week produces exactly that week with
     * matching channel and total figures.
     */
    public function testSingleShopOrder(): void
    {
        // recognize(90.00, 10.00, 7.67, 0.00) = 90.00 + 10.00 + 7.67 - 0.00 = 107.67
        $rows = [
            $this->row(SalesChannel::SHOP, 'order-13', '2026-07-20 18:00:00', 90.00, 10.00, 7.67, 0.00, 107.67),
        ];

        $result = WeeklySalesAggregator::aggregate($rows);

        $this->assertCount(1, $result['weeks']);
        $week = $result['weeks'][0];

        $this->assertSame('2026-07-20', $week['week_start']);
        $this->assertSame(90.00, $week['channels'][SalesChannel::SHOP]['merchandise']);
        $this->assertSame(10.00, $week['channels'][SalesChannel::SHOP]['delivery']);
        $this->assertSame(7.67,  $week['channels'][SalesChannel::SHOP]['tax']);
        $this->assertSame(107.67, $week['channels'][SalesChannel::SHOP]['recognized_sales']);
        $this->assertSame(1, $week['channels'][SalesChannel::SHOP]['order_count']);
        $this->assertSame(107.67, $week['total']['recognized_sales']);
        $this->assertSame('2026-07-20', $result['first_sale_week']);
        $this->assertSame('2026-07-20', $result['last_week']);
    }

    /**
     * A shop order and a quote in the same week keep separate per-channel
     * splits while the week total sums both, and the untouched channel is
     * still present, zero-filled.
     */
    public function testTwoChannelsInTheSameWeek(): void
    {
        // recognize(35.00, 0.00, 2.98, 0.00) = 37.98
        $shop = $this->row(SalesChannel::SHOP, 'order-14', '2026-07-20 18:00:00', 35.00, 0.00, 2.98, 0.00, 37.98);
        // recognize(112.00, 15.00, 10.82, 0.00) = 137.82
        $quote = $this->row(SalesChannel::QUOTE, 'quote-19', '2026-07-22 18:00:00', 112.00, 15.00, 10.82, 0.00, 137.82, 63.50);

        $result = WeeklySalesAggregator::aggregate([$shop, $quote]);

        $this->assertCount(1, $result['weeks']);
        $week = $result['weeks'][0];

        $this->assertSame(37.98,  $week['channels'][SalesChannel::SHOP]['recognized_sales']);
        $this->assertSame(137.82, $week['channels'][SalesChannel::QUOTE]['recognized_sales']);
        $this->assertSame(63.50,  $week['channels'][SalesChannel::QUOTE]['deposit_collected']);

        // Week total: merchandise 35.00 + 112.00 = 147.00, delivery 0.00 + 15.00 = 15.00,
        // tax 2.98 + 10.82 = 13.80, recognized = 147.00 + 15.00 + 13.80 - 0.00 = 175.80
        $this->assertSame(147.00, $week['total']['merchandise']);
        $this->assertSame(15.00,  $week['total']['delivery']);
        $this->assertSame(13.80,  $week['total']['tax']);
        $this->assertSame(175.80, $week['total']['recognized_sales']);

        $this->assertArrayHasKey(SalesChannel::DOORDASH, $week['channels']);
        $this->assertSame(WeeklySalesAggregator::emptyTotals(), $week['channels'][SalesChannel::DOORDASH]);
    }

    /**
     * DoorDash's fees subtract from the recognized total, and the fee
     * figure itself is still reported on the channel and week total.
     */
    public function testDoorDashFeesSubtract(): void
    {
        // recognize(210.00, 18.00, 17.89, 35.70) = 210.00 + 18.00 + 17.89 - 35.70 = 210.19
        $rows = [
            $this->row(SalesChannel::DOORDASH, 'D-1', '2026-07-21 18:00:00', 210.00, 18.00, 17.89, 35.70, 210.19),
        ];

        $result = WeeklySalesAggregator::aggregate($rows);
        $week   = $result['weeks'][0];

        $this->assertSame(35.70,  $week['channels'][SalesChannel::DOORDASH]['fees']);
        $this->assertSame(210.19, $week['channels'][SalesChannel::DOORDASH]['recognized_sales']);
        $this->assertSame(35.70,  $week['total']['fees']);
        $this->assertSame(210.19, $week['total']['recognized_sales']);
    }

    /**
     * A quiet stretch between two sales is filled with explicit zero weeks
     * rather than a gap, and the exact week count is asserted.
     */
    public function testGapFillingBetweenTwoSales(): void
    {
        $rows = [
            $this->row(SalesChannel::SHOP, 'order-20', '2026-05-25 18:00:00', 40.00, 0.00, 3.41, 0.00, 43.41),
            $this->row(SalesChannel::SHOP, 'order-21', '2026-07-20 18:00:00', 60.00, 0.00, 5.11, 0.00, 65.11),
        ];

        $result = WeeklySalesAggregator::aggregate($rows);

        // 2026-05-25 to 2026-07-20 is 56 days (6 remaining May days + 30 June
        // days + 20 July days) = 8 weeks apart, so 9 Mondays inclusive.
        $expectedWeekStarts = [
            '2026-05-25', '2026-06-01', '2026-06-08', '2026-06-15',
            '2026-06-22', '2026-06-29', '2026-07-06', '2026-07-13', '2026-07-20',
        ];

        $this->assertCount(9, $result['weeks']);
        $this->assertSame($expectedWeekStarts, array_column($result['weeks'], 'week_start'));

        foreach (array_slice($result['weeks'], 1, 7) as $middleWeek) {
            $this->assertSame(0, $middleWeek['total']['order_count']);
            $this->assertSame(WeeklySalesAggregator::emptyTotals(), $middleWeek['total']);
            $this->assertSame(WeeklySalesAggregator::emptyTotals(), $middleWeek['channels'][SalesChannel::SHOP]);
        }
    }

    /**
     * `$throughWeekStart` past the last sale still shows the current quiet
     * week as an explicit zero row.
     */
    public function testThroughWeekStartExtendsPastLastSale(): void
    {
        $rows = [
            $this->row(SalesChannel::SHOP, 'order-22', '2026-07-20 18:00:00', 50.00, 0.00, 4.26, 0.00, 54.26),
        ];

        $result = WeeklySalesAggregator::aggregate($rows, '2026-07-27');

        $this->assertCount(2, $result['weeks']);
        $this->assertSame('2026-07-27', $result['weeks'][1]['week_start']);
        $this->assertSame(0, $result['weeks'][1]['total']['order_count']);
        $this->assertSame('2026-07-27', $result['last_week']);
    }

    /**
     * `$throughWeekStart` earlier than the last sale never drops the sale —
     * the series still runs through the last sale's week, with a warning.
     */
    public function testThroughWeekStartEarlierThanLastSaleStillIncludesIt(): void
    {
        $rows = [
            $this->row(SalesChannel::SHOP, 'order-23', '2026-07-20 18:00:00', 50.00, 0.00, 4.26, 0.00, 54.26),
        ];

        $result = WeeklySalesAggregator::aggregate($rows, '2026-07-13');

        $this->assertSame('2026-07-20', $result['last_week']);
        $this->assertCount(1, $result['weeks']);
        $this->assertSame(54.26, $result['weeks'][0]['total']['recognized_sales']);

        $joined = implode(' ', $result['warnings']);
        $this->assertStringContainsString('2026-07-13', $joined);
        $this->assertStringContainsString('2026-07-20', $joined);
    }

    /**
     * Empty input is a valid report: no weeks, zeroed totals, no exception.
     */
    public function testEmptyInput(): void
    {
        $result = WeeklySalesAggregator::aggregate([]);

        $this->assertSame([], $result['weeks']);
        $this->assertSame([], $result['warnings']);
        $this->assertNull($result['first_sale_week']);
        $this->assertNull($result['last_week']);
        $this->assertSame(WeeklySalesAggregator::emptyTotals(), $result['totals']['total']);
        $this->assertSame(WeeklySalesAggregator::emptyTotals(), $result['totals']['channels'][SalesChannel::SHOP]);
        $this->assertSame(WeeklySalesAggregator::emptyTotals(), $result['totals']['channels'][SalesChannel::QUOTE]);
        $this->assertSame(WeeklySalesAggregator::emptyTotals(), $result['totals']['channels'][SalesChannel::DOORDASH]);
    }

    /**
     * Aggregating a fixed set of rows and a shuffled copy of the same rows
     * produces byte-for-byte identical output.
     */
    public function testOrderIndependence(): void
    {
        $rows = [
            $this->row(SalesChannel::SHOP,     'order-1', '2026-06-01 18:00:00', 50.00, 5.00, 4.26, 0.00, 59.26),
            $this->row(SalesChannel::QUOTE,    'quote-1', '2026-06-01 18:00:00', 100.00, 10.00, 8.52, 0.00, 118.52, 50.00),
            $this->row(SalesChannel::DOORDASH, 'dd-1',    '2026-06-08 18:00:00', 80.00, 8.00, 6.81, 12.00, 82.81),
            $this->row(SalesChannel::SHOP,     'order-2', '2026-06-08 18:00:00', 30.00, 0.00, 2.56, 0.00, 32.56),
            $this->row(SalesChannel::QUOTE,    'quote-2', '2026-06-15 18:00:00', 200.00, 20.00, 17.03, 0.00, 237.03, 100.00),
            $this->row(SalesChannel::DOORDASH, 'dd-2',    '2026-06-15 18:00:00', 60.00, 6.00, 5.11, 9.00, 62.11),
            $this->row(SalesChannel::SHOP,     'order-3', '2026-06-22 18:00:00', 75.00, 10.00, 6.39, 0.00, 91.39),
            $this->row(SalesChannel::QUOTE,    'quote-3', '2026-06-22 18:00:00', 45.00, 0.00, 3.83, 0.00, 48.83, 22.50),
        ];

        $shuffled = [
            $rows[7], $rows[3], $rows[0], $rows[5], $rows[2], $rows[6], $rows[1], $rows[4],
        ];

        $this->assertSame(
            WeeklySalesAggregator::aggregate($rows),
            WeeklySalesAggregator::aggregate($shuffled)
        );
    }

    /**
     * A row whose own components don't close raises a warning naming its
     * source_id instead of throwing, and its full value is still counted.
     */
    public function testWarningOnNonClosingRow(): void
    {
        $rows = [
            $this->row(SalesChannel::SHOP, 'order-99', '2026-07-20 18:00:00', 100.00, 0.00, 0.00, 0.00, 999.99),
        ];

        $result = WeeklySalesAggregator::aggregate($rows);

        $joined = implode(' ', $result['warnings']);
        $this->assertStringContainsString('order-99', $joined);
        // The row's own reported figure is still counted in full, not discarded.
        $this->assertSame(100.00, $result['totals']['total']['merchandise']);
    }

    /**
     * An unrecognized channel value is still counted in the grand total, is
     * named in a warning, and gets its own key in the channels maps.
     */
    public function testUnknownChannelIsIncludedAndWarnedAbout(): void
    {
        $rows = [
            $this->row('gift_card', 'gc-1', '2026-07-20 18:00:00', 50.00, 0.00, 0.00, 0.00, 50.00),
        ];

        $result = WeeklySalesAggregator::aggregate($rows);

        $joined = implode(' ', $result['warnings']);
        $this->assertStringContainsString('gift_card', $joined);
        $this->assertStringContainsString('gc-1', $joined);

        $this->assertSame(50.00, $result['totals']['total']['merchandise']);
        $this->assertArrayHasKey('gift_card', $result['totals']['channels']);
        $this->assertSame(50.00, $result['totals']['channels']['gift_card']['merchandise']);
        $this->assertArrayHasKey('gift_card', $result['weeks'][0]['channels']);
    }

    /**
     * A row's own producer-supplied warnings propagate into both its week's
     * warnings and the top-level warnings list.
     */
    public function testWarningsPropagateToWeekAndTopLevel(): void
    {
        $producerWarning = 'quote-19: delivery line "Delivery" ($15.00) was taxed (pre-migration-018 behaviour)';

        $rows = [
            $this->row(
                SalesChannel::QUOTE,
                'quote-19',
                '2026-07-20 18:00:00',
                112.00, 15.00, 10.82, 0.00, 137.82, 63.50,
                [$producerWarning]
            ),
        ];

        $result = WeeklySalesAggregator::aggregate($rows);

        $this->assertContains($producerWarning, $result['weeks'][0]['warnings']);
        $this->assertContains($producerWarning, $result['warnings']);
    }

    /**
     * The grand total across three rows in two weeks equals the
     * hand-computed sum of those rows' figures, not a re-summing of the
     * weekly rows through the code under test.
     */
    public function testGrandTotalEqualsHandComputedSumOfRows(): void
    {
        $rows = [
            $this->row(SalesChannel::SHOP,     'order-30', '2026-06-01 18:00:00', 50.00, 5.00, 4.26, 0.00, 59.26),
            $this->row(SalesChannel::QUOTE,    'quote-30', '2026-06-01 18:00:00', 100.00, 10.00, 8.52, 0.00, 118.52, 50.00),
            $this->row(SalesChannel::DOORDASH, 'dd-30',    '2026-06-08 18:00:00', 80.00, 8.00, 6.81, 12.00, 82.81),
        ];

        $result = WeeklySalesAggregator::aggregate($rows);

        // Hand-computed, independent of the aggregator:
        // merchandise = 50.00 + 100.00 + 80.00       = 230.00
        // delivery    = 5.00 + 10.00 + 8.00           = 23.00
        // tax         = 4.26 + 8.52 + 6.81            = 19.59
        // fees        = 0.00 + 0.00 + 12.00           = 12.00
        // deposit     = 0.00 + 50.00 + 0.00           = 50.00
        // recognized  = 230.00 + 23.00 + 19.59 - 12.00 = 260.59
        $this->assertSame([
            'merchandise'       => 230.00,
            'delivery'          => 23.00,
            'tax'               => 19.59,
            'fees'              => 12.00,
            'recognized_sales'  => 260.59,
            'deposit_collected' => 50.00,
            'order_count'       => 3,
        ], $result['totals']['total']);
    }

    /**
     * emptyTotals() returns every ChannelTotals key zeroed.
     */
    public function testEmptyTotalsShape(): void
    {
        $this->assertSame([
            'merchandise'       => 0.00,
            'delivery'          => 0.00,
            'tax'               => 0.00,
            'fees'              => 0.00,
            'recognized_sales'  => 0.00,
            'deposit_collected' => 0.00,
            'order_count'       => 0,
        ], WeeklySalesAggregator::emptyTotals());
    }

    /**
     * sumTotals() is plain field-by-field addition.
     */
    public function testSumTotals(): void
    {
        $a = ['merchandise' => 10.00, 'delivery' => 1.00, 'tax' => 0.85, 'fees' => 0.00,
              'recognized_sales' => 11.85, 'deposit_collected' => 0.00, 'order_count' => 1];
        $b = ['merchandise' => 20.00, 'delivery' => 2.00, 'tax' => 1.70, 'fees' => 0.00,
              'recognized_sales' => 23.70, 'deposit_collected' => 5.00, 'order_count' => 1];

        $this->assertSame([
            'merchandise'       => 30.00,
            'delivery'          => 3.00,
            'tax'               => 2.55,
            'fees'              => 0.00,
            'recognized_sales'  => 35.55,
            'deposit_collected' => 5.00,
            'order_count'       => 2,
        ], WeeklySalesAggregator::sumTotals($a, $b));
    }

    /**
     * identityHolds() reports true for a closing total and false otherwise.
     */
    public function testIdentityHolds(): void
    {
        $this->assertTrue(WeeklySalesAggregator::identityHolds([
            'merchandise' => 90.00, 'delivery' => 10.00, 'tax' => 7.67, 'fees' => 0.00,
            'recognized_sales' => 107.67, 'deposit_collected' => 0.00, 'order_count' => 1,
        ]));

        $this->assertFalse(WeeklySalesAggregator::identityHolds([
            'merchandise' => 90.00, 'delivery' => 10.00, 'tax' => 7.67, 'fees' => 0.00,
            'recognized_sales' => 999.99, 'deposit_collected' => 0.00, 'order_count' => 1,
        ]));
    }

    /**
     * Build one random, internally-closing ChannelRow for the property
     * tests: a random known channel, realistic random dollar figures, a tax
     * figure consistent with the project's 0.08517 rate, and a
     * `recognized_at` anchored at 18:00 UTC (safely mid-afternoon in
     * America/Chicago on any date, so DST transitions never shift which
     * calendar day — and therefore which week — the row lands in) on a
     * random day within roughly a year of a fixed anchor date.
     *
     * @param int $dayOffset Days to add to the anchor date; the caller
     *        supplies a random value so the property tests can also derive
     *        the same offset independently for their expectation checks.
     *
     * @return array{channel: string, source_id: string, recognized_at: \DateTimeImmutable,
     *              merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float, warnings: list<string>}
     *         A ChannelRow that always satisfies SalesChannel::closes().
     */
    private function randomClosingRow(int $dayOffset): array
    {
        $channel = SalesChannel::ALL[mt_rand(0, count(SalesChannel::ALL) - 1)];

        $merchandise = round(mt_rand(500, 50000) / 100, 2);
        $delivery    = round(mt_rand(0, 3000) / 100, 2);
        $tax         = round($merchandise * 0.08517, 2);
        $fees        = $channel === SalesChannel::DOORDASH ? round(mt_rand(0, 4000) / 100, 2) : 0.00;
        $deposit     = $channel === SalesChannel::QUOTE ? round(mt_rand(0, (int) ($merchandise * 100)) / 100, 2) : 0.00;

        $recognizedAt = (new \DateTimeImmutable('2026-01-05 18:00:00', new \DateTimeZone('UTC')))
            ->add(new \DateInterval(sprintf('P%dD', $dayOffset)));

        return [
            'channel'           => $channel,
            'source_id'         => sprintf('stoch-%d-%d', $dayOffset, mt_rand(1000, 9999)),
            'recognized_at'     => $recognizedAt,
            'merchandise'       => $merchandise,
            'delivery'          => $delivery,
            'tax'               => $tax,
            'fees'              => $fees,
            'recognized_sales'  => SalesChannel::recognize($merchandise, $delivery, $tax, $fees),
            'deposit_collected' => $deposit,
            'warnings'          => [],
        ];
    }

    /**
     * Property test: across ~200 randomly generated datasets, the
     * aggregator obeys the laws that must hold regardless of the specific
     * data — identity closure at every level, conservation of the input
     * totals, week-to-week continuity, completeness (every generated row's
     * week appears), order invariance, and the fixed three-channel shape.
     */
    public function testStochasticPropertiesHoldAcrossRandomInputs(): void
    {
        mt_srand(20260725);

        for ($iteration = 0; $iteration < 200; $iteration++) {
            $rowCount = mt_rand(1, 20);
            $rows     = [];
            for ($i = 0; $i < $rowCount; $i++) {
                $rows[] = $this->randomClosingRow(mt_rand(0, 400));
            }

            // Conservation targets, computed independently of the aggregator.
            $expectedMerchandise = array_sum(array_column($rows, 'merchandise'));
            $expectedDelivery    = array_sum(array_column($rows, 'delivery'));
            $expectedTax         = array_sum(array_column($rows, 'tax'));
            $expectedFees        = array_sum(array_column($rows, 'fees'));
            $expectedOrderCount  = count($rows);

            $expectedWeekStarts = [];
            foreach ($rows as $row) {
                $expectedWeekStarts[WeekBucket::fromUtc($row['recognized_at'])['week_start']] = true;
            }

            $result = WeeklySalesAggregator::aggregate($rows);

            $shuffled = $rows;
            shuffle($shuffled);
            $resultShuffled = WeeklySalesAggregator::aggregate($shuffled);

            $this->assertSame($result, $resultShuffled, "order invariance failed at iteration {$iteration}");

            $this->assertEqualsWithDelta($expectedMerchandise, $result['totals']['total']['merchandise'], 0.01);
            $this->assertEqualsWithDelta($expectedDelivery, $result['totals']['total']['delivery'], 0.01);
            $this->assertEqualsWithDelta($expectedTax, $result['totals']['total']['tax'], 0.01);
            $this->assertEqualsWithDelta($expectedFees, $result['totals']['total']['fees'], 0.01);
            $this->assertSame($expectedOrderCount, $result['totals']['total']['order_count']);

            foreach ($result['weeks'] as $week) {
                foreach (SalesChannel::ALL as $channel) {
                    $this->assertArrayHasKey($channel, $week['channels'], "week {$week['week_start']} missing channel {$channel}");
                    $this->assertTrue(WeeklySalesAggregator::identityHolds($week['channels'][$channel]));
                }
                $this->assertTrue(WeeklySalesAggregator::identityHolds($week['total']));
                $this->assertSame('1', (new \DateTimeImmutable($week['week_start']))->format('N'));
            }

            foreach ($result['totals']['channels'] as $channelTotals) {
                $this->assertTrue(WeeklySalesAggregator::identityHolds($channelTotals));
            }
            $this->assertTrue(WeeklySalesAggregator::identityHolds($result['totals']['total']));

            $previousWeekStart = null;
            foreach ($result['weeks'] as $week) {
                if ($previousWeekStart !== null) {
                    $expectedNext = (new \DateTimeImmutable($previousWeekStart))
                        ->add(new \DateInterval('P7D'))
                        ->format('Y-m-d');
                    $this->assertSame($expectedNext, $week['week_start']);
                    $this->assertTrue(WeekBucket::compareWeekStart($previousWeekStart, $week['week_start']) < 0);
                }
                $previousWeekStart = $week['week_start'];
            }

            $resultWeekStarts = array_column($result['weeks'], 'week_start');
            foreach (array_keys($expectedWeekStarts) as $expectedWeekStart) {
                $this->assertContains($expectedWeekStart, $resultWeekStarts, "missing week {$expectedWeekStart}");
            }
        }
    }
}
