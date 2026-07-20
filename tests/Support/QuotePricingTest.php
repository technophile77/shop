<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\QuotePricing;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuotePricing::compute().
 *
 * Regression coverage for the bug behind live quote #17: delivery used to be
 * folded into a hand-typed items_json line and taxed along with the
 * merchandise. Covers the tax base (merchandise subtotal only, never the
 * delivery fee), the total composition (subtotal + tax + delivery), and —
 * critically — that deposit_amount never includes delivery, since delivery
 * falls due with the balance rather than up front.
 *
 * @see \App\Support\QuotePricing
 * @see \App\Support\CheckoutPricing  Equivalent calculator for shop-cart checkout.
 */
class QuotePricingTest extends TestCase
{
    /**
     * With no delivery, tax and total apply only to merchandise.
     */
    public function testZeroDeliveryFee(): void
    {
        $items  = [['unit_price' => 100.00, 'qty' => 1, 'full_deposit' => false]];
        $result = QuotePricing::compute($items, 50, 0.08517, 0.00);

        $this->assertSame(100.00, $result['subtotal']);
        $this->assertSame(0.00,   $result['delivery_fee']);
        $this->assertSame(8.52,   $result['tax_amount']); // round(100 * 0.08517, 2)
        $this->assertSame(108.52, $result['total']);
    }

    /**
     * Delivery adds to the total but is NOT part of the tax base — this is the
     * exact bug the migration and this class exist to fix (live quote #17 was
     * taxed on a $14 delivery line).
     */
    public function testDeliveryFeeNotTaxed(): void
    {
        $items  = [['unit_price' => 100.00, 'qty' => 1, 'full_deposit' => false]];
        $result = QuotePricing::compute($items, 50, 0.08517, 15.00);

        // Tax = round(100 * 0.08517, 2) = 8.52 — delivery not included in the base
        $this->assertSame(8.52,   $result['tax_amount']);
        $this->assertSame(15.00,  $result['delivery_fee']);
        $this->assertSame(123.52, $result['total']);
    }

    /**
     * Subtotal, tax_amount, and delivery_fee must sum exactly to total.
     */
    public function testTotalComposition(): void
    {
        $items  = [
            ['unit_price' => 30.00, 'qty' => 2, 'full_deposit' => false],
            ['unit_price' => 5.00,  'qty' => 3, 'full_deposit' => false],
        ];
        $result = QuotePricing::compute($items, 40, 0.08517, 12.00);

        $expected = round(
            $result['subtotal'] + $result['tax_amount'] + $result['delivery_fee'],
            2
        );
        $this->assertSame($expected, $result['total']);
    }

    /**
     * deposit_amount is derived from items only (via QuoteService::calculateDeposit)
     * and must NOT include the delivery fee, since delivery falls due with the
     * balance rather than up front. This is a regression guard for the bug this
     * whole change fixes.
     */
    public function testDepositAmountExcludesDeliveryFee(): void
    {
        $items = [['unit_price' => 200.00, 'qty' => 1, 'full_deposit' => false]];

        $withoutDelivery = QuotePricing::compute($items, 30, 0.0, 0.00);
        $withDelivery    = QuotePricing::compute($items, 30, 0.0, 500.00);

        // deposit = 30% of the $200 merchandise subtotal = $60.00, unaffected by
        // a $500 delivery fee.
        $this->assertSame(60.00, $withoutDelivery['deposit_amount']);
        $this->assertSame(60.00, $withDelivery['deposit_amount']);
    }

    /**
     * A full_deposit-flagged item is still charged in full for the deposit,
     * independent of any delivery fee present alongside it.
     */
    public function testDepositAmountWithFullDepositItemsExcludesDelivery(): void
    {
        $items = [
            ['unit_price' => 50.0,  'qty' => 1, 'full_deposit' => true],
            ['unit_price' => 100.0, 'qty' => 1, 'full_deposit' => false],
        ];

        $result = QuotePricing::compute($items, 50, 0.0, 25.00);

        // 50 (full) + 50% of 100 = 100.00 — delivery of $25 plays no part.
        $this->assertSame(100.00, $result['deposit_amount']);
    }

    /**
     * Tax rounds to 2 decimal places (matches CheckoutPricing's rounding rule).
     */
    public function testTaxRounding(): void
    {
        // 0.08517 * 33 = 2.81061 → 2.81
        $items  = [['unit_price' => 33.00, 'qty' => 1, 'full_deposit' => false]];
        $result = QuotePricing::compute($items, 50, 0.08517, 0.00);

        $this->assertSame(2.81, $result['tax_amount']);
    }

