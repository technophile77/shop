<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Shop;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Support\Shop — pure fixture-based, no DB.
 *
 * Covers isBuyable(), normalizePrice(), and occasionCopy() for all four
 * seeded occasion slugs (birthday, get-well, new-baby, sympathy) in both
 * languages, plus unknown-slug fallback behaviour.
 *
 * @see \App\Support\Shop
 */
final class ShopTest extends TestCase
{
    // -------------------------------------------------------------------------
    // isBuyable
    // -------------------------------------------------------------------------

    /**
     * A product with a positive price_from string is buyable.
     */
    public function testIsBuyablePositiveStringPrice(): void
    {
        self::assertTrue(Shop::isBuyable(['price_from' => '45.00']));
    }

    /**
     * A product with a positive float price_from is buyable.
     */
    public function testIsBuyablePositiveFloatPrice(): void
    {
        self::assertTrue(Shop::isBuyable(['price_from' => 9.99]));
    }

    /**
     * A product with price_from === null is not buyable.
     */
    public function testIsBuyableNullPrice(): void
    {
        self::assertFalse(Shop::isBuyable(['price_from' => null]));
    }

    /**
     * A product with price_from === 0 is not buyable.
     */
    public function testIsBuyableZeroIntPrice(): void
    {
        self::assertFalse(Shop::isBuyable(['price_from' => 0]));
    }

    /**
     * A product with price_from === "0.00" is not buyable.
     */
    public function testIsBuyableZeroStringPrice(): void
    {
        self::assertFalse(Shop::isBuyable(['price_from' => '0.00']));
    }

    /**
     * A product row with no price_from key at all is not buyable.
     */
    public function testIsBuyableMissingKey(): void
    {
        self::assertFalse(Shop::isBuyable([]));
    }

    /**
     * A product row with only unrelated keys is not buyable.
     */
    public function testIsBuyableUnrelatedKeys(): void
    {
        self::assertFalse(Shop::isBuyable(['name_en' => 'Roses', 'active' => 1]));
    }

    // -------------------------------------------------------------------------
    // normalizePrice
    // -------------------------------------------------------------------------

    /**
     * A negative value is clamped to 0.00.
     */
    public function testNormalizePriceNegativeClamped(): void
    {
        self::assertSame(0.0, Shop::normalizePrice(-5));
    }

    /**
     * A negative string value is clamped to 0.00.
     */
    public function testNormalizePriceNegativeStringClamped(): void
    {
        self::assertSame(0.0, Shop::normalizePrice('-99.99'));
    }

    /**
     * A numeric string "12.5" parses to 12.5.
     */
    public function testNormalizePriceNumericString(): void
    {
        self::assertSame(12.5, Shop::normalizePrice('12.5'));
    }

    /**
     * A value that rounds up at 2 decimal places is correctly rounded.
     */
    public function testNormalizePriceRoundsUp(): void
    {
        self::assertSame(12.56, Shop::normalizePrice(12.555));
    }

    /**
     * A value that rounds down at 2 decimal places is correctly rounded.
     */
    public function testNormalizePriceRoundsDown(): void
    {
        self::assertSame(12.55, Shop::normalizePrice(12.554));
    }

    /**
     * A non-numeric string "abc" produces 0.0.
     */
    public function testNormalizePriceNonNumericString(): void
    {
        self::assertSame(0.0, Shop::normalizePrice('abc'));
    }

    /**
     * Zero is returned as-is (not negative, not rounded away).
     */
    public function testNormalizePriceZero(): void
    {
        self::assertSame(0.0, Shop::normalizePrice(0));
    }

    /**
     * An integer value is returned as float with 2 dp.
     */
    public function testNormalizePriceInteger(): void
    {
        self::assertSame(10.0, Shop::normalizePrice(10));
    }

    // -------------------------------------------------------------------------
    // occasionCopy — seeded slugs
    // -------------------------------------------------------------------------

