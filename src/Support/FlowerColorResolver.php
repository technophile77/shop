<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free resolver for per-flower-type color availability.
 *
 * Computes which colors a customer can choose for each flower type in a product,
 * derives default selections based on the color shown in the product photo, and
 * flags when the default differs from the photo. No DB access — callers pre-load
 * all arrays and pass them in, enabling easy unit testing and avoiding N+1 queries.
 *
 * @see \App\Models\FlowerTypeColor::map()              Supplies the $flowerTypeColorMap.
 * @see \App\Models\ProductFlowerType::flowerTypeIdsForProduct()  Supplies $productFlowerTypeIds.
 */
final class FlowerColorResolver
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Compute selectable colors per flower type for a given product.
     *
     * For each flower type the product is tagged with, returns the colors
     * available for that type, the default selection (the product's pictured
     * colors for that type that are actually available — possibly several, e.g.
     * red + white roses), and a differs_from_photo flag.
     *
     * The default is the intersection of the type's pictured colors with its
     * available colors. When that intersection is empty (no pictured colors
     * recorded, or none of them are available) the default falls back to the
     * first available color and differs_from_photo is true. When a type has no
     * colors at all the default is an empty array.
     *
     * When a flower type ID is not found in $flowerTypesById the entry is
     * silently skipped.
     *
     * @param int[]  $productFlowerTypeIds IDs of flower types the product uses.
     * @param array  $flowerTypesById      Map of id => flower_type row.
     * @param array  $flowerTypeColorMap   Map of flower_type_id => int[] color_ids
     *                                     (from FlowerTypeColor::map()).
     * @param array  $flowerColorsById     Map of id => flower_color row.
     * @param array  $picturedColorsByType Map of flower_type_id => int[] pictured color_ids
     *                                     (from ProductFlowerTypeColor::mapForProduct()).
     *
     * @return array<int, array{
     *     flower_type_id: int,
     *     name_en: string,
     *     name_es: string|null,
     *     colors: array<int, array{id: int, name_en: string, name_es: string|null, hex: string|null}>,
     *     default_color_ids: int[],
     *     differs_from_photo: bool
     * }> One entry per flower type, empty array when product has no flower types.
     *
     * @example
     *   $result = FlowerColorResolver::availableColorsForProduct(
     *       [1],
     *       $flowerTypesById,
     *       $flowerTypeColorMap,
     *       $flowerColorsById,
     *       [1 => [3, 7]]   // pictured: red + white roses
     *   );
     *   // $result[0]['default_color_ids'] === [3, 7] if both are available for type 1
     *   // $result[0]['differs_from_photo'] === false in that case
     */
    public static function availableColorsForProduct(
        array $productFlowerTypeIds,
        array $flowerTypesById,
        array $flowerTypeColorMap,
        array $flowerColorsById,
        array $picturedColorsByType
    ): array {
        $result = [];

        foreach ($productFlowerTypeIds as $typeId) {
            $type = $flowerTypesById[$typeId] ?? null;
            if ($type === null) {
                continue;
            }

            $colorIds = $flowerTypeColorMap[$typeId] ?? [];
            $colors   = [];

            foreach ($colorIds as $colorId) {
                $color = $flowerColorsById[$colorId] ?? null;
                if ($color !== null) {
                    $colors[] = [
                        'id'      => (int) $color['id'],
                        'name_en' => (string) $color['name_en'],
                        'name_es' => isset($color['name_es']) ? (string) $color['name_es'] : null,
                        'hex'     => isset($color['hex']) ? (string) $color['hex'] : null,
                    ];
                }
            }

            // Default = the type's pictured colors that are actually available
            // (in available order); fall back to the first available color.
            $defaultColorIds  = [];
            $differsFromPhoto = false;

            if ($colors !== []) {
                $availableIds = array_column($colors, 'id');
                $pictured     = array_map('intval', (array) ($picturedColorsByType[$typeId] ?? []));
                $picturedAvailable = array_values(array_filter(
                    $availableIds,
                    static fn (int $id): bool => in_array($id, $pictured, true)
                ));

                if ($picturedAvailable !== []) {
                    $defaultColorIds  = $picturedAvailable;
                    $differsFromPhoto = false;
                } else {
                    $defaultColorIds  = [$colors[0]['id']];
                    $differsFromPhoto = true;
                }
            }

            $result[] = [
                'flower_type_id'     => (int) $typeId,
                'name_en'            => (string) ($type['name_en'] ?? ''),
                'name_es'            => isset($type['name_es']) ? (string) $type['name_es'] : null,
                'colors'             => $colors,
                'default_color_ids'  => $defaultColorIds,
                'differs_from_photo' => $differsFromPhoto,
            ];
        }

        return $result;
    }

    /**
     * Normalize a raw list of IDs: cast to int, drop zeros and negatives,
     * deduplicate, and reindex from 0.
     *
     * Useful for sanitising checkbox arrays from POST data before passing
     * to setForProduct() / setColorsForType().
     *
     * @param array $raw Raw values (strings, ints, or mixed).
     *
     * @return int[] Cleaned, unique, positive integer IDs.
     *
     * @example
     *   FlowerColorResolver::normalizeIdList(['3', '0', '-1', '3', '5']); // [3, 5]
     */
    public static function normalizeIdList(array $raw): array
    {
        $ids = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
