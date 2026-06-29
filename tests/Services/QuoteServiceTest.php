<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Services\QuoteService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuoteService deposit math and item decoding.
 *
 * Focuses on the per-line-item "full deposit" behaviour: flagged items are
 * charged in full while the rest follow deposit_pct, and the flag round-trips
 * through decodeItems(). A stochastic test cross-checks calculateDeposit()
 * against an independent reference computation over random item sets.
 *
 * @see \App\Services\QuoteService
 */
class QuoteServiceTest extends TestCase
{
    /**
     * With no flagged items the deposit is the plain subtotal × pct.
     */
    public function testDepositWithoutFlaggedItems(): void
    {
        $items = [
            ['qty' => 2, 'unit_price' => 50.0, 'full_deposit' => false],
            ['qty' => 1, 'unit_price' => 100.0],
        ];

        // subtotal 200 × 50% = 100.00
        $this->assertSame(100.00, QuoteService::calculateDeposit($items, 50));
    }

    /**
     * A flagged item is counted in full; unflagged items use the percentage.
     */
    public function testDepositMixesFullAndPercentItems(): void
    {
        $items = [
            ['qty' => 1, 'unit_price' => 50.0,  'full_deposit' => true],
            ['qty' => 1, 'unit_price' => 100.0, 'full_deposit' => false],
        ];

        // 50 (full) + 50% of 100 = 100.00
        $this->assertSame(100.00, QuoteService::calculateDeposit($items, 50));
    }

    /**
     * When every item is flagged the deposit equals the whole subtotal.
     */
    public function testDepositAllItemsFlagged(): void
    {
        $items = [
            ['qty' => 2, 'unit_price' => 30.0, 'full_deposit' => true],
            ['qty' => 1, 'unit_price' => 40.0, 'full_deposit' => true],
        ];

        // 60 + 40 = 100, regardless of pct
        $this->assertSame(100.00, QuoteService::calculateDeposit($items, 25));
    }

    /**
     * A missing full_deposit key is treated as not flagged.
     */
    public function testDepositTreatsMissingFlagAsFalse(): void
    {
        $items = [['qty' => 1, 'unit_price' => 80.0]];

        $this->assertSame(20.00, QuoteService::calculateDeposit($items, 25));
    }

    /**
     * An empty item list yields a zero deposit.
     */
    public function testDepositEmptyItems(): void
    {
        $this->assertSame(0.0, QuoteService::calculateDeposit([], 50));
    }

    /**
     * The result is rounded to two decimal places.
     */
    public function testDepositRoundsToCents(): void
    {
        $items = [['qty' => 1, 'unit_price' => 33.33, 'full_deposit' => false]];

        // 33.33 × 50% = 16.665 → 16.67
        $this->assertSame(16.67, QuoteService::calculateDeposit($items, 50));
    }

    /**
     * Stochastic: calculateDeposit must equal an independent reference sum for
     * random item sets, quantities, prices, flags, and deposit percentages.
     */
    public function testDepositStochasticMatchesReference(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $items = [];
            $rows  = random_int(1, 6);
            $full  = 0.0;
            $rest  = 0.0;

            for ($r = 0; $r < $rows; $r++) {
                $qty       = random_int(1, 5);
                $unitCents = random_int(0, 50000);     // up to $500.00, in cents
                $unitPrice = $unitCents / 100;
                $flag      = (bool) random_int(0, 1);

                $items[] = ['qty' => $qty, 'unit_price' => $unitPrice, 'full_deposit' => $flag];

                $line = $unitPrice * $qty;
                if ($flag) {
                    $full += $line;
                } else {
                    $rest += $line;
                }
            }

            $pct      = random_int(0, 100);
            $expected = round($full + $rest * ($pct / 100), 2);

            $this->assertSame(
                $expected,
                QuoteService::calculateDeposit($items, $pct),
                "Deposit mismatch on iteration {$i} (pct {$pct})",
            );
        }
    }

    /**
     * decodeItems coerces the full_deposit flag to bool and defaults it false.
     */
    public function testDecodeItemsCarriesFullDepositFlag(): void
    {
        $json = json_encode([
            ['description' => 'Flagged', 'qty' => 1, 'unit_price' => 50, 'full_deposit' => true],
            ['description' => 'Legacy',  'qty' => 2, 'unit_price' => 25], // no flag key
        ], JSON_THROW_ON_ERROR);

        $items = QuoteService::decodeItems($json);

        $this->assertTrue($items[0]['full_deposit']);
        $this->assertFalse($items[1]['full_deposit']);
        $this->assertSame('Flagged', $items[0]['description']);
        $this->assertSame(2, $items[1]['qty']);
        $this->assertSame(25.0, $items[1]['unit_price']);
    }

    /**
     * An empty or 'null' items blob decodes to an empty array.
     */
    public function testDecodeItemsEmpty(): void
    {
        $this->assertSame([], QuoteService::decodeItems(''));
        $this->assertSame([], QuoteService::decodeItems('null'));
    }
}