    /**
     * A zero tax rate returns zero tax; total is subtotal + delivery only.
     */
    public function testZeroTaxRate(): void
    {
        $items  = [['unit_price' => 50.00, 'qty' => 1, 'full_deposit' => false]];
        $result = QuotePricing::compute($items, 50, 0.00, 5.00);

        $this->assertSame(0.00,  $result['tax_amount']);
        $this->assertSame(55.00, $result['total']);
    }

    /**
     * Subtotal is the sum of qty * unit_price across every item, before tax
     * or delivery are applied.
     */
    public function testSubtotalSumsAllItems(): void
    {
        $items = [
            ['unit_price' => 25.00, 'qty' => 2, 'full_deposit' => false],
            ['unit_price' => 10.00, 'qty' => 3, 'full_deposit' => false],
        ];
        $result = QuotePricing::compute($items, 50, 0.00, 0.00);

        // (25*2) + (10*3) = 80.00
        $this->assertSame(80.00, $result['subtotal']);
    }

    /**
     * All five keys are present in the return value.
     */
    public function testReturnKeysPresent(): void
    {
        $items  = [['unit_price' => 10.00, 'qty' => 1, 'full_deposit' => false]];
        $result = QuotePricing::compute($items, 50, 0.05, 2.00);

        $this->assertArrayHasKey('subtotal',       $result);
        $this->assertArrayHasKey('tax_amount',     $result);
        $this->assertArrayHasKey('delivery_fee',   $result);
        $this->assertArrayHasKey('deposit_amount', $result);
        $this->assertArrayHasKey('total',          $result);
    }

    /**
     * An empty item list yields zero subtotal, tax, and deposit — total equals
     * whatever delivery fee (if any) was passed.
     */
    public function testEmptyItemsWithDeliveryFee(): void
    {
        $result = QuotePricing::compute([], 50, 0.08517, 20.00);

        $this->assertSame(0.00,  $result['subtotal']);
        $this->assertSame(0.00,  $result['tax_amount']);
        $this->assertSame(0.0,   $result['deposit_amount']);
        $this->assertSame(20.00, $result['delivery_fee']);
        $this->assertSame(20.00, $result['total']);
    }

    /**
     * Stochastic: tax_amount, deposit_amount, and total must all match an
     * independent reference computation — never including delivery in the tax
     * base or the deposit — across random item sets, tax rates, deposit
     * percentages, and delivery fees.
     */
    public function testStochasticMatchesIndependentReference(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $items      = [];
            $rows       = random_int(1, 5);
            $subtotal   = 0.0;
            $fullTotal  = 0.0;
            $restTotal  = 0.0;

            for ($r = 0; $r < $rows; $r++) {
                $qty       = random_int(1, 5);
                $unitCents = random_int(0, 50000); // up to $500.00
                $unitPrice = $unitCents / 100;
                $flag      = (bool) random_int(0, 1);

                $items[] = ['unit_price' => $unitPrice, 'qty' => $qty, 'full_deposit' => $flag];

                $line = $unitPrice * $qty;
                $subtotal += $line;
                if ($flag) {
                    $fullTotal += $line;
                } else {
                    $restTotal += $line;
                }
            }

            $depositPct  = random_int(0, 100);
            $taxRate     = random_int(0, 10000) / 100000; // 0.00000 – 0.10000
            $deliveryFee = random_int(0, 5000) / 100;      // $0.00 – $50.00

            $expectedSubtotal = round($subtotal, 2);
            $expectedTax      = round($subtotal * $taxRate, 2);
            $expectedDeposit  = round($fullTotal + $restTotal * ($depositPct / 100), 2);
            $expectedTotal    = round($expectedSubtotal + $expectedTax + $deliveryFee, 2);

            $result = QuotePricing::compute($items, $depositPct, $taxRate, $deliveryFee);

            $this->assertSame($expectedSubtotal, $result['subtotal'], "Subtotal mismatch on iteration {$i}");
            $this->assertSame($expectedTax,      $result['tax_amount'], "Tax mismatch on iteration {$i}");
            $this->assertSame($expectedDeposit,  $result['deposit_amount'], "Deposit mismatch on iteration {$i}");
            $this->assertSame(round($deliveryFee, 2), $result['delivery_fee'], "Delivery fee mismatch on iteration {$i}");
            $this->assertSame($expectedTotal,    $result['total'], "Total mismatch on iteration {$i}");
        }
    }
}
