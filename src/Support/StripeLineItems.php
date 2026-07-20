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
 *  - A single Delivery line when deliveryFee > 0 (never taxed — Oklahoma treats
 *    separately-stated delivery as a non-taxable service).
 *  - Sales tax: when taxAmount > 0 and a Stripe Tax Rate id is supplied, that
 *    rate is attached to the merchandise (bouquet + add-on) lines so Stripe
 *    computes and reports the tax. Delivery is deliberately excluded from the
 *    tax base, matching {@see \App\Support\CheckoutPricing}. When no rate id is
 *    supplied, a plain Sales Tax line is appended as a fallback so tax is still
 *    charged (though Stripe will not classify it as tax).
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
     * @param array[]     $items       Canonical cart line-item arrays from CartSession::items().
     * @param float       $deliveryFee Delivery fee in dollars; adds a (never-taxed) line when > 0.
     * @param float       $taxAmount   Pre-computed sales tax in dollars; used as the signal for
     *                                 whether tax applies (> 0). With a $taxRateId, Stripe
     *                                 recomputes the exact cents, which may differ by a cent.
     * @param string|null $taxRateId   Stripe Tax Rate object id (e.g. STRIPE_TAX_RATE_ID). When
     *                                 non-empty and $taxAmount > 0, it is attached to merchandise
     *                                 lines and no Sales Tax line is added. When null/empty, a
     *                                 plain Sales Tax line is appended as a fallback.
     *
     * @return list<array<string, mixed>>
     *         Array of Stripe line_item params ready for checkout->sessions->create().
     *
     * @example
     *   $lineItems = StripeLineItems::fromCart($items, 10.00, 3.83, 'txr_123');
     *   // [
     *   //   ['price_data'=>[...'Rose Bouquet'],'quantity'=>1,'tax_rates'=>['txr_123']],
     *   //   ['price_data'=>[...'Delivery'],'quantity'=>1],   // no tax_rates — delivery untaxed
     *   // ]
     */
    public static function fromCart(
        array $items,
        float $deliveryFee,
        float $taxAmount,
        ?string $taxRateId = null,
    ): array {
        $lineItems = [];

        // Attach the rate to merchandise lines only when tax applies and a rate
        // object is configured; delivery is excluded from the tax base.
        $rateId       = $taxRateId ?? '';
        $applyTaxRate = $taxAmount > 0.0 && $rateId !== '';

        foreach ($items as $item) {
            $bouquetName = (string) ($item['name_en'] ?? 'Bouquet');
            $unitAmount  = (int) round((float) ($item['unit_price'] ?? 0) * 100);
            $qty         = max(1, (int) ($item['qty'] ?? 1));

            $bouquetLine = [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $unitAmount,
                    'product_data' => ['name' => $bouquetName],
                ],
                'quantity' => $qty,
            ];
            if ($applyTaxRate) {
                $bouquetLine['tax_rates'] = [$rateId];
            }
            $lineItems[] = $bouquetLine;

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
                $addonLine = [
                    'price_data' => [
                        'currency'     => 'usd',
                        'unit_amount'  => $addonCents,
                        'product_data' => ['name' => $addonLabel],
                    ],
                    'quantity' => $addonQty * $qty,
                ];
                if ($applyTaxRate) {
                    $addonLine['tax_rates'] = [$rateId];
                }
                $lineItems[] = $addonLine;
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

        // Fallback only: no Tax Rate object configured but tax is owed. Charge it
        // as a plain line so revenue is correct, even though Stripe won't report
        // it as tax. Prefer supplying $taxRateId so this branch never runs.
        if ($taxAmount > 0.0 && !$applyTaxRate) {
            $lineItems[] = self::salesTaxLine($taxAmount);
        }

        return $lineItems;
    }

    /**
     * Build a Stripe-ready line_items array from decoded quote items.
     *
     * Mirrors the quote's tax base, which now taxes only the merchandise
     * items (the quote subtotal), never the delivery fee — see
     * {@see \App\Models\Quote::create()} and {@see \App\Support\QuotePricing}.
     * When tax applies and a Stripe Tax Rate id is supplied, that rate is
     * attached to every merchandise line so Stripe computes and reports the
     * tax; otherwise a single plain Sales Tax line is appended as a fallback.
     * When $deliveryFee is > 0, a single "Delivery" line is appended with no
     * `tax_rates` key, exactly as {@see fromCart()} does — Oklahoma treats a
     * separately-stated delivery charge as non-taxable.
     *
     * Tax is expressed by **exactly one** mechanism — either tax_rates on the
     * item lines or the fallback Sales Tax line, never both — so a quote is
     * never taxed twice.
     *
     * @param array<int, array{description: string, qty: int, unit_price: float}> $items
     *        Decoded quote items from QuoteService::decodeItems().
     * @param float       $taxAmount   Pre-computed tax in dollars from quote['tax_amount'];
     *                                 used only as the signal for whether tax applies (> 0).
     *                                 With a $taxRateId, Stripe recomputes the exact cents.
     * @param string|null $taxRateId   Stripe Tax Rate object id (STRIPE_TAX_RATE_ID). When
     *                                 non-empty and $taxAmount > 0, attached to every
     *                                 merchandise line and no Sales Tax line is added. When
     *                                 null/empty, a Sales Tax line is appended instead.
     * @param float       $deliveryFee Delivery fee in dollars from quote['delivery_fee'];
     *                                 adds a (never-taxed) Delivery line when > 0.
     *
     * @return list<array<string, mixed>>
     *         Array of Stripe line_item params ready for checkout->sessions->create().
     *
     * @example
     *   $lineItems = StripeLineItems::fromQuoteItems($items, 7.24, 'txr_123', 15.00);
     *   // every merchandise line carries 'tax_rates' => ['txr_123']; no Sales Tax
     *   // line; a trailing Delivery line with no tax_rates.
     */
    public static function fromQuoteItems(
        array   $items,
        float   $taxAmount,
        ?string $taxRateId = null,
        float   $deliveryFee = 0.0,
    ): array {
        $rateId       = $taxRateId ?? '';
        $applyTaxRate = $taxAmount > 0.0 && $rateId !== '';

        $lineItems = array_map(static function (array $item) use ($applyTaxRate, $rateId): array {
            $line = [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => (int) round((float) $item['unit_price'] * 100),
                    'product_data' => ['name' => (string) $item['description']],
                ],
                'quantity' => max(1, (int) $item['qty']),
            ];
            if ($applyTaxRate) {
                $line['tax_rates'] = [$rateId];
            }
            return $line;
        }, $items);

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

        // Fallback only — never in addition to tax_rates, so tax is applied once.
        if ($taxAmount > 0.0 && !$applyTaxRate) {
            $lineItems[] = self::salesTaxLine($taxAmount);
        }

        return $lineItems;
    }

    /**
     * Build the fallback flat "Sales Tax" product line for a dollar tax amount.
     *
     * Used only when no Stripe Tax Rate object is configured; Stripe treats this
     * as an ordinary product and will not report it as tax.
     *
     * @param float $taxAmount Tax in dollars (> 0).
     * @return array<string, mixed> A single Stripe line_item param.
     */
    private static function salesTaxLine(float $taxAmount): array
    {
        return [
            'price_data' => [
                'currency'     => 'usd',
                'unit_amount'  => (int) round($taxAmount * 100),
                'product_data' => ['name' => 'Sales Tax'],
            ],
            'quantity' => 1,
        ];
    }
}
