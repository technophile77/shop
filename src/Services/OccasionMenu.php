<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Occasion;
use App\Models\Product;
use App\Support\Shop;

/**
 * Builds the "Shop by Occasion" tile list (hub page + homepage row).
 *
 * Aggregates the active occasions with their localized heading
 * ({@see \App\Support\Shop::occasionCopyFromRow()}) and a representative image
 * (the first tagged product's photo, {@see \App\Models\Product::firstImageByOccasion()}).
 * DB-backed read aggregator — kept out of the controllers so the hub and the
 * homepage share one source of truth.
 *
 * @see \App\Controllers\ShopController::occasions()
 * @see \App\Controllers\HomeController::index()
 */
final class OccasionMenu
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Build occasion tiles for the given language.
     *
     * @param string $lang Locale code: 'en' or 'es' (anything else treated as 'en').
     *
     * @return list<array{slug: string, heading: string, image: string, url: string}>
     *         One tile per active occasion; image falls back to the placeholder.
     *
     * @example
     *   OccasionMenu::tiles('en');
     *   // [['slug'=>'birthday','heading'=>'Birthday Flowers','image'=>'/public/uploads/products/x.jpg','url'=>'/en/flowers/occasion/birthday'], …]
     */
    public static function tiles(string $lang): array
    {
        $langPrefix = $lang === 'es' ? 'es' : 'en';
        $tiles      = [];

        foreach (Occasion::allActive() as $occasion) {
            $slug = (string) ($occasion['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $image = Product::firstImageByOccasion($slug);

            $tiles[] = [
                'slug'    => $slug,
                'heading' => Shop::occasionCopyFromRow($occasion, $lang)['heading'],
                'image'   => $image !== null
                    ? '/public/uploads/products/' . $image
                    : '/public/assets/images/placeholder-flower.jpg',
                'url'     => '/' . $langPrefix . '/flowers/occasion/' . $slug,
            ];
        }

        return $tiles;
    }
}
