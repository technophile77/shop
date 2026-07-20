<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free mapper from a bouquet request into a draft quote.
 *
 * The public "Request a Custom Bouquet" form captures structured data — an
 * arrangement style, a budget range, selected add-ons, the occasion, colours,
 * delivery details, and free-text notes — but no per-item prices. This helper
 * turns that data into the line items and notes that {@see \App\Models\Quote::create()}
 * expects, so the owner gets a pre-filled draft to review instead of re-typing
 * everything by hand.
 *
 * The bouquet itself has no quoted price, so it is seeded at the midpoint of the
 * customer's stated budget range (the owner adjusts before sending). Add-ons are
 * priced from the catalogue and passed in already resolved, keeping every method
 * here free of database access, session access, or other side effects.
 *
 * @see \App\Controllers\OrderController  Primary consumer (on form submission).
 * @see \App\Models\Quote::create()       Consumes lineItems() and notes() output.
 */
final class QuoteDraft
{
    /** Description used for the main bouquet line when no style was supplied. */
    private const DEFAULT_BOUQUET_DESCRIPTION = 'Custom bouquet';

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Derive a starting unit price for the bouquet from a budget-range label.
     *
     * The form's budget options (see views/public/order.php) are free-text
     * labels rather than numbers, so the rule set keys off how many dollar
     * amounts the label contains and a couple of keywords:
     *   - Two amounts ("$100–$200")  → midpoint (A + B) / 2.
     *   - One amount, "under" ("Under $50") → half the cap, B / 2.
     *   - One amount, otherwise ("$350+")   → that amount, A.
     *   - No amounts ("Custom", "", unparseable) → 0.0 (owner sets the price).
     * The en-dash (–) used by the form between two amounts is handled by simply
     * extracting every numeric group regardless of the separator.
     *
     * @param string|null $budgetRange The budget label as stored on the order,
     *                                  e.g. '$100–$200', 'Under $50', '$350+'.
     *
     * @return float Suggested bouquet unit price in dollars, rounded to cents;
     *               0.0 when no amount can be derived.
     *
     * @example
     *   QuoteDraft::budgetMidpoint('$100–$200'); // 150.0
     *   QuoteDraft::budgetMidpoint('Under $50');  // 25.0
     *   QuoteDraft::budgetMidpoint('$350+');      // 350.0
     *   QuoteDraft::budgetMidpoint('Custom');     // 0.0
     */
    public static function budgetMidpoint(?string $budgetRange): float
    {
        $label = (string) $budgetRange;

        preg_match_all('/\d+(?:\.\d+)?/', $label, $matches);
        $amounts = array_map('floatval', $matches[0]);

        if (count($amounts) >= 2) {
            return round(($amounts[0] + $amounts[1]) / 2, 2);
        }

        if (count($amounts) === 1) {
            return stripos($label, 'under') !== false
                ? round($amounts[0] / 2, 2)
                : round($amounts[0], 2);
        }

        return 0.0;
    }

    /**
     * Build the quote line items from the request's bouquet and add-on data.
     *
     * Produces one line for the bouquet (priced at the budget midpoint) followed
     * by one line per add-on at its catalogue price, in the shape
     * {@see \App\Models\Quote::create()} expects: a list of
     * `['description' => string, 'qty' => int, 'unit_price' => float]`.
     *
     * @param array{arrangement_style?: ?string, budget_range?: ?string} $fields
     *        The bouquet fields from the order. A blank or missing
     *        arrangement_style falls back to "Custom bouquet".
     * @param list<array{name: string, price: float}> $addons
     *        Add-ons already resolved to a display name and catalogue price by
     *        the caller (kept out of this method to preserve purity).
     *
     * @return list<array{description: string, qty: int, unit_price: float}>
     *         Always at least one item (the bouquet), then one per add-on.
     *
     * @example
     *   QuoteDraft::lineItems(
     *       ['arrangement_style' => 'Money bouquet', 'budget_range' => '$100–$200'],
     *       [['name' => 'Crown', 'price' => 15.0]],
     *   );
     *   // [
     *   //   ['description' => 'Money bouquet', 'qty' => 1, 'unit_price' => 150.0],
     *   //   ['description' => 'Crown',         'qty' => 1, 'unit_price' => 15.0],
     *   // ]
     */
    public static function lineItems(array $fields, array $addons): array
    {
        $style = trim((string) ($fields['arrangement_style'] ?? ''));

        $items = [[
            'description' => $style !== '' ? $style : self::DEFAULT_BOUQUET_DESCRIPTION,
            'qty'         => 1,
            'unit_price'  => self::budgetMidpoint($fields['budget_range'] ?? null),
        ]];

        foreach ($addons as $addon) {
            $items[] = [
                'description' => (string) $addon['name'],
                'qty'         => 1,
                'unit_price'  => round((float) $addon['price'], 2),
            ];
        }

        return $items;
    }

    /**
     * Assemble the quote's notes from the request fields that aren't line items.
     *
     * Occasion, colour preferences, a caller-formatted delivery summary, and the
     * customer's own notes don't map to priced lines, so they are collected here
     * as labelled lines for the owner's reference on the draft. Empty values are
     * omitted, and an all-empty input yields an empty string.
     *
     * The delivery *fee* is no longer part of $deliveryLine — it is persisted as
     * its own structured, untaxed `delivery_fee` field on the quote (see
     * {@see \App\Models\Quote::create()}), so the caller passes only the address
     * summary here. This method stays a pure text formatter regardless of what
     * the caller includes; it does not itself decide what belongs in the note.
     *
     * @param string|null $occasion      Occasion label, e.g. 'Quinceañera'.
     * @param string|null $colors        Colour preferences, e.g. 'Pink and white'.
     * @param string|null $customerNotes The customer's free-text notes.
     * @param string      $deliveryLine  Pre-formatted delivery/pickup summary, or
     *                                    '' to omit. Built by the caller — since
     *                                    the fee now has its own field, this is
     *                                    ordinarily just the address.
     *
     * @return string Newline-joined notes, or '' when nothing was supplied.
     *
     * @example
     *   QuoteDraft::notes('Quinceañera', 'Pink and white', 'Surprise gift', 'Delivery: 123 Main St');
     *   // "Occasion: Quinceañera\nColors: Pink and white\nDelivery: 123 Main St\nCustomer notes: Surprise gift"
     */
    public static function notes(
        ?string $occasion,
        ?string $colors,
        ?string $customerNotes,
        string $deliveryLine = '',
    ): string {
        $lines = [];

        if (trim((string) $occasion) !== '') {
            $lines[] = 'Occasion: ' . trim((string) $occasion);
        }
        if (trim((string) $colors) !== '') {
            $lines[] = 'Colors: ' . trim((string) $colors);
        }
        if (trim($deliveryLine) !== '') {
            $lines[] = trim($deliveryLine);
        }
        if (trim((string) $customerNotes) !== '') {
            $lines[] = 'Customer notes: ' . trim((string) $customerNotes);
        }

        return implode("\n", $lines);
    }
}
