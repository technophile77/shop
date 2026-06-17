<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free helpers for the public shop feature.
 *
 * Contains no database access. All inputs are passed by the caller, making
 * every method trivially unit-testable with in-memory fixtures.
 *
 * Used by ShopController for buyability checks, price normalisation, and
 * per-occasion display copy.
 *
 * @see \App\Controllers\ShopController
 */
final class Shop
{
    /**
     * Map of occasion slug => per-language heading and blurb copy.
     *
     * Used by occasionCopy() to return display text without a database round-
     * trip. Extend this map whenever a new occasion slug is seeded.
     */
    /**
     * Virtual occasion "groups" that combine several real occasion slugs onto a
     * single public page. Keyed by the group slug; values are the member
     * occasion slugs whose products are unioned on that page.
     *
     * The hospital page shows both get-well and new-baby bouquets together.
     */
    private const OCCASION_GROUPS = [
        'hospital' => ['get-well', 'new-baby'],
    ];

    private const OCCASION_COPY = [
        'hospital' => [
            'en' => [
                'heading' => 'Hospital Flowers',
                'blurb'   => 'Get-well wishes and new-baby congratulations — bright, hand-crafted bouquets delivered to the hospital with care.',
            ],
            'es' => [
                'heading' => 'Flores para el Hospital',
                'blurb'   => 'Deseos de pronta recuperación y felicitaciones por el nuevo bebé — ramos alegres y hechos a mano, entregados al hospital con cariño.',
            ],
        ],
        'birthday' => [
            'en' => [
                'heading' => 'Birthday Flowers',
                'blurb'   => 'Make someone\'s birthday unforgettable with a vibrant, hand-crafted arrangement tailored just for them.',
            ],
            'es' => [
                'heading' => 'Flores de Cumpleaños',
                'blurb'   => 'Haz el cumpleaños de alguien inolvidable con un arreglo vibrante y hecho a mano especialmente para esa persona.',
            ],
        ],
        'get-well' => [
            'en' => [
                'heading' => 'Get Well Flowers',
                'blurb'   => 'Brighten someone\'s day and speed their recovery with a cheerful bouquet delivered with care.',
            ],
            'es' => [
                'heading' => 'Flores para Que te Mejores',
                'blurb'   => 'Alegra el día de alguien y ayuda a su recuperación con un ramo alegre entregado con cariño.',
            ],
        ],
        'new-baby' => [
            'en' => [
                'heading' => 'New Baby Flowers',
                'blurb'   => 'Welcome a new arrival with a fresh, beautiful arrangement that celebrates this joyful milestone.',
            ],
            'es' => [
                'heading' => 'Flores para Nuevo Bebé',
                'blurb'   => 'Da la bienvenida a un nuevo bebé con un arreglo fresco y hermoso que celebra este momento tan especial.',
            ],
        ],
        'sympathy' => [
            'en' => [
                'heading' => 'Sympathy Flowers',
                'blurb'   => 'Express your deepest condolences with a thoughtful, elegant arrangement crafted with compassion.',
            ],
            'es' => [
                'heading' => 'Flores de Condolencias',
                'blurb'   => 'Expresa tus más sentidas condolencias con un arreglo elegante y pensativo elaborado con compasión.',
            ],
        ],
    ];

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Determine whether a product can be purchased directly (add-to-cart flow).
     *
     * A product is buyable when it carries a positive price_from value. Products
     * with a null, absent, zero, or string "0.00" price_from are not buyable and
     * should only offer the "Customize this bouquet" quote path.
     *
     * @param array<string, mixed> $product Product row from Product::byOccasion() or similar.
     * @return bool True when the product has a positive price_from.
     *
     * @example
     *   Shop::isBuyable(['price_from' => '45.00']); // true
     *   Shop::isBuyable(['price_from' => '0.00']);  // false
     *   Shop::isBuyable(['price_from' => null]);    // false
     *   Shop::isBuyable([]);                         // false
     */
    public static function isBuyable(array $product): bool
    {
        if (!array_key_exists('price_from', $product)) {
            return false;
        }
        return (float) $product['price_from'] > 0.0;
    }

    /**
     * Parse and clamp a raw price value to a non-negative float rounded to 2 dp.
     *
     * Negative values are clamped to 0.00. Non-numeric strings cast to 0.00.
     * Used by AddonsController::collectAddonData() to normalise the price field.
     *
     * @param mixed $raw Raw value from a form POST or other untrusted source.
     * @return float Non-negative price rounded to 2 decimal places.
     *
     * @example
     *   Shop::normalizePrice('-5')    // 0.0
     *   Shop::normalizePrice('12.5')  // 12.5
     *   Shop::normalizePrice(12.555)  // 12.56
     *   Shop::normalizePrice('abc')   // 0.0
     */
    public static function normalizePrice(mixed $raw): float
    {
        return round(max(0.0, (float) $raw), 2);
    }

    /**
     * Return the display heading and blurb for an occasion slug in a given language.
     *
     * Data is sourced from the in-class OCCASION_COPY constant so no database
     * call is needed. For unknown slugs a generic fallback is returned so callers
     * never receive an empty array.
     *
     * @param string $slug Occasion URL slug, e.g. 'birthday' or 'get-well'.
     * @param string $lang Locale code: 'en' or 'es'. Falls back to 'en' for
     *                     any unrecognised code.
     *
     * @return array{heading: string, blurb: string} Display heading and blurb text.
     *
     * @example
     *   Shop::occasionCopy('birthday', 'en');
     *   // ['heading' => 'Birthday Flowers', 'blurb' => 'Make someone\'s…']
     *
     *   Shop::occasionCopy('unknown-slug', 'es');
     *   // ['heading' => 'Flores Especiales', 'blurb' => 'Explora nuestra…']
     */
    public static function occasionCopy(string $slug, string $lang): array
    {
        $langKey = ($lang === 'es') ? 'es' : 'en';

        if (isset(self::OCCASION_COPY[$slug][$langKey])) {
            return self::OCCASION_COPY[$slug][$langKey];
        }

        // Graceful fallback for unknown or future slugs.
        return $langKey === 'es'
            ? ['heading' => 'Flores Especiales', 'blurb' => 'Explora nuestra selección de arreglos florales hermosos y hechos a mano.']
            : ['heading' => 'Special Occasion Flowers', 'blurb' => 'Explore our selection of beautiful, hand-crafted floral arrangements.'];
    }

    /**
     * Resolve a virtual occasion-group slug to its member occasion slugs.
     *
     * Returns null for a normal (single) occasion slug, so callers can branch:
     * a non-null result means the page should union several occasions'
     * products rather than load one.
     *
     * @param string $slug The page slug, e.g. 'hospital' or 'sympathy'.
     *
     * @return string[]|null Member occasion slugs for a group, or null otherwise.
     *
     * @example
     *   Shop::occasionGroup('hospital'); // ['get-well', 'new-baby']
     *   Shop::occasionGroup('sympathy'); // null
     */
    public static function occasionGroup(string $slug): ?array
    {
        return self::OCCASION_GROUPS[$slug] ?? null;
    }

    /**
     * Return the slugs of every virtual occasion-group page (e.g. 'hospital').
     *
     * Used by the sitemap to list the combined occasion landing pages, which
     * have no row in the occasions table.
     *
     * @return string[] Group slugs.
     *
     * @example
     *   Shop::occasionGroupSlugs(); // ['hospital']
     */
    public static function occasionGroupSlugs(): array
    {
        return array_keys(self::OCCASION_GROUPS);
    }
}
