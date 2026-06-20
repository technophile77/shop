<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FlowerColor;
use App\Models\FlowerType;
use App\Models\FlowerTypeColor;
use App\Models\ProductFlowerType;
use App\Models\ProductFlowerTypeColor;
use App\Support\FlowerColorResolver;
use App\Support\Shop;

/**
 * Resolves the per-product flower-color options for the add-to-cart panel.
 *
 * For a set of products, builds the map of which flower colors each buyable
 * bouquet can be made in, by combining the product's flower types with the
 * global type/color catalogs. Centralizes the catalog-preload + per-product
 * loop that the occasion grid, the product detail page, and the birthday city
 * landing page all need, so they share one source of truth.
 *
 * Unlike the pure {@see \App\Support\Shop} helpers, this performs DB reads
 * (it loads the active flower-type, flower-color, and type/color catalogs and
 * per-product joins), so it belongs in Services rather than Support.
 *
 * @see \App\Support\FlowerColorResolver::availableColorsForProduct()
 * @see \App\Controllers\ShopController::occasion()
 * @see \App\Controllers\ShopController::product()
 * @see \App\Controllers\LocalAreaController::renderService()
 */
final class BouquetColorOptions
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Build the per-product color-options map for the given products.
     *
     * Pre-loads the active flower-type, flower-color, and flower-type/color
     * catalogs once, then resolves the available colors for each buyable product.
     * Non-buyable products ({@see \App\Support\Shop::isBuyable()}) are skipped, so
     * they never appear as keys in the result.
     *
     * @param array<int, array<string, mixed>> $products Product rows; each must
     *        carry an 'id' and the price/active fields Shop::isBuyable() reads.
     *
     * @return array<int, mixed> Map of product id => available color options
     *         (the shape returned by FlowerColorResolver::availableColorsForProduct()),
     *         including only buyable products.
     *
     * @example
     *   $products = Product::byOccasion('birthday');
     *   $options  = BouquetColorOptions::forProducts($products);
     *   // $options[12] === [ ...color options for buyable product #12... ]
     *   // (non-buyable products are absent from the map)
     */
    public static function forProducts(array $products): array
    {
        $flowerTypesById = [];
        foreach (FlowerType::allActive() as $_ft) {
            $flowerTypesById[(int) $_ft['id']] = $_ft;
        }
        $flowerColorsById = [];
        foreach (FlowerColor::allActive() as $_fc) {
            $flowerColorsById[(int) $_fc['id']] = $_fc;
        }
        $flowerTypeColorMap = FlowerTypeColor::map();

        $productColorOptions = [];
        foreach ($products as $_p) {
            if (!Shop::isBuyable($_p)) {
                continue;
            }
            $pid = (int) $_p['id'];
            $productColorOptions[$pid] = FlowerColorResolver::availableColorsForProduct(
                ProductFlowerType::flowerTypeIdsForProduct($pid),
                $flowerTypesById,
                $flowerTypeColorMap,
                $flowerColorsById,
                ProductFlowerTypeColor::mapForProduct($pid)
            );
        }

        return $productColorOptions;
    }
}
