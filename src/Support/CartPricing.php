<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free pricing calculations for cart line items.
 *
 * All methods operate on the canonical line-item shape produced by
 * {@see \App\Support\Cart}. Color selections are free; only add-on prices
 * contribute to a line total beyond the base unit_price.
 *
 * No database access — all prices are taken from the snapshot values stored
 * in the line item at add-time.
 *
 * @see \App\Support\Cart         Produces the items arrays consumed here.
 * @see \App\Controllers\CartController  Uses these helpers to render the cart view.
 */
final class CartPricing
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Return the sum of all add-on prices for a single line item.
     *
     * Each add-on row must carry a 'price' key (float) and may carry a
     * 'quantity' (int, default 1) for per-unit add-ons such as chocolates.
     * The per-bouquet add-on total is sum(price × quantity). Items without
     * add-ons yield 0.00.
     *
     * @param array $line Canonical line-item array.
     *
     * @return float Total add-on price per bouquet (not multiplied by bouquet qty), rounded to 2 dp.
     *
     * @example
     *   CartPricing::addonsTotal(['addons' => [['price' => 5.00], ['price' => 3.50]]]);            // 8.50
     *   CartPricing::addonsTotal(['addons' => [['price' => 2.00, 'quantity' => 3]]]);              // 6.00
     *   CartPricing::addonsTotal(['addons' => []]);                                                 // 0.00
     */
    public static function addonsTotal(array $line): float
    {
        $total = 0.0;
        foreach ((array) ($line['addons'] ?? []) as $addon) {
            $price    = (float) ($addon['price'] ?? 0.0);
            $quantity = max(1, (int) ($addon['quantity'] ?? 1));
            $total   += $price * $quantity;
        }
        return round($total, 2);
    }

    /**
     * Return the total price for a single line item including add-ons and qty.
     *
     * Formula: (unit_price + addonsTotal) * qty, rounded to 2 decimal places.
     * Color choices never affect the price.
     *
     * @param array $line Canonical line-item array with unit_price, addons, and qty.
     *
     * @return float Line total rounded to 2 dp.
     *
     * @example
     *   CartPricing::lineTotal(['unit_price'=>45.00,'addons'=>[],'qty'=>1]);       // 45.00
     *   CartPricing::lineTotal(['unit_price'=>45.00,'addons'=>[['price'=>5.00]],'qty'=>2]); // 100.00
     */
    public static function lineTotal(array $line): float
    {
        $unitPrice   = (float) ($line['unit_price'] ?? 0.0);
        $addonsTotal = self::addonsTotal($line);
        $qty         = max(1, (int) ($line['qty'] ?? 1));

        return round(($unitPrice + $addonsTotal) * $qty, 2);
    }

    /**
     * Return the subtotal across all lines in the cart.
     *
     * Sums lineTotal() for every item. An empty cart yields 0.00.
     *
     * @param array[] $items Array of canonical line-item arrays.
     *
     * @return float Cart subtotal rounded to 2 dp.
     *
     * @example
     *   CartPricing::subtotal([]); // 0.00
     *   CartPricing::subtotal([$lineA, $lineB]); // sum of both line totals
     */
    public static function subtotal(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $total += self::lineTotal($item);
        }
        return round($total, 2);
    }
}
