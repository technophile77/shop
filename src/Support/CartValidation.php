<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free validation for an add-to-cart selection.
 *
 * All catalog context is passed in as pre-loaded arrays so this class has no
 * database dependency and can be exercised fully by unit tests with in-memory
 * fixtures.
 *
 * Validation rules (all enforced by validateSelection()):
 *  - qty must be ≥ 1.
 *  - Each colors entry must reference a flower_type_id in productFlowerTypeIds.
 *  - Every color_id in a colors entry must appear in that type's valid color set.
 *  - Every colors entry must contain ≥ 1 color_id.
 *  - When mixed = true the entry must have ≥ 2 color_ids; when false exactly 1.
 *  - paper_color_id (when provided) must exist in paperColorIds.
 *  - Every addon_id must exist in addonsById.
 *  - When an addon has_custom_text and custom_text is provided, its length must
 *    not exceed ribbonCharLimit(flowerCount).
 *  - custom_text on an addon without has_custom_text is rejected.
 *
 * @see \App\Support\Ribbon::ribbonCharLimit()   Provides the ribbon text limit.
 * @see \App\Controllers\CartController::add()   Primary consumer.
 */
final class CartValidation
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Validate a customer's add-to-cart selection against catalog context.
     *
     * Returns a list of human-readable error strings. An empty list means the
     * selection is valid and safe to convert into a cart line item.
     *
     * @param array $selection Raw chosen options:
     *   <pre>
     *   [
     *     'product_id'     => int,
     *     'qty'            => int,
     *     'paper_color_id' => int|null,
     *     'colors'         => [
     *         ['flower_type_id' => int, 'color_ids' => int[], 'mixed' => bool],
     *         ...
     *     ],
     *     'addons'         => [
     *         ['addon_id' => int, 'custom_text' => string|null],
     *         ...
     *     ],
     *   ]
     *   </pre>
     *
     * @param array $context Catalog data needed for validation:
     *   <pre>
     *   [
     *     'flowerTypeColorMap'     => array<int, int[]>,  // type_id => valid color_ids
     *     'productFlowerTypeIds'   => int[],              // type IDs the product uses
     *     'paperColorIds'          => int[],              // valid paper color IDs
     *     'addonsById'             => array<int, array>,  // id => addon row with has_custom_text
     *     'flowerCount'            => int,                // stem count for ribbon limit
     *   ]
     *   </pre>
     *
     * @return string[] List of error messages; empty when selection is valid.
     *
     * @example
     *   $errors = CartValidation::validateSelection($selection, $context);
     *   if ($errors !== []) {
     *       // flash $errors[0] and redirect back
     *   }
     */
    public static function validateSelection(array $selection, array $context): array
    {
        $errors = [];

        // ── qty ──────────────────────────────────────────────────────────────
        $qty = (int) ($selection['qty'] ?? 0);
        if ($qty < 1) {
            $errors[] = 'Quantity must be at least 1.';
        }

        // ── context extraction ────────────────────────────────────────────────
        $validTypeIds      = (array) ($context['productFlowerTypeIds'] ?? []);
        $typeColorMap      = (array) ($context['flowerTypeColorMap'] ?? []);
        $validPaperIds     = (array) ($context['paperColorIds'] ?? []);
        $addonsById        = (array) ($context['addonsById'] ?? []);
        $flowerCount       = (int)   ($context['flowerCount'] ?? 0);

        // ── colors ────────────────────────────────────────────────────────────
        foreach ((array) ($selection['colors'] ?? []) as $colorEntry) {
            $typeId   = (int) ($colorEntry['flower_type_id'] ?? 0);
            $colorIds = (array) ($colorEntry['color_ids'] ?? []);
            $mixed    = (bool) ($colorEntry['mixed'] ?? false);

            if (!in_array($typeId, $validTypeIds, true)) {
                $errors[] = "Flower type ID {$typeId} is not valid for this product.";
                continue;
            }

            $validColorsForType = (array) ($typeColorMap[$typeId] ?? []);

            foreach ($colorIds as $colorId) {
                if (!in_array((int) $colorId, $validColorsForType, true)) {
                    $errors[] = "Color ID {$colorId} is not available for flower type {$typeId}.";
                }
            }

            if (count($colorIds) < 1) {
                $errors[] = "At least one color must be selected for each flower type.";
                continue;
            }

            if ($mixed && count($colorIds) < 2) {
                $errors[] = "Mixed color selection requires at least 2 colors.";
            }

            if (!$mixed && count($colorIds) !== 1) {
                $errors[] = "A non-mixed color selection must have exactly 1 color.";
            }
        }

        // ── paper color ───────────────────────────────────────────────────────
        $paperColorId = isset($selection['paper_color_id'])
            ? (int) $selection['paper_color_id']
            : null;

        if ($paperColorId !== null && $validPaperIds !== [] && !in_array($paperColorId, $validPaperIds, true)) {
            $errors[] = "Paper color ID {$paperColorId} is not available.";
        }

        // ── addons ────────────────────────────────────────────────────────────
        $ribbonLimit = Ribbon::ribbonCharLimit($flowerCount);

        foreach ((array) ($selection['addons'] ?? []) as $addonEntry) {
            $addonId    = (int) ($addonEntry['addon_id'] ?? 0);
            $customText = isset($addonEntry['custom_text'])
                ? (string) $addonEntry['custom_text']
                : null;

            if (!array_key_exists($addonId, $addonsById)) {
                $errors[] = "Add-on ID {$addonId} is not available.";
                continue;
            }

            $addon         = $addonsById[$addonId];
            $hasCustomText = (bool) ($addon['has_custom_text'] ?? false);

            if ($customText !== null && $customText !== '') {
                if (!$hasCustomText) {
                    $errors[] = "Custom text is not allowed for this add-on.";
                } elseif (mb_strlen($customText) > $ribbonLimit) {
                    $errors[] = "Ribbon text must be {$ribbonLimit} characters or fewer.";
                }
            }
        }

        return $errors;
    }
}
