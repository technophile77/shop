<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\CheckoutPricing;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CheckoutPricing::compute().
 *
 * Covers the tax-base decision (merchandise only, not delivery), rounding at
 * 2 decimal places, zero-delivery and with-delivery compositions, and the
 * relationship total = subtotal + delivery_fee + tax_amount.
 *
 * @see \App\Support\CheckoutPricing
 */
class CheckoutPricingTest extends TestCase
{
    /**
     * With no delivery the fee and tax apply only to merchandise.
     */
    public function testZeroDeliveryFee(): void
    {
        $result = CheckoutPricing::compute(100.00, 0.00, 0.08517);

        $this->assertSame(100.00, $result['subtotal']);
        $this->assertSame(0.00,   $result['delivery_fee']);
        $this->assertSame(8.52,   $result['tax_amount']);   // round(100 * 0.08517, 2)
        $this->assertSame(108.52, $result['total']);
    }

    /**
     * Delivery adds to the total but is NOT part of the tax base.
     */
    public function testDeliveryFeeNotTaxed(): void
    {
        $result = CheckoutPricing::compute(100.00, 10.00, 0.08517);

        // Tax = round(100 * 0.08517, 2) = 8.52 — delivery not included
        $this->assertSame(8.52,   $result['tax_amount']);
        $this->assertSame(10.00,  $result['delivery_fee']);
        $this->assertSame(118.52, $result['total']);
    }

    /**
     * Subtotal, delivery_fee, and tax_amount must sum exactly to total.
     */
    public function testTotalComposition(): void
    {
        $result = CheckoutPricing::compute(45.00, 12.00, 0.08517);

        $expected = round(
            $result['subtotal'] + $result['delivery_fee'] + $result['tax_amount'],
            2
        );
        $this->assertSame($expected, $result['total']);
    }

    /**
     * Tax rounds to 2 decimal places (0.5 rounds up by PHP's round() default).
     */
    public function testTaxRounding(): void
    {
        // 0.08517 * 33 = 2.81061 → 2.81
        $result = CheckoutPricing::compute(33.00, 0.00, 0.08517);
        $this->assertSame(2.81, $result['tax_amount']);
    }

    /**
     * A zero tax rate returns zero tax and total equals subtotal + delivery.
     */
    public function testZeroTaxRate(): void
    {
        $result = CheckoutPricing::compute(50.00, 5.00, 0.00);

        $this->assertSame(0.00,  $result['tax_amount']);
        $this->assertSame(55.00, $result['total']);
    }

    /**
     * All four keys are present in the return value.
     */
    public function testReturnKeysPresent(): void
    {
        $result = CheckoutPricing::compute(10.00, 2.00, 0.05);

        $this->assertArrayHasKey('subtotal',     $result);
        $this->assertArrayHasKey('delivery_fee', $result);
        $this->assertArrayHasKey('tax_amount',   $result);
        $this->assertArrayHasKey('total',        $result);
    }

    /**
     * Large subtotal with a real-world OK tax rate.
     */
    public function testLargeSubtotal(): void
    {
        // $350 bouquet + $15 delivery
        $result = CheckoutPricing::compute(350.00, 15.00, 0.08517);

        // tax_amount = round(350 * 0.08517, 2) = round(29.8095, 2) = 29.81
        $this->assertSame(29.81,  $result['tax_amount']);
        $this->assertSame(394.81, $result['total']);
    }
}
