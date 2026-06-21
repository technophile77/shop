<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure mapper from a `products` table row to a Google Merchant API v1 productInput.
 *
 * Centralises the translation between this project's product schema (see
 * {@see \App\Models\Product}) and the array shape the Google Merchant API
 * (Content / Merchant API v1) expects for a single productInput, plus an
 * eligibility predicate that decides whether a product belongs in the feed at
 * all. Keeping this logic in one pure class means there is exactly one place to
 * adjust when Google's field names are finalised against their docs, and the
 * mapping is trivially unit-testable with no database, network, or clock.
 *
 * One offerId is emitted per product (`prod-{id}`), stable across languages; a
 * separate productInput is produced per content language (en / es) so a product
 * appears once per locale in the feed.
 *
 * No database access, no HTTP client, no SDK instantiation — this is a pure
 * transform: row + config in, array out.
 *
 * @see \App\Models\Product          Source row shape consumed here.
 * @see \App\Support\Slug            Builds the canonical id-anchored URL ref.
 * @see \App\Support\StripeLineItems Sibling pure mapper this mirrors in style.
 */
final class MerchantProduct
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Decide whether a product row should be synced to Google Merchant Center.
     *
     * A product is eligible iff it is active AND carries a positive starting
     * price. Quote-only products (no price) and inactive / soft-deleted products
     * are excluded so the feed never advertises something that cannot be bought
     * at a stated price.
     *
     * @param array<string, mixed> $product Product row with at least 'active' and 'price_from'.
     *
     * @return bool True when the product is active and priced; false otherwise.
     *
     * @example
     *   MerchantProduct::eligible(['active' => 1, 'price_from' => 45.00]); // true
     *   MerchantProduct::eligible(['active' => 1, 'price_from' => 0]);     // false (no price)
     *   MerchantProduct::eligible(['active' => 0, 'price_from' => 45.00]); // false (inactive)
     */
    public static function eligible(array $product): bool
    {
        $isActive = (int) ($product['active'] ?? 0) === 1;
        $isPriced = (float) ($product['price_from'] ?? 0) > 0;

        return $isActive && $isPriced;
    }

    /**
     * Map a product row into a Google Merchant API v1 productInput array.
     *
     * The `$cfg` array carries everything that is NOT on the product row, so the
     * transform stays pure and the caller owns all environment access:
     *  - `app_url`          (string) Public site base URL, e.g. 'https://flowers.cresswell.org'.
     *                       Trailing slashes are trimmed before composing links.
     *  - `brand`            (string) Brand name used for the `brand` attribute and the
     *                       generated description fallback.
     *  - `google_category`  (string) The Google product taxonomy value for
     *                       `googleProductCategory`, e.g. 'Home & Garden > Decor > Flowers'.
     *  - `placeholder_image`(string) Absolute URL used for `imageLink` when the product
     *                       has no `image_path`.
     *
     * Returned shape (associative array; identity fields top-level, descriptive
     * attributes nested under `productAttributes`):
     * ```
     * [
     *   'offerId'         => 'prod-{id}',     // stable across languages
     *   'contentLanguage' => 'en' | 'es',
     *   'feedLabel'       => 'US',
     *   'productAttributes' => [
     *      'title'        => string,
     *      'description'  => string,
     *      'link'         => string,         // {app_url}/{lang}/flowers/{slug-id}
     *      'imageLink'    => string,         // product image or placeholder
     *      'availability' => 'IN_STOCK',
     *      'condition'    => 'new',
     *      'brand'        => string,
     *      'googleProductCategory' => string,
     *      'identifierExists'      => false, // no GTIN/MPN for handcrafted bouquets
     *      'price'        => ['amountMicros' => string, 'currencyCode' => 'USD'],
     *   ],
     * ]
     * ```
     *
     * Price uses `price_from` even when a `price_to` range exists; `amountMicros`
     * is the integer micros (dollars × 1,000,000) rendered as a string per the
     * Merchant API money format.
     *
     * @param array<string, mixed> $product Product row (see {@see \App\Models\Product}).
     * @param string               $lang    Content language: 'en' or 'es'.
     * @param array{app_url: string, brand: string, google_category: string, placeholder_image: string} $cfg
     *        Non-row configuration; see the key descriptions above.
     *
     * @return array<string, mixed> A Merchant API v1 productInput array (shape above).
     *
     * @example
     *   $cfg = [
     *       'app_url'           => 'https://flowers.cresswell.org',
     *       'brand'             => "Perla's Flowers",
     *       'google_category'   => 'Home & Garden > Decor > Flowers',
     *       'placeholder_image' => 'https://flowers.cresswell.org/public/img/placeholder.jpg',
     *   ];
     *   $row = [
     *       'id' => 12, 'name_en' => 'Whisper of Love', 'name_es' => 'Susurro de Amor',
     *       'description_en' => 'A dozen red roses.', 'price_from' => 45.00,
     *       'image_path' => 'abc123.jpg', 'active' => 1,
     *   ];
     *   MerchantProduct::toProductInput($row, 'en', $cfg);
     *   // [
     *   //   'offerId' => 'prod-12', 'contentLanguage' => 'en',
     *   //   'feedLabel' => 'US',
     *   //   'productAttributes' => [
     *   //     'title' => 'Whisper of Love', 'description' => 'A dozen red roses.',
     *   //     'link' => 'https://flowers.cresswell.org/en/flowers/whisper-of-love-12',
     *   //     'imageLink' => 'https://flowers.cresswell.org/public/uploads/products/abc123.jpg',
     *   //     'availability' => 'IN_STOCK', 'condition' => 'new', 'brand' => "Perla's Flowers",
     *   //     'googleProductCategory' => 'Home & Garden > Decor > Flowers',
     *   //     'identifierExists' => false,
     *   //     'price' => ['amountMicros' => '45000000', 'currencyCode' => 'USD'],
     *   //   ],
     *   // ]
     */
    public static function toProductInput(array $product, string $lang, array $cfg): array
    {
        $appUrl = rtrim((string) ($cfg['app_url'] ?? ''), '/');
        $brand  = (string) ($cfg['brand'] ?? '');

        $title       = self::localized($product, 'name', $lang);
        $description = self::description($product, $lang, $title, $brand);

        $imagePath = trim((string) ($product['image_path'] ?? ''));
        $imageLink = $imagePath !== ''
            ? $appUrl . '/public/uploads/products/' . $imagePath
            : (string) ($cfg['placeholder_image'] ?? '');

        $price = round(((float) ($product['price_from'] ?? 0)) * 1_000_000);

        return [
            'offerId'         => 'prod-' . (int) ($product['id'] ?? 0),
            'contentLanguage' => $lang,
            'feedLabel'       => 'US',
            'productAttributes' => [
                'title'                 => $title,
                'description'           => $description,
                'link'                  => $appUrl . '/' . $lang . '/flowers/' . Slug::productRef($product),
                'imageLink'             => $imageLink,
                'availability'          => 'IN_STOCK',
                'condition'             => 'new',
                'brand'                 => $brand,
                'googleProductCategory' => (string) ($cfg['google_category'] ?? ''),
                'identifierExists'      => false,
                'price'                 => [
                    'amountMicros' => (string) $price,
                    'currencyCode' => 'USD',
                ],
            ],
        ];
    }

    /**
     * Return a localized field value, falling back to the English variant.
     *
     * Reads `{$field}_{$lang}`; when that is missing or blank it falls back to
     * `{$field}_en`. Used for both the title (`name`) and description.
     *
     * @param array<string, mixed> $product Product row.
     * @param string               $field   Field stem, e.g. 'name' or 'description'.
     * @param string               $lang    Requested language ('en' | 'es').
     *
     * @return string The localized value, or the English fallback, or '' when neither exists.
     *
     * @example
     *   self::localized(['name_en' => 'Roses', 'name_es' => 'Rosas'], 'name', 'es'); // 'Rosas'
     *   self::localized(['name_en' => 'Roses'], 'name', 'es');                        // 'Roses'
     */
    private static function localized(array $product, string $field, string $lang): string
    {
        $value = trim((string) ($product[$field . '_' . $lang] ?? ''));
        if ($value !== '') {
            return $value;
        }

        return trim((string) ($product[$field . '_en'] ?? ''));
    }

    /**
     * Resolve the product description with a generated final fallback.
     *
     * Prefers the localized description (`description_{lang}` → `description_en`);
     * when both are blank it synthesises a short blurb from the title and brand so
     * the feed never ships an empty description.
     *
     * @param array<string, mixed> $product Product row.
     * @param string               $lang    Requested language ('en' | 'es').
     * @param string               $title   Already-resolved product title (used in the blurb).
     * @param string               $brand   Brand name (used in the blurb).
     *
     * @return string A non-empty description when a title is present.
     *
     * @example
     *   self::description(['description_en' => 'A dozen roses.'], 'en', 'Roses', 'Perla');
     *   // 'A dozen roses.'
     *   self::description([], 'en', 'Roses', 'Perla');
     *   // 'Roses — handcrafted by Perla.'
     */
    private static function description(array $product, string $lang, string $title, string $brand): string
    {
        $description = self::localized($product, 'description', $lang);
        if ($description !== '') {
            return $description;
        }

        return $title . ' — handcrafted by ' . $brand . '.';
    }
}
