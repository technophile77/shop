<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Shop;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Support\Shop — pure fixture-based, no DB.
 *
 * Covers isBuyable(), normalizePrice(), occasionCopyFromRow() (DB-backed copy
 * with name/blurb fallbacks), and occasionGroup()/occasionGroupCopy() for the
 * virtual hospital group page.
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
    // occasionCopyFromRow — DB-backed single-occasion copy
    // -------------------------------------------------------------------------

    /**
     * When the row has heading/blurb for the language, they are used verbatim.
     */
    public function testOccasionCopyFromRowUsesRowFields(): void
    {
        $row = [
            'name_en' => 'Anniversary', 'name_es' => 'Aniversario',
            'heading_en' => 'Anniversary Flowers', 'heading_es' => 'Flores de Aniversario',
            'blurb_en' => 'Celebrate years together.', 'blurb_es' => 'Celebra los años juntos.',
        ];
        $en = Shop::occasionCopyFromRow($row, 'en');
        self::assertSame('Anniversary Flowers', $en['heading']);
        self::assertSame('Celebrate years together.', $en['blurb']);

        $es = Shop::occasionCopyFromRow($row, 'es');
        self::assertSame('Flores de Aniversario', $es['heading']);
        self::assertSame('Celebra los años juntos.', $es['blurb']);
    }

    /**
     * An empty heading falls back to the localized name, then to name_en.
     */
    public function testOccasionCopyFromRowHeadingFallsBackToName(): void
    {
        $row = ['name_en' => 'Anniversary', 'name_es' => 'Aniversario', 'heading_en' => '', 'heading_es' => ''];
        self::assertSame('Anniversary',  Shop::occasionCopyFromRow($row, 'en')['heading']);
        self::assertSame('Aniversario',  Shop::occasionCopyFromRow($row, 'es')['heading']);

        // No name_es → es heading falls back to name_en.
        $row2 = ['name_en' => 'Anniversary', 'name_es' => '', 'heading_en' => '', 'heading_es' => ''];
        self::assertSame('Anniversary', Shop::occasionCopyFromRow($row2, 'es')['heading']);
    }

    /**
     * An empty blurb falls back to the generic blurb (language-specific).
     */
    public function testOccasionCopyFromRowBlurbFallsBackToGeneric(): void
    {
        $row = ['name_en' => 'Anniversary', 'blurb_en' => '', 'blurb_es' => ''];
        $en  = Shop::occasionCopyFromRow($row, 'en');
        $es  = Shop::occasionCopyFromRow($row, 'es');
        self::assertNotSame('', $en['blurb']);
        self::assertNotSame('', $es['blurb']);
        self::assertNotSame($en['blurb'], $es['blurb']); // generic differs per language
    }

    // -------------------------------------------------------------------------
    // occasionGroup / occasionGroupCopy — virtual group pages
    // -------------------------------------------------------------------------

    /**
     * occasionGroup resolves the hospital group to get-well + new-baby and
     * returns null for single occasions and unknown slugs.
     */
    public function testOccasionGroup(): void
    {
        self::assertSame(['get-well', 'new-baby'], Shop::occasionGroup('hospital'));
        self::assertNull(Shop::occasionGroup('sympathy'));
        self::assertNull(Shop::occasionGroup('birthday'));
        self::assertNull(Shop::occasionGroup('get-well'));
        self::assertNull(Shop::occasionGroup('unknown'));
    }

    /**
     * The hospital group page has its own non-empty bilingual heading + blurb.
     */
    public function testHospitalGroupCopy(): void
    {
        foreach (['en', 'es'] as $lang) {
            $copy = Shop::occasionGroupCopy('hospital', $lang);
            self::assertNotSame('', $copy['heading']);
            self::assertNotSame('', $copy['blurb']);
            self::assertNotSame('Special Occasion Flowers', $copy['heading']);
            self::assertNotSame('Flores Especiales', $copy['heading']);
        }
    }

    /**
     * An unknown group slug returns the generic fallback (en differs from es).
     */
    public function testOccasionGroupCopyUnknownFallsBack(): void
    {
        $en = Shop::occasionGroupCopy('nope', 'en');
        $es = Shop::occasionGroupCopy('nope', 'es');
        self::assertSame('Special Occasion Flowers', $en['heading']);
        self::assertSame('Flores Especiales', $es['heading']);
    }
}