    /**
     * birthday / en returns a non-empty heading and blurb.
     */
    public function testOccasionCopyBirthdayEn(): void
    {
        $copy = Shop::occasionCopy('birthday', 'en');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
        self::assertIsString($copy['heading']);
        self::assertIsString($copy['blurb']);
    }

    /**
     * birthday / es returns a non-empty heading and blurb in Spanish.
     */
    public function testOccasionCopyBirthdayEs(): void
    {
        $copy = Shop::occasionCopy('birthday', 'es');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
        // Verify it differs from the English copy (not just a passthrough).
        $enCopy = Shop::occasionCopy('birthday', 'en');
        self::assertNotSame($enCopy['heading'], $copy['heading']);
    }

    /**
     * get-well / en returns a non-empty heading and blurb.
     */
    public function testOccasionCopyGetWellEn(): void
    {
        $copy = Shop::occasionCopy('get-well', 'en');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
    }

    /**
     * get-well / es returns a non-empty heading and blurb.
     */
    public function testOccasionCopyGetWellEs(): void
    {
        $copy = Shop::occasionCopy('get-well', 'es');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
    }

    /**
     * new-baby / en returns a non-empty heading and blurb.
     */
    public function testOccasionCopyNewBabyEn(): void
    {
        $copy = Shop::occasionCopy('new-baby', 'en');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
    }

    /**
     * new-baby / es returns a non-empty heading and blurb.
     */
    public function testOccasionCopyNewBabyEs(): void
    {
        $copy = Shop::occasionCopy('new-baby', 'es');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
    }

    /**
     * sympathy / en returns a non-empty heading and blurb.
     */
    public function testOccasionCopySympathyEn(): void
    {
        $copy = Shop::occasionCopy('sympathy', 'en');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
    }

    /**
     * sympathy / es returns a non-empty heading and blurb.
     */
    public function testOccasionCopySympathyEs(): void
    {
        $copy = Shop::occasionCopy('sympathy', 'es');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
    }

    // -------------------------------------------------------------------------
    // occasionCopy — unknown slug fallback
    // -------------------------------------------------------------------------

    /**
     * An unknown slug with 'en' lang returns a sane English fallback.
     */
    public function testOccasionCopyUnknownSlugEn(): void
    {
        $copy = Shop::occasionCopy('totally-unknown-slug', 'en');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
        self::assertIsString($copy['heading']);
        self::assertIsString($copy['blurb']);
    }

    /**
     * An unknown slug with 'es' lang returns a sane Spanish fallback.
     */
    public function testOccasionCopyUnknownSlugEs(): void
    {
        $copy = Shop::occasionCopy('totally-unknown-slug', 'es');
        self::assertNotEmpty($copy['heading']);
        self::assertNotEmpty($copy['blurb']);
        // Fallback for 'es' must differ from fallback for 'en'.
        $enFallback = Shop::occasionCopy('totally-unknown-slug', 'en');
        self::assertNotSame($enFallback['heading'], $copy['heading']);
    }

    /**
     * An unrecognised lang code falls back to English copy.
     */
    public function testOccasionCopyUnknownLangFallsBackToEn(): void
    {
        $copy   = Shop::occasionCopy('birthday', 'fr');
        $enCopy = Shop::occasionCopy('birthday', 'en');
        self::assertSame($enCopy['heading'], $copy['heading']);
        self::assertSame($enCopy['blurb'],   $copy['blurb']);
    }

    /**
     * occasionCopy always returns an array with exactly the 'heading' and 'blurb' keys.
     */
    public function testOccasionCopyStructureKeys(): void
    {
        foreach (['birthday', 'get-well', 'new-baby', 'sympathy', 'unknown'] as $slug) {
            foreach (['en', 'es'] as $lang) {
                $copy = Shop::occasionCopy($slug, $lang);
                self::assertArrayHasKey('heading', $copy, "Missing 'heading' for {$slug}/{$lang}");
                self::assertArrayHasKey('blurb',   $copy, "Missing 'blurb' for {$slug}/{$lang}");
            }
        }
    }
}
