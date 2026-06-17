<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free operations on a cart items array.
 *
 * The cart is represented as a plain PHP array of line-item arrays. None of
 * these methods read or write session state — that delegation lives in
 * {@see \App\Support\CartSession}. Keeping mutation logic here enables fast
 * unit testing without a session or database.
 *
 * Canonical line-item shape:
 * <pre>
 * [
 *   'signature'      => string,   // stable merge key
 *   'product_id'     => int,
 *   'name_en'        => string,
 *   'name_es'        => ?string,
 *   'image_path'     => ?string,
 *   'unit_price'     => float,
 *   'flower_count'   => ?int,
 *   'qty'            => int,
 *   'paper_color_id' => ?int,
 *   'colors'         => [ ['flower_type_id'=>int, 'color_ids'=>int[], 'mixed'=>bool], ... ],
 *   'addons'         => [ ['addon_id'=>int, 'name_en'=>string, 'name_es'=>?string, 'price'=>float, 'custom_text'=>?string], ... ],
 * ]
 * </pre>
 *
 * @see \App\Support\CartSession  Thin session wrapper that delegates to this class.
 * @see \App\Support\CartPricing  Pure pricing helpers that consume items arrays.
 * @see \App\Support\CartValidation  Validates a selection before it is added.
 */
final class Cart
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Compute a deterministic signature for a line item.
     *
     * The signature depends only on the configurable selection — not on qty,
     * snapshot names, or pricing. Two add operations that represent identical
     * customer choices (same product, paper, colors, and add-ons including
     * custom text) produce the same signature and therefore merge in the cart.
     *
     * Sort order:
     *  - colors: sorted by flower_type_id; within each type, color_ids are sorted.
     *  - addons: sorted by addon_id.
     *
     * @param array $line Partial or complete line-item array.  Only these keys
     *                    are consumed: product_id, paper_color_id, colors, addons.
     *
     * @return string 64-character hex SHA-256 hash.
     *
     * @example
     *   $sig = Cart::signature([
     *       'product_id'     => 5,
     *       'paper_color_id' => 2,
     *       'colors'         => [['flower_type_id'=>1,'color_ids'=>[3,1],'mixed'=>true]],
     *       'addons'         => [['addon_id'=>2,'custom_text'=>'Happy Birthday']],
     *   ]);
     */
    public static function signature(array $line): string
    {
        $productId    = (int) ($line['product_id'] ?? 0);
        $paperColorId = isset($line['paper_color_id']) ? (int) $line['paper_color_id'] : null;

        // Normalise colors: sort by flower_type_id, then sort each color_ids list.
        $colors = [];
        foreach ((array) ($line['colors'] ?? []) as $c) {
            $typeId   = (int) ($c['flower_type_id'] ?? 0);
            $colorIds = (array) ($c['color_ids'] ?? []);
            sort($colorIds);
            $colors[$typeId] = ['color_ids' => $colorIds, 'mixed' => (bool) ($c['mixed'] ?? false)];
        }
        ksort($colors);

        // Normalise addons: sort by addon_id; quantity + custom text are part of
        // the line identity (chocolates ×2 and ×3 are distinct lines).
        $addons = [];
        foreach ((array) ($line['addons'] ?? []) as $a) {
            $addonId = (int) ($a['addon_id'] ?? 0);
            $addons[$addonId] = [
                'custom_text' => $a['custom_text'] ?? null,
                'quantity'    => max(1, (int) ($a['quantity'] ?? 1)),
            ];
        }
        ksort($addons);

        $payload = json_encode([
            'product_id'     => $productId,
            'paper_color_id' => $paperColorId,
            'colors'         => $colors,
            'addons'         => $addons,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    /**
     * Add a line item to the cart, merging if an identical configuration exists.
     *
     * Sets $line['signature'] before comparing. If an existing line with the
     * same signature is found, its qty is incremented by $line['qty']; otherwise
     * the new line is appended.
     *
     * The input $items array is never mutated — a new array is returned.
     *
     * @param array[] $items Existing cart items.
     * @param array   $line  New line item to add.  Must include at least
     *                       product_id, qty, and the configurable selection keys.
     *
     * @return array[] Updated items list.
     *
     * @example
     *   $items = Cart::add([], $line);           // first add
     *   $items = Cart::add($items, $sameLine);   // qty merges
     *   $items = Cart::add($items, $diffLine);   // separate line appended
     */
    public static function add(array $items, array $line): array
    {
        $sig           = self::signature($line);
        $line['signature'] = $sig;

        $result = [];
        $merged = false;

        foreach ($items as $existing) {
            if (!$merged && ($existing['signature'] ?? '') === $sig) {
                $existing['qty'] = ((int) ($existing['qty'] ?? 0)) + ((int) ($line['qty'] ?? 1));
                $merged          = true;
            }
            $result[] = $existing;
        }

        if (!$merged) {
            $result[] = $line;
        }

        return $result;
    }

    /**
     * Update the quantity of a line item identified by its signature.
     *
     * When $qty ≤ 0 the line is removed from the cart (equivalent to
     * {@see remove()}). The input array is not mutated.
     *
     * @param array[] $items     Existing cart items.
     * @param string  $signature The line item's signature (from Cart::signature()).
     * @param int     $qty       New quantity.  ≤ 0 removes the line.
     *
     * @return array[] Updated items list.
     *
     * @example
     *   $items = Cart::updateQty($items, $sig, 3);   // set qty to 3
     *   $items = Cart::updateQty($items, $sig, 0);   // remove line
     */
    public static function updateQty(array $items, string $signature, int $qty): array
    {
        if ($qty <= 0) {
            return self::remove($items, $signature);
        }

        return array_map(static function (array $item) use ($signature, $qty): array {
            if (($item['signature'] ?? '') === $signature) {
                $item['qty'] = $qty;
            }
            return $item;
        }, $items);
    }

    /**
     * Remove a line item by signature.
     *
     * Returns a re-indexed array without the matching line.
     * No-ops silently when the signature is not found.
     *
     * @param array[] $items     Existing cart items.
     * @param string  $signature Signature of the line to remove.
     *
     * @return array[] Updated items list.
     *
     * @example
     *   $items = Cart::remove($items, $sig);
     */
    public static function remove(array $items, string $signature): array
    {
        return array_values(
            array_filter($items, static fn (array $item): bool =>
                ($item['signature'] ?? '') !== $signature
            )
        );
    }

    /**
     * Return the total number of individual units across all line items.
     *
     * Sums the qty field of every line. Useful for displaying a badge count in
     * the navigation bar.
     *
     * @param array[] $items Cart items.
     *
     * @return int Total item count (0 when the cart is empty).
     *
     * @example
     *   Cart::totalQuantity([]);                    // 0
     *   Cart::totalQuantity([['qty'=>2],['qty'=>3]]); // 5
     */
    public static function totalQuantity(array $items): int
    {
        return array_reduce($items, static fn (int $carry, array $item): int =>
            $carry + (int) ($item['qty'] ?? 0),
            0
        );
    }
}
