<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Support\Slug — pure, no DB.
 *
 * @see \App\Support\Slug
 */
final class SlugTest extends TestCase
{
    /**
     * make() lowercases, hyphenates, and trims.
     */
    public function testMakeBasics(): void
    {
        self::assertSame('whisper-of-love', Slug::make('Whisper of Love'));
        self::assertSame('flores-alegres', Slug::make('  Flores   Alegres!  '));
        self::assertSame('deluxe-box', Slug::make('Deluxe Box'));
    }

    /**
     * make() transliterates accented Latin characters to ASCII.
     */
    public function testMakeStripsAccents(): void
    {
        self::assertSame('nino-co', Slug::make('Niño & Co.'));
        self::assertSame('cumpleanos', Slug::make('Cumpleaños'));
        self::assertSame('flores-de-condolencias', Slug::make('Flores de Condolencias'));
    }

    /**
     * make() returns '' when there are no slug-able characters.
     */
    public function testMakeEmpty(): void
    {
        self::assertSame('', Slug::make('   '));
        self::assertSame('', Slug::make('!!!'));
    }

    /**
     * productRef() appends the id; falls back to 'flower' for an unslugable name.
     */
    public function testProductRef(): void
    {
        self::assertSame('whisper-of-love-12', Slug::productRef(['id' => 12, 'name_en' => 'Whisper of Love']));
        self::assertSame('flower-7', Slug::productRef(['id' => 7, 'name_en' => '!!!']));
        self::assertSame('flower-3', Slug::productRef(['id' => 3]));
    }

    /**
     * parseId() extracts the trailing integer, or 0 when absent.
     */
    public function testParseId(): void
    {
        self::assertSame(12, Slug::parseId('whisper-of-love-12'));
        self::assertSame(5, Slug::parseId('rose-2-5'));      // trailing id wins
        self::assertSame(8, Slug::parseId('8'));
        self::assertSame(0, Slug::parseId('no-id-here'));
        self::assertSame(0, Slug::parseId(''));
    }

    /**
     * A productRef round-trips back to its id via parseId.
     */
    public function testRefRoundTrip(): void
    {
        foreach ([1, 12, 999] as $id) {
            $ref = Slug::productRef(['id' => $id, 'name_en' => 'Some Bouquet 2 Roses']);
            self::assertSame($id, Slug::parseId($ref));
        }
    }
}
