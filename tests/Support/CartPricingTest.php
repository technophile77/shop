<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\CartPricing;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Support\CartPricing — pure pricing math, no DB.
 *
 * Verifies add-on totals, per-line totals (with qty), cart subtotal, and that
 * color selections never affect price.
 *
 * @see \App\Support\CartPricing
 */
final class CartPricingTest extends TestCase
{
    /**
     * addonsTotal sums add-on prices and ignores qty.
     */
    public function testAddonsTotal(): void
    {
        self::assertSame(0.0, CartPricing::addonsTotal(['addons' => []]));
        self::assertSame(8.5, CartPricing::addonsTotal(['addons' => [['price' => 5.0], ['price' => 3.5]]]));
    }

    /**
     * lineTotal of a no-addon single-qty line equals its unit price.
     */
    public function testLineTotalNoAddons(): void
    {
        self::assertSame(45.0, CartPricing::lineTotal(['unit_price' => 45.0, 'addons' => [], 'qty' => 1]));
    }

    /**
     * lineTotal includes add-ons and multiplies by qty.
     */
    public function testLineTotalWithAddonsAndQty(): void
    {
        $line = ['unit_price' => 45.0, 'addons' => [['price' => 5.0]], 'qty' => 2];
        self::assertSame(100.0, CartPricing::lineTotal($line));
    }

    /**
     * Color selections do not change the price.
     */
    public function testColorsAreFree(): void
    {
        $plain   = ['unit_price' => 30.0, 'addons' => [], 'qty' => 1, 'colors' => []];
        $colored = ['unit_price' => 30.0, 'addons' => [], 'qty' => 1,
            'colors' => [['flower_type_id' => 1, 'color_ids' => [1, 2, 3], 'mixed' => true]]];
        self::assertSame(CartPricing::lineTotal($plain), CartPricing::lineTotal($colored));
    }

    /**
     * subtotal sums line totals across the cart; empty cart is 0.
     */
    public function testSubtotal(): void
    {
        self::assertSame(0.0, CartPricing::subtotal([]));
        $items = [
            ['unit_price' => 45.0, 'addons' => [['price' => 5.0]], 'qty' => 2], // 100
            ['unit_price' => 20.0, 'addons' => [], 'qty' => 1],                  // 20
        ];
        self::assertSame(120.0, CartPricing::subtotal($items));
    }

    /**
     * A missing qty defaults to a minimum of 1 (line is never free by omission).
     */
    public function testQtyDefaultsToOne(): void
    {
        self::assertSame(45.0, CartPricing::lineTotal(['unit_price' => 45.0, 'addons' => []]));
    }
}
