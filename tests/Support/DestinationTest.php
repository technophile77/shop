<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Destination;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Support\Destination::normalize() — pure sanitisation.
 *
 * The session helpers (set/get/clear) are session-bound and validated at deploy.
 *
 * @see \App\Support\Destination
 */
final class DestinationTest extends TestCase
{
    /**
     * normalize trims, strips tags, lowercases service/city, and nulls empties.
     */
    public function testNormalizeSanitises(): void
    {
        $result = Destination::normalize([
            'service'       => 'Hospital',
            'city'          => '  Tulsa  ',
            'venue_name'    => '<b>St. Francis</b>',
            'venue_address' => ' 6161 S Yale Ave ',
            'occasion'      => '',
        ]);

        self::assertSame('hospital', $result['service']);
        self::assertSame('tulsa', $result['city']);
        self::assertSame('St. Francis', $result['venue_name']);
        self::assertSame('6161 S Yale Ave', $result['venue_address']);
        self::assertNull($result['occasion']);
    }

    /**
     * Missing keys become null, and the canonical key set is always present.
     */
    public function testNormalizeFillsMissingKeysWithNull(): void
    {
        $result = Destination::normalize([]);

        self::assertSame(
            ['service', 'city', 'venue_name', 'venue_address', 'occasion'],
            array_keys($result)
        );
        foreach ($result as $value) {
            self::assertNull($value);
        }
    }

    /**
     * Whitespace-only and tag-only values collapse to null.
     */
    public function testNormalizeCollapsesEmptyToNull(): void
    {
        $result = Destination::normalize([
            'venue_name'    => '   ',
            'venue_address' => '<br>',
        ]);
        self::assertNull($result['venue_name']);
        self::assertNull($result['venue_address']);
    }
}
