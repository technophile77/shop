<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure builder for Stripe Checkout line_items arrays from cart data.
 *
 * Converts a cart items array (canonical shape from {@see \App\Support\Cart})
 * into the `line_items` array expected by the Stripe Checkout Sessions API.
 * All amounts are expressed as integer cents per Stripe's requirement.
 *
 * Rules:
 *  - One line per bouquet (product name, unit_price * 100 cents, qty).
 *  - One line per add-on per cart line: unit_amount = addon.price * 100 cents,
 *    quantity = the add-on's quantity × the bouquet's qty (so per-unit add-ons
 *    such as chocolates ×3 across 2 bouquets bill 6 units).
 *    Add-ons priced at 0 are included only when they carry a custom_text value.
 *  - A single Delivery line when deliveryFee > 0.
 *  - A single Sales Tax line when taxAmount > 0.
 *
 * No database access, no Stripe SDK instantiation — this is a pure transform.
 *
 * @see \App\Support\Cart              Canonical line-item shape consumed here.
 * @see \App\Support\CheckoutPricing   Produces the deliveryFee and taxAmount inputs.
 * @see \App\Services\StripeService    Passes the output of fromCart() to Stripe.
 */
final class StripeLineItems
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Build a Stripe-ready line_items array from cart data and pricing totals.
     *
     * @param array[] $items       Canonical cart line-item arrays from CartSession::items().
     * @param float   $deliveryFee Delivery fee in dollars; adds a line when > 0.
     * @param float   $taxAmount   Pre-computed sales tax in dollars; adds a line when > 0.
     *
     * @return list<array{price_data: array{currency: string, unit_amount: int, product_data: array{name: string}}, quantity: int}>
     *         Array of Stripe line_item params ready for checkout->sessions->create().
     *
     * @example
     *   $lineItems = StripeLineItems::fromCart($items, 10.00, 3.83);
     *   // [
     *   //   ['price_data'=>['currency'=>'usd','unit_amount'=>4500,'product_data'=>['name'=>'Rose Bouquet']],'quantity'=>1],
     *   //   ['price_data'=>['currency'=>'usd','unit_amount'=>1000,'product_data'=>['name'=>'Delivery']],'quantity'=>1],
     *   //   ['price_data'=>['currency'=>'usd','unit_amount'=>383,'product_data'=>['name'=>'Sales Tax']],'quantity'=>1],
     *   // ]
     */
    public static function fromCart(array $items, float $deliveryFee, float $taxAmount): array
    {
        $lineItems = [];

        foreach ($items as $item) {
            $bouquetName = (string) ($item['name_en'] ?? 'Bouquet');
            $unitAmount  = (int) round((float) ($item['unit_price'] ?? 0) * 100);
            $qty         = max(1, (int) ($item['qty'] ?? 1));

            $lineItems[] = [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $unitAmount,
                    'product_data' => ['name' => $bouquetName],
                ],
                'quantity' => $qty,
            ];

            foreach ((array) ($item['addons'] ?? []) as $addon) {
                $addonPrice      = (float) ($addon['price'] ?? 0.0);
                $addonQty        = max(1, (int) ($addon['quantity'] ?? 1));
                $addonCustomText = isset($addon['custom_text']) ? trim((string) $addon['custom_text']) : '';

                // Skip zero-price add-ons that carry no custom text.
                if ($addonPrice <= 0.0 && $addonCustomText === '') {
                    continue;
                }

                $addonCents = (int) round($addonPrice * 100);
                $addonLabel = (string) ($addon['name_en'] ?? 'Add-on');
                if ($addonCustomText !== '') {
                    $addonLabel .= ': ' . $addonCustomText;
                }

                // Per-unit price; total units = the add-on's quantity across each
                // bouquet in the line (addon qty × bouquet qty).
                $lineItems[] = [
                    'price_data' => [
                        'currency'     => 'usd',
                        'unit_amount'  => $addonCents,
                        'product_data' => ['name' => $addonLabel],
                    ],
                    'quantity' => $addonQty * $qty,
                ];
            }
        }

        if ($deliveryFee > 0.0) {
            $lineItems[] = [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => (int) round($deliveryFee * 100),
                    'product_data' => ['name' => 'Delivery'],
                ],
                'quantity' => 1,
            ];
        }

        if ($taxAmount > 0.0) {
            $lineItems[] = [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => (int) round($taxAmount * 100),
                    'product_data' => ['name' => 'Sales Tax'],
                ],
                'quantity' => 1,
            ];
        }

        return $lineItems;
    }
}
