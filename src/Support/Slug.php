<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free URL-slug helpers for products.
 *
 * Product detail URLs are id-anchored: `{name-slug}-{id}` (e.g.
 * `whisper-of-love-12`). The trailing id is authoritative, so a product can be
 * renamed without breaking links — the controller 301-redirects a stale slug to
 * the canonical one. No database access; trivially unit-testable.
 *
 * @see \App\Controllers\ShopController::product()
 */
final class Slug
{
    /** Lowercase accented-Latin → ASCII transliteration map (Spanish coverage). */
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Convert arbitrary text to a lowercase, hyphenated ASCII slug.
     *
     * Lowercases, transliterates accents, replaces every run of non
     * `[a-z0-9]` with a single hyphen, and trims leading/trailing hyphens.
     *
     * @param string $text Source text (e.g. a product name).
     *
     * @return string The slug; '' when the input has no slug-able characters.
     *
     * @example
     *   Slug::make('Whisper of Love');     // 'whisper-of-love'
     *   Slug::make('Flores  Alegres!');    // 'flores-alegres'
     *   Slug::make('Niño & Co.');          // 'nino-co'
     */
    public static function make(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, self::ACCENTS);
        $text = (string) preg_replace('/[^a-z0-9]+/u', '-', $text);

        return trim($text, '-');
    }

    /**
     * Build the canonical id-anchored ref for a product detail URL.
     *
     * Format: `{name-slug}-{id}`. Falls back to `flower-{id}` when the name
     * yields no slug.
     *
     * @param array<string, mixed> $product Product row with at least 'id' and 'name_en'.
     *
     * @return string The canonical ref, e.g. 'whisper-of-love-12'.
     *
     * @example
     *   Slug::productRef(['id' => 12, 'name_en' => 'Whisper of Love']); // 'whisper-of-love-12'
     */
    public static function productRef(array $product): string
    {
        $id   = (int) ($product['id'] ?? 0);
        $slug = self::make((string) ($product['name_en'] ?? ''));
        if ($slug === '') {
            $slug = 'flower';
        }

        return $slug . '-' . $id;
    }

    /**
     * Extract the trailing product id from a detail-URL ref.
     *
     * @param string $ref The URL ref, e.g. 'whisper-of-love-12'.
     *
     * @return int The trailing integer id, or 0 when none is present.
     *
     * @example
     *   Slug::parseId('whisper-of-love-12'); // 12
     *   Slug::parseId('no-id-here');         // 0
     */
    public static function parseId(string $ref): int
    {
        return preg_match('/(\d+)$/', $ref, $m) === 1 ? (int) $m[1] : 0;
    }
}
