<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free pricing calculator for the shop-cart checkout flow.
 *
 * Computes tax and total from a merchandise subtotal, a delivery fee, and a
 * tax rate. Tax is applied only to the merchandise subtotal, not to the
 * delivery fee, because Oklahoma sales-tax law treats delivery as a non-taxable
 * service charge when separately stated.
 *
 * No database access, no session access, no side effects.
 *
 * @see \App\Controllers\CheckoutController  Primary consumer.
 * @see \App\Support\StripeLineItems          Consumes the output of compute().
 */
final class CheckoutPricing
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Compute the full pricing breakdown for a shop checkout.
     *
     * Tax base is the merchandise subtotal only (not the delivery fee).
     * Formula:
     *   tax_amount = round($subtotal * $taxRate, 2)
     *   total      = round($subtotal + $deliveryFee + $taxAmount, 2)
     *
     * @param float $subtotal    Merchandise subtotal in dollars (from CartPricing::subtotal()).
     * @param float $deliveryFee Delivery fee in dollars (0.00 for pickup orders).
     * @param float $taxRate     Decimal tax rate, e.g. 0.08517 for 8.517%.
     *
     * @return array{subtotal: float, delivery_fee: float, tax_amount: float, total: float}
     *         All values are floats rounded to 2 decimal places.
     *
     * @example
     *   CheckoutPricing::compute(45.00, 10.00, 0.08517);
     *   // ['subtotal'=>45.00, 'delivery_fee'=>10.00, 'tax_amount'=>3.83, 'total'=>58.83]
     */
    public static function compute(float $subtotal, float $deliveryFee, float $taxRate): array
    {
        $taxAmount = round($subtotal * $taxRate, 2);
        $total     = round($subtotal + $deliveryFee + $taxAmount, 2);

        return [
            'subtotal'     => round($subtotal, 2),
            'delivery_fee' => round($deliveryFee, 2),
            'tax_amount'   => $taxAmount,
            'total'        => $total,
        ];
    }
}
