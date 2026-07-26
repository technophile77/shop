<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\DoorDashSalesCsv;
use App\Support\SalesChannel;
use PHPUnit\Framework\TestCase;

/**
 * Unit and property tests for DoorDashSalesCsv::parse().
 *
 * Covers: the happy path across three real-world order shapes (pickup, with
 * delivery, and with a marketing fee alongside commission); the fail-loud
 * behaviour when a required header is renamed, including that the exception
 * message names the field, an attempted spelling, and the config file;
 * order-independence of column position; alternative accepted header
 * spellings; unmapped vs. ignored extra columns (including that a `Tip`
 * column never contributes to any money figure); every documented money
 * format `parseMoney()` must handle; a malformed row being skipped with a
 * line-numbered warning while other rows still parse; a non-closing net
 * payout producing a warning without being overwritten or throwing; and the
 * four accepted date/time formats, including the noon-business-time
 * anchoring of a date-only value. A stochastic test then checks the
 * class's invariants (row count, non-negative fees, recognized-sales
 * agreement, order-independence, UTC timestamps) across 150 randomly
 * generated well-formed CSVs.
 *
 * @see \App\Support\DoorDashSalesCsv
 */
class DoorDashSalesCsvTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Read a fixture CSV under tests/fixtures/doordash/ via fgetcsv(), so
     * the tests exercise the same row shape DoorDashSalesCsv::parse()
     * documents as its input (a list<list<string>> with the header first).
     */
    private function readFixture(string $filename): array
    {
        $path   = __DIR__ . '/../fixtures/doordash/' . $filename;
        $handle = fopen($path, 'rb');
        $this->assertNotFalse($handle, "Could not open fixture: $filename");

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    // -------------------------------------------------------------------------
    // 1. Happy path
    // -------------------------------------------------------------------------

    /**
     * Three orders — a pickup with no delivery fee, one with a delivery fee,
     * and one with both a commission and a marketing fee — all parse with
     * the correct per-row figures and a net payout that closes.
     */
    public function testHappyPathThreeOrders(): void
    {
        $rows = $this->readFixture('happy-path.csv');
        $map  = require DoorDashSalesCsv::defaultMapPath();

        $result = DoorDashSalesCsv::parse($rows, $map);

        $this->assertSame([], $result['warnings']);
        $this->assertSame([], $result['unmapped_headers']);
        $this->assertCount(3, $result['rows']);

        // D-1: pickup, no delivery fee. 90.00 + 0.00 + 7.65 - 13.50 = 84.15
        $row1 = $result['rows'][0];
        $this->assertSame(SalesChannel::DOORDASH, $row1['channel']);
        $this->assertSame('D-1', $row1['source_id']);
        $this->assertSame(90.00, $row1['merchandise']);
        $this->assertSame(0.00, $row1['delivery']);
        $this->assertSame(7.65, $row1['tax']);
        $this->assertSame(13.50, $row1['fees']);
        $this->assertSame(84.15, $row1['recognized_sales']);
        $this->assertSame('UTC', $row1['recognized_at']->getTimezone()->getName());
        $this->assertSame([], $row1['warnings']);

        // D-2: an $8.00 delivery fee. 120.00 + 8.00 + 10.20 - 18.00 = 120.20
        $row2 = $result['rows'][1];
        $this->assertSame('D-2', $row2['source_id']);
        $this->assertSame(120.00, $row2['merchandise']);
        $this->assertSame(8.00, $row2['delivery']);
        $this->assertSame(10.20, $row2['tax']);
        $this->assertSame(18.00, $row2['fees']);
        $this->assertSame(120.20, $row2['recognized_sales']);

        // D-3: commission ($30.00) plus a marketing fee ($5.00), so
        // fees = 30.00 + 5.00 = 35.00, and
        // 200.00 + 12.00 + 17.00 - 35.00 = 194.00
        $row3 = $result['rows'][2];
        $this->assertSame('D-3', $row3['source_id']);
        $this->assertSame(200.00, $row3['merchandise']);
        $this->assertSame(12.00, $row3['delivery']);
        $this->assertSame(17.00, $row3['tax']);
        $this->assertSame(35.00, $row3['fees']);
        $this->assertSame(194.00, $row3['recognized_sales']);
    }

    // -------------------------------------------------------------------------
    // 2. Renamed required header
    // -------------------------------------------------------------------------

    /**
     * Renaming a required column ('Subtotal' -> 'Item Cost', an unmapped
     * spelling) throws a RuntimeException whose message names the missing
     * canonical field, an attempted spelling, and the config file to fix.
     */
    public function testRenamedRequiredHeaderThrowsActionableException(): void
    {
        $rows = $this->readFixture('renamed-required-header.csv');
        $map  = require DoorDashSalesCsv::defaultMapPath();

        try {
            DoorDashSalesCsv::parse($rows, $map);
            $this->fail('Expected a RuntimeException for the missing merchandise column.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('merchandise', $e->getMessage());
            $this->assertStringContainsString('Subtotal', $e->getMessage());
            $this->assertStringContainsString('doordash-report-map.php', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // 3. Reordered columns
    // -------------------------------------------------------------------------

    /**
     * The same three orders as the happy path, with every column shuffled
     * into a different order, parse to identical figures — proving the
     * column resolution is by header name, not position.
     */
    public function testReorderedColumnsProduceIdenticalOutput(): void
    {
        $map = require DoorDashSalesCsv::defaultMapPath();

        $happyPath = DoorDashSalesCsv::parse($this->readFixture('happy-path.csv'), $map);
        $reordered = DoorDashSalesCsv::parse($this->readFixture('reordered-columns.csv'), $map);

        $this->assertCount(3, $reordered['rows']);

        foreach ($happyPath['rows'] as $index => $expected) {
            $actual = $reordered['rows'][$index];

            $this->assertSame($expected['source_id'], $actual['source_id']);
            $this->assertSame($expected['merchandise'], $actual['merchandise']);
            $this->assertSame($expected['delivery'], $actual['delivery']);
            $this->assertSame($expected['tax'], $actual['tax']);
            $this->assertSame($expected['fees'], $actual['fees']);
            $this->assertSame($expected['recognized_sales'], $actual['recognized_sales']);
            $this->assertSame(
                $expected['recognized_at']->getTimestamp(),
                $actual['recognized_at']->getTimestamp()
            );
        }
    }

    // -------------------------------------------------------------------------
    // 4. Alternative header spellings
    // -------------------------------------------------------------------------

    /**
     * Using the secondary accepted spellings ('Order date', 'Tax',
     * 'Net payout') instead of the primary ones still parses successfully.
     */
    public function testAlternativeHeaderSpellingsParse(): void
    {
        $rows = $this->readFixture('alternative-spellings.csv');
        $map  = require DoorDashSalesCsv::defaultMapPath();

        $result = DoorDashSalesCsv::parse($rows, $map);

        $this->assertCount(1, $result['rows']);

        // 90.00 + 0.00 + 7.65 - 13.50 = 84.15
        $row = $result['rows'][0];
        $this->assertSame('D-1', $row['source_id']);
        $this->assertSame(90.00, $row['merchandise']);
        $this->assertSame(7.65, $row['tax']);
        $this->assertSame(13.50, $row['fees']);
        $this->assertSame(84.15, $row['recognized_sales']);
    }

    // -------------------------------------------------------------------------
    // 5. Unmapped header
    // -------------------------------------------------------------------------

    /**
     * An extra, unrecognised column ('Mystery New Fee') is reported in
     * unmapped_headers as a warning-worthy surprise, but does not abort
     * parsing and is not folded into any money figure.
     */
    public function testUnmappedHeaderIsReportedButDoesNotBreakParsing(): void
    {
        $rows = $this->readFixture('unmapped-header.csv');
        $map  = require DoorDashSalesCsv::defaultMapPath();

        $result = DoorDashSalesCsv::parse($rows, $map);

        $this->assertSame(['Mystery New Fee'], $result['unmapped_headers']);
        $this->assertCount(1, $result['rows']);

        // The $2.00 in "Mystery New Fee" must not be added to fees.
        $this->assertSame(13.50, $result['rows'][0]['fees']);
    }

    // -------------------------------------------------------------------------
    // 6. Ignored header
    // -------------------------------------------------------------------------

    /**
     * A 'Tip' column is explicitly ignored: it does not appear in
     * unmapped_headers, and the tip amount is not added to merchandise,
     * fees, or any other money figure — a tip passes through to the Dasher
     * and is never merchant flower revenue.
     */
    public function testIgnoredHeaderIsSilentAndExcludedFromMoney(): void
    {
        $rows = $this->readFixture('ignored-header.csv');
        $map  = require DoorDashSalesCsv::defaultMapPath();

        $result = DoorDashSalesCsv::parse($rows, $map);

        $this->assertSame([], $result['unmapped_headers']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];
        $this->assertSame(90.00, $row['merchandise']);
        $this->assertSame(13.50, $row['fees']);
        $this->assertSame(84.15, $row['recognized_sales']); // $15.00 tip is nowhere in this figure
    }

    // -------------------------------------------------------------------------
    // 7. Money formats
    // -------------------------------------------------------------------------

    /**
     * Every documented money format ($1,234.56 thousands separator,
     * (12.34) parenthesised negative, -12.34 leading minus, an empty cell,
     * and surrounding whitespace) parses to the correct dollar amount.
     */
    public function testMoneyFormatsParseCorrectly(): void
    {
        $rows = $this->readFixture('money-formats.csv');
        $map  = require DoorDashSalesCsv::defaultMapPath();

        $result = DoorDashSalesCsv::parse($rows, $map);

        $this->assertCount(5, $result['rows']);

        $this->assertSame(1234.56, $result['rows'][0]['merchandise']); // "$1,234.56"
        $this->assertSame(-12.34, $result['rows'][1]['merchandise']);  // "(12.34)"
        $this->assertSame(-12.34, $result['rows'][2]['merchandise']);  // "-12.34"
        $this->assertSame(0.00, $result['rows'][3]['merchandise']);    // "" (empty)
        $this->assertSame(45.00, $result['rows'][4]['merchandise']);   // " 45.00 "
    }

    // -------------------------------------------------------------------------
    // 8. Malformed row
    // -------------------------------------------------------------------------

    /**
     * A row with the wrong number of cells (D-2, missing one column) is
     * skipped with a warning naming its 1-based line number, while the
     * well-formed rows before and after it still parse.
     */
    public function testMalformedRowIsSkippedWithLineNumberedWarning(): void
    {
        $rows = $this->readFixture('malformed-row.csv');
        $map  = require DoorDashSalesCsv::defaultMapPath();

        $result = DoorDashSalesCsv::parse($rows, $map);

        $this->assertCount(2, $result['rows']);
        $this->assertSame('D-1', $result['rows'][0]['source_id']);
        $this->assertSame('D-3', $result['rows'][1]['source_id']);

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Line 3', $result['warnings'][0]);
    }

    // -------------------------------------------------------------------------
    // 9. Non-closing identity
    // -------------------------------------------------------------------------

    /**
     * A row whose net payout disagrees with merchandise + delivery + tax -
     * fees emits a warning naming the order id and both figures, keeps
     * DoorDash's own net payout as recognized_sales (never overwritten by
     * our computed figure), and does not throw.
     */
    public function testNonClosingIdentityWarnsWithoutThrowingOrOverwriting(): void
    {
        $rows = $this->readFixture('non-closing-identity.csv');
        $map  = require DoorDashSalesCsv::defaultMapPath();

        $result = DoorDashSalesCsv::parse($rows, $map);

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];

        // 100.00 + 10.00 + 8.50 - 5.00 = 113.50, but the CSV's net total is
        // 100.00 — the mismatch DoorDashSalesCsv must not silently correct.
        $this->assertSame(100.00, $row['recognized_sales']);
        $this->assertCount(1, $row['warnings']);
        $this->assertStringContainsString('D-9', $row['warnings'][0]);
        $this->assertStringContainsString('100.00', $row['warnings'][0]);
        $this->assertStringContainsString('113.50', $row['warnings'][0]);
    }

    // -------------------------------------------------------------------------
    // 10. Date formats
    // -------------------------------------------------------------------------

    /**
     * 'm/d/Y' (date-only, anchored at noon America/Chicago and converted to
     * UTC), 'Y-m-d H:i:s', and 'm/d/Y H:i' all parse to the correct UTC
     * instant.
     */
    public function testDateFormatsParseToCorrectUtcInstant(): void
    {
        $rows = $this->readFixture('date-formats.csv');
        $map  = require DoorDashSalesCsv::defaultMapPath();

        $result = DoorDashSalesCsv::parse($rows, $map);

        $this->assertCount(3, $result['rows']);

        // DT-1: date-only '07/20/2026' -> noon America/Chicago (CDT, UTC-5
        // in July) -> 17:00 UTC on the same calendar day.
        $dt1 = $result['rows'][0]['recognized_at'];
        $this->assertSame('UTC', $dt1->getTimezone()->getName());
        $this->assertSame('2026-07-20 17:00:00', $dt1->format('Y-m-d H:i:s'));

        // DT-2: '2026-07-21 15:30:00' local -> +5 hours -> 20:30 UTC.
        $dt2 = $result['rows'][1]['recognized_at'];
        $this->assertSame('2026-07-21 20:30:00', $dt2->format('Y-m-d H:i:s'));

        // DT-3: '07/22/2026 09:15' local -> +5 hours -> 14:15 UTC.
        $dt3 = $result['rows'][2]['recognized_at'];
        $this->assertSame('2026-07-22 14:15:00', $dt3->format('Y-m-d H:i:s'));
    }

    // -------------------------------------------------------------------------
    // Stochastic / property test
    // -------------------------------------------------------------------------

    /**
     * Across 150 seeded, randomly generated well-formed CSVs (random row
     * counts, random dollar amounts, columns reshuffled every iteration),
     * the class's invariants hold: parsed row count matches the generated
     * row count, every fees value is non-negative regardless of which
     * negative notation the generator used, recognized_sales always agrees
     * with the generated net payout to within a cent, reshuffling columns
     * never changes any parsed figure, and every recognized_at is UTC.
     */
    public function testStochasticInvariantsHoldAcrossRandomCsvs(): void
    {
        mt_srand(20260725);

        $map = require DoorDashSalesCsv::defaultMapPath();

        $baseHeader = [
            'Order ID', 'Timestamp local date', 'Subtotal',
            'Delivery fee', 'Tax subtotal', 'Commission', 'Net total',
        ];

        for ($iteration = 0; $iteration < 150; $iteration++) {
            $rowCount = mt_rand(1, 6);

            $dataRows           = [];
            $expectedFees       = [];
            $expectedNetPayouts = [];

            for ($i = 0; $i < $rowCount; $i++) {
                // Format to 2dp *before* casting back to float, so the
                // "expected" value is bit-identical to what parseMoney()
                // will produce from the same decimal text — an independent
                // check, not a re-run of the parser's own arithmetic.
                $merchandiseStr = sprintf('%.2f', mt_rand(500, 50000) / 100);
                $deliveryStr    = sprintf('%.2f', mt_rand(0, 3000) / 100);
                $taxStr         = sprintf('%.2f', mt_rand(0, 3000) / 100);
                $feeStr         = sprintf('%.2f', mt_rand(0, 5000) / 100);

                $netPayout    = round(
                    (float) $merchandiseStr + (float) $deliveryStr + (float) $taxStr - (float) $feeStr,
                    2
                );
                $netPayoutStr = sprintf('%.2f', $netPayout);

                // Exercise both negative notations DoorDash uses.
                $feeText = mt_rand(0, 1) === 0 ? "-{$feeStr}" : "({$feeStr})";

                $day = mt_rand(1, 27);

                $dataRows[] = [
                    sprintf('P-%d-%d', $iteration, $i),
                    sprintf('2026-07-%02d 12:00:00', $day),
                    $merchandiseStr,
                    $deliveryStr,
                    $taxStr,
                    $feeText,
                    $netPayoutStr,
                ];

                $expectedFees[]       = (float) $feeStr;
                $expectedNetPayouts[] = (float) $netPayoutStr;
            }

            $resultA = DoorDashSalesCsv::parse([$baseHeader, ...$dataRows], $map);

            $this->assertCount($rowCount, $resultA['rows'], 'law: output row count == input data row count');

            foreach ($resultA['rows'] as $index => $row) {
                $this->assertGreaterThanOrEqual(0.0, $row['fees'], 'law: fees is never negative');
                $this->assertSame($expectedFees[$index], $row['fees'], 'fees matches the generated magnitude');
                $this->assertEqualsWithDelta(
                    $expectedNetPayouts[$index],
                    $row['recognized_sales'],
                    0.01,
                    'law: recognized_sales agrees with the generated net payout'
                );
                $this->assertSame('UTC', $row['recognized_at']->getTimezone()->getName(), 'law: recognized_at is UTC');
            }

            // Deterministic Fisher-Yates shuffle of the column order, applied
            // identically to the header and every data row.
            $permutation = range(0, count($baseHeader) - 1);
            for ($p = count($permutation) - 1; $p > 0; $p--) {
                $swap                    = mt_rand(0, $p);
                [$permutation[$p], $permutation[$swap]] = [$permutation[$swap], $permutation[$p]];
            }

            $shuffledHeader = array_map(static fn (int $col) => $baseHeader[$col], $permutation);
            $shuffledRows   = array_map(
                static fn (array $row) => array_map(static fn (int $col) => $row[$col], $permutation),
                $dataRows
            );

            $resultB = DoorDashSalesCsv::parse([$shuffledHeader, ...$shuffledRows], $map);

            $this->assertCount($rowCount, $resultB['rows']);

            foreach ($resultA['rows'] as $index => $rowA) {
                $rowB = $resultB['rows'][$index];

                $this->assertSame($rowA['source_id'], $rowB['source_id'], 'law: shuffle does not change source_id');
                $this->assertSame($rowA['merchandise'], $rowB['merchandise'], 'law: shuffle does not change merchandise');
                $this->assertSame($rowA['delivery'], $rowB['delivery'], 'law: shuffle does not change delivery');
                $this->assertSame($rowA['tax'], $rowB['tax'], 'law: shuffle does not change tax');
                $this->assertSame($rowA['fees'], $rowB['fees'], 'law: shuffle does not change fees');
                $this->assertSame(
                    $rowA['recognized_sales'],
                    $rowB['recognized_sales'],
                    'law: shuffle does not change recognized_sales'
                );
                $this->assertSame(
                    $rowA['recognized_at']->getTimestamp(),
                    $rowB['recognized_at']->getTimestamp(),
                    'law: shuffle does not change recognized_at'
                );
            }
        }
    }
}
