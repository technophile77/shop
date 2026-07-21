<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\QuoteService;

/**
 * Pure, side-effect-free pricing calculator for custom quotes.
 *
 * Computes the merchandise subtotal, tax, deposit, and grand total from a
 * quote's line items, deposit percentage, tax rate, and delivery fee. Tax is
 * applied only to the merchandise subtotal, never to the delivery fee, for
 * the same reason the shop-cart checkout path already handles it this way
 * (see {@see \App\Support\CheckoutPricing}): Oklahoma treats a
 * separately-stated delivery charge as a non-taxable service. The deposit is
 * likewise computed from items only via
 * {@see \App\Services\QuoteService::calculateDeposit()} — delivery is never
 * included, because delivery falls due with the balance rather than up front.
 *
 * No database access, no session access, no side effects.
 *
 * @see \App\Models\Quote            Persists the values this computes.
 * @see \App\Support\CheckoutPricing Equivalent calculator for shop-cart checkout.
 */
final class QuotePricing
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Compute the full pricing breakdown for a quote.
     *
     * Tax base is the merchandise subtotal only (not the delivery fee), and
     * the deposit is derived from items only — delivery is excluded from
     * both. Formula:
     *   subtotal       = sum(item.unit_price * item.qty)
     *   tax_amount     = round(subtotal * taxRate, 2)
     *   deposit_amount = QuoteService::calculateDeposit(items, depositPct)
     *   total          = round(subtotal + tax_amount + deliveryFee, 2)
     *
     * @param array<int, array{unit_price: float, qty: int, full_deposit?: bool}> $items
     *        Quote line items in the shape {@see \App\Models\Quote::create()} expects.
     * @param int   $depositPct  Percentage of the non-full-deposit subtotal due
     *                           up front, e.g. 50 for 50%.
     * @param float $taxRate     Decimal sales tax rate, e.g. 0.08517 for 8.517%.
     * @param float $deliveryFee Delivery fee in dollars; 0.00 when not delivering.
     *
     * @return array{subtotal: float, tax_amount: float, delivery_fee: float,
     *               deposit_amount: float, total: float}
     *         All values are floats rounded to 2 decimal places.
     *
     * @example
     *   QuotePricing::compute(
     *       [['unit_price' => 75.00, 'qty' => 2, 'full_deposit' => false]],
     *       50, 0.08517, 15.00
     *   );
     *   // ['subtotal'=>150.00, 'tax_amount'=>12.78, 'delivery_fee'=>15.00,
     *   //  'deposit_amount'=>75.00, 'total'=>177.78]
     */
    public static function compute(array $items, int $depositPct, float $taxRate, float $deliveryFee): array
    {
        $subtotal = array_reduce(
            $items,
            static fn (float $carry, array $item): float =>
                $carry + ((float) $item['unit_price'] * (int) $item['qty']),
            0.0
        );

        $taxAmount     = round($subtotal * $taxRate, 2);
        $depositAmount = QuoteService::calculateDeposit($items, $depositPct);
        $total         = round($subtotal + $taxAmount + $deliveryFee, 2);

        return [
            'subtotal'       => round($subtotal, 2),
            'tax_amount'     => $taxAmount,
            'delivery_fee'   => round($deliveryFee, 2),
            'deposit_amount' => $depositAmount,
            'total'          => $total,
        ];
    }
}
