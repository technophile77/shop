<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds a self-contained JSON-serialisable snapshot of cart items for shop orders.
 *
 * Embeds resolved human-readable names (flower type, chosen color names, paper
 * color name, add-on names + custom_text) so the florist's order record remains
 * meaningful even after catalog rows are edited or deleted. All name lookups
 * degrade gracefully when an ID is not present in the supplied name maps.
 *
 * No database access, no session access, no side effects.
 *
 * @see \App\Support\Cart          Source of the canonical line-item shape.
 * @see \App\Support\CartPricing   Provides the lineTotal helper used for snapshots.
 * @see \App\Controllers\CheckoutController  Primary consumer.
 */
final class ShopOrderSnapshot
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Build a JSON-serialisable items snapshot with resolved names.
     *
     * Each output element contains all details the florist needs to fulfil the
     * order independently of the live catalog: product name, paper color name,
     * each flower-type/color selection with human-readable names, all add-ons
     * with names and custom text, qty, unit_price, and the computed line_total.
     *
     * When an ID is present in the line item but absent from the name map the
     * field is returned as null, which the florist view should treat as "unknown".
     *
     * @param array[]  $items            Canonical cart line-item arrays from CartSession::items().
     * @param string[] $flowerTypeNames  Map of flower_type_id (int key) → name string.
     * @param string[] $flowerColorNames Map of color_id (int key) → name string.
     * @param string[] $paperColorNames  Map of paper_color_id (int key) → name string.
     * @param string   $lang             'en' or 'es' — selects which name field to snapshot.
     *
     * @return list<array<string, mixed>> JSON-serialisable snapshot array.
     *
     * @example
     *   $snapshot = ShopOrderSnapshot::itemsSnapshot(
     *       CartSession::items(),
     *       [1 => 'Rose', 2 => 'Lily'],
     *       [1 => 'Red', 2 => 'White', 3 => 'Pink'],
     *       [1 => 'White kraft', 2 => 'Black'],
     *       'en'
     *   );
     */
    public static function itemsSnapshot(
        array $items,
        array $flowerTypeNames,
        array $flowerColorNames,
        array $paperColorNames,
        string $lang = 'en',
    ): array {
        $nameKey = $lang === 'es' ? 'name_es' : 'name_en';
        $out     = [];

        foreach ($items as $item) {
            $paperColorId   = isset($item['paper_color_id']) ? (int) $item['paper_color_id'] : null;
            $paperColorName = $paperColorId !== null ? ($paperColorNames[$paperColorId] ?? null) : null;

            // Resolve color selections.
            $colorSelections = [];
            foreach ((array) ($item['colors'] ?? []) as $colorGroup) {
                $typeId    = (int) ($colorGroup['flower_type_id'] ?? 0);
                $typeName  = $flowerTypeNames[$typeId] ?? null;
                $colorIds  = (array) ($colorGroup['color_ids'] ?? []);
                $colorNames = array_map(
                    static fn (int $cid): ?string => $flowerColorNames[$cid] ?? null,
                    array_map('intval', $colorIds)
                );
                $colorSelections[] = [
                    'flower_type_id'   => $typeId,
                    'flower_type_name' => $typeName,
                    'color_ids'        => $colorIds,
                    'color_names'      => $colorNames,
                    'mixed'            => (bool) ($colorGroup['mixed'] ?? false),
                ];
            }

            // Resolve add-ons.
            $addons = [];
            foreach ((array) ($item['addons'] ?? []) as $addon) {
                $addonNameEn = (string) ($addon['name_en'] ?? '');
                $addonNameEs = isset($addon['name_es']) ? (string) $addon['name_es'] : null;
                $addonName   = ($lang === 'es' && $addonNameEs !== null && $addonNameEs !== '')
                    ? $addonNameEs
                    : $addonNameEn;

                $addons[] = [
                    'addon_id'    => (int) ($addon['addon_id'] ?? 0),
                    'name'        => $addonName,
                    'name_en'     => $addonNameEn,
                    'name_es'     => $addonNameEs,
                    'price'       => (float) ($addon['price'] ?? 0.0),
                    'quantity'    => max(1, (int) ($addon['quantity'] ?? 1)),
                    'custom_text' => isset($addon['custom_text']) ? (string) $addon['custom_text'] : null,
                ];
            }

            $unitPrice = (float) ($item['unit_price'] ?? 0.0);
            $qty       = max(1, (int) ($item['qty'] ?? 1));
            $lineTotal = CartPricing::lineTotal($item);

            $out[] = [
                'product_id'       => (int) ($item['product_id'] ?? 0),
                'name'             => (string) ($item[$nameKey] ?? $item['name_en'] ?? ''),
                'name_en'          => (string) ($item['name_en'] ?? ''),
                'name_es'          => isset($item['name_es']) ? (string) $item['name_es'] : null,
                'image_path'       => isset($item['image_path']) ? (string) $item['image_path'] : null,
                'unit_price'       => $unitPrice,
                'flower_count'     => isset($item['flower_count']) ? (int) $item['flower_count'] : null,
                'qty'              => $qty,
                'paper_color_id'   => $paperColorId,
                'paper_color_name' => $paperColorName,
                'colors'           => $colorSelections,
                'addons'           => $addons,
                'line_total'       => $lineTotal,
            ];
        }

        return $out;
    }
}
