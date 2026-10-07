<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\FloristSchema;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Unit tests for App\Support\FloristSchema::build() — pure, with array-backed lookups instead of Config and Settings.
 *
 * build() replaced JSON-LD the layout wrote by hand with htmlspecialchars(), so every page published
 * "Perla&#039;s Flowers" (and "&amp;" in URLs): the browser does not decode entities inside <script>, so
 * structured-data consumers read them literally. These tests pin that values stay raw in the array and that
 * json_encode() with the layout's flags round-trips them and cannot end the script block, plus the rules the
 * layout used to hard-code: defaults, float coordinates, sameAs and opening hours. One seeded stochastic test
 * covers random hours; it uses its own Random\Randomizer, like PageTitleTest, so mt_rand() is untouched.
 *
 * @see \App\Support\FloristSchema
 * @see views/layouts/public.php
 */
final class FloristSchemaTest extends TestCase
{
    /** Seed for the stochastic test; quoted in its failure messages so a run can be replayed. */
    private const SEED = 20261007;

    private const ITERATIONS = 300;

    /** The json_encode() flags views/layouts/public.php prints the document with. */
    private const LAYOUT_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG;

    /** Site-setting day names mapped to their schema.org DayOfWeek URLs, Monday first (used by the stochastic test). */
    private const DAYS = [
        'mon' => 'https://schema.org/Monday', 'tue' => 'https://schema.org/Tuesday', 'wed' => 'https://schema.org/Wednesday', 'thu' => 'https://schema.org/Thursday',
        'fri' => 'https://schema.org/Friday', 'sat' => 'https://schema.org/Saturday', 'sun' => 'https://schema.org/Sunday',
    ];

    private const FACEBOOK = 'https://www.facebook.com/perlaflowers4/';

    private const INSTAGRAM = 'https://www.instagram.com/perlaflowers4';

    /** Environment values mirroring production. */
    private const PRODUCTION = [
        'BUSINESS_NAME' => "Perla's Flowers", 'APP_URL' => 'https://flowers.cresswell.org', 'BUSINESS_PHONE' => '(918) 638-9506',
        'BUSINESS_STREET_ADDRESS' => '6134 S Troost Ave', 'BUSINESS_CITY' => 'Tulsa', 'BUSINESS_STATE' => 'OK', 'BUSINESS_POSTAL_CODE' => '74136',
        'BUSINESS_LAT' => '36.0814', 'BUSINESS_LNG' => '-95.9987', 'FACEBOOK_URL' => self::FACEBOOK, 'INSTAGRAM_URL' => self::INSTAGRAM,
    ];

    /**
     * Build the document from array-backed lookups shaped like Config::get() and Settings::get().
     *
     * @param array<string, mixed> $env      Environment values; an absent key yields the caller's default.
     * @param array<string, mixed> $settings Site-setting values; an absent key reads as null.
     */
    private static function build(array $env = [], array $settings = []): array
    {
        return FloristSchema::build(
            static fn (string $key, mixed $default = null): mixed => $env[$key] ?? $default,
            static fn (string $key): mixed => $settings[$key] ?? null
        );
    }

    /** One expected OpeningHoursSpecification entry; $dayOfWeek is the full schema.org DayOfWeek URL. */
    private static function spec(string $dayOfWeek, string $opens, string $closes): array
    {
        return ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $dayOfWeek, 'opens' => $opens, 'closes' => $closes];
    }

    /** Production values produce exactly this document: keys, order, types, and no hours section. */
    public function testProductionValuesBuildTheExpectedDocument(): void
    {
        self::assertSame([
            '@context'   => 'https://schema.org',
            '@type'      => 'Florist',
            'name'       => "Perla's Flowers",
            'url'        => 'https://flowers.cresswell.org',
            'telephone'  => '(918) 638-9506',
            'address'    => ['@type' => 'PostalAddress', 'streetAddress' => '6134 S Troost Ave', 'addressLocality' => 'Tulsa', 'addressRegion' => 'OK', 'postalCode' => '74136', 'addressCountry' => 'US'],
            'geo'        => ['@type' => 'GeoCoordinates', 'latitude' => 36.0814, 'longitude' => -95.9987],
            'sameAs'     => [self::FACEBOOK, self::INSTAGRAM],
            'priceRange' => '$$',
        ], self::build(self::PRODUCTION));
    }

    /** With nothing configured the document falls back to the defaults the layout always had. */
    public function testDefaultsApplyWhenConfigKeysAreAbsent(): void
    {
        self::assertSame([
            '@context'   => 'https://schema.org',
            '@type'      => 'Florist',
            'name'       => '',
            'url'        => '',
            'telephone'  => '',
            'address'    => ['@type' => 'PostalAddress', 'streetAddress' => '', 'addressLocality' => 'Tulsa', 'addressRegion' => 'OK', 'postalCode' => '', 'addressCountry' => 'US'],
            'geo'        => ['@type' => 'GeoCoordinates', 'latitude' => 36.0814, 'longitude' => -95.9987],
            'sameAs'     => [],
            'priceRange' => '$$',
        ], self::build());
    }

    /** Coordinates arrive as .env strings (or numbers) and always leave as floats. */
    public function testCoordinatesAreFloats(): void
    {
        foreach (['40' => 40.0, '36.5' => 36.5, '-95.25' => -95.25] as $given => $expected) {
            $geo = self::build(['BUSINESS_LAT' => (string) $given, 'BUSINESS_LNG' => (string) $given])['geo'];
            self::assertSame([$expected, $expected], [$geo['latitude'], $geo['longitude']], "from '{$given}'");
        }

        self::assertSame(41.0, self::build(['BUSINESS_LAT' => 41])['geo']['latitude'], 'latitude from an int');
    }

    /** Apostrophes, ampersands, quotes, markup and a script terminator come through unchanged. */
    public function testValuesStayRawText(): void
    {
        $hostile = "O'Neil & Sons \"Florist\" <b>x</b> </script><script>alert(1)</script>";
        $url     = 'https://example.test/p?a=1&b=2';
        $schema  = self::build(
            [
                'BUSINESS_NAME' => $hostile, 'APP_URL' => $url, 'BUSINESS_PHONE' => $hostile, 'BUSINESS_STREET_ADDRESS' => $hostile,
                'BUSINESS_CITY' => $hostile, 'BUSINESS_STATE' => $hostile, 'BUSINESS_POSTAL_CODE' => $hostile,
                'BUSINESS_PRICE_RANGE' => $hostile, 'FACEBOOK_URL' => $url, 'INSTAGRAM_URL' => $url . '2',
            ],
            ['business_hours_mon_open' => $hostile, 'business_hours_mon_close' => $hostile]
        );

        self::assertSame([$hostile, $url, $hostile, $hostile], [$schema['name'], $schema['url'], $schema['telephone'], $schema['priceRange']]);
        self::assertSame(
            ['@type' => 'PostalAddress', 'streetAddress' => $hostile, 'addressLocality' => $hostile, 'addressRegion' => $hostile, 'postalCode' => $hostile, 'addressCountry' => 'US'],
            $schema['address']
        );
        self::assertSame([$url, $url . '2'], $schema['sameAs']);
        self::assertSame(self::spec('https://schema.org/Monday', $hostile, $hostile), $schema['openingHoursSpecification'][0]);
    }

    /** Printed with the layout's flags the document round-trips and has no raw `<` or `>`, so it cannot end the script block. */
    public function testLayoutFlagsRoundTripAndCannotEndTheScriptBlock(): void
    {
        $schema = self::build(
            ['BUSINESS_NAME' => "O'Neil & Sons \"Florist\" </script><script>alert(1)</script>", 'BUSINESS_STREET_ADDRESS' => 'Fish & Chips <Ave>'] + self::PRODUCTION,
            ['business_hours_sun_open' => '11:00', 'business_hours_sun_close' => '15:00']
        );
        $json   = json_encode($schema, self::LAYOUT_FLAGS);

        self::assertIsString($json);
        self::assertSame($schema, json_decode($json, true), 'the printed JSON decodes back to the array');
        self::assertDoesNotMatchRegularExpression('/[<>]/', $json);
        self::assertStringContainsString('"https://flowers.cresswell.org"', $json, 'slashes are not escaped');
        self::assertStringNotContainsString('&#039;', $json, 'no HTML entities in the JSON');
        self::assertStringNotContainsString('&amp;', $json, 'no HTML entities in the JSON');
    }

    /**
     * sameAs lists the Facebook URL then the Instagram URL, drops blanks, stays a list, and builds the
     * Instagram URL from the handle only when the handle is not blank (no bare instagram.com link).
     */
    public function testSameAsFilteringAndInstagramFallback(): void
    {
        $cases = [
            'Facebook then Instagram URL'                        => [['FACEBOOK_URL' => self::FACEBOOK, 'INSTAGRAM_URL' => self::INSTAGRAM], [self::FACEBOOK, self::INSTAGRAM]],
            'Instagram URL wins over the handle'                 => [['INSTAGRAM_URL' => self::INSTAGRAM, 'INSTAGRAM_HANDLE' => 'someoneelse'], [self::INSTAGRAM]],
            'handle builds the Instagram URL'                    => [['FACEBOOK_URL' => self::FACEBOOK, 'INSTAGRAM_HANDLE' => 'perlaflowers4'], [self::FACEBOOK, self::INSTAGRAM]],
            'handle is trimmed'                                  => [['INSTAGRAM_HANDLE' => "  perlaflowers4 \n"], [self::INSTAGRAM]],
            'neither URL nor handle: no bare instagram.com link' => [[], []],
            'empty handle is ignored'                            => [['INSTAGRAM_HANDLE' => ''], []],
            'whitespace handle is ignored'                       => [['INSTAGRAM_HANDLE' => " \t "], []],
            'blank Facebook URL is dropped, list stays a list'   => [['FACEBOOK_URL' => '  ', 'INSTAGRAM_URL' => self::INSTAGRAM], [self::INSTAGRAM]],
            'only Facebook'                                      => [['FACEBOOK_URL' => self::FACEBOOK], [self::FACEBOOK]],
        ];

        foreach ($cases as $label => [$env, $expected]) {
            self::assertSame($expected, self::build($env)['sameAs'], $label);
        }
    }

    /** Only days with both an opening and a closing time are listed, in Monday-to-Sunday order, last in the document. */
    public function testOpeningHoursListOnlyDaysWithBothTimes(): void
    {
        $schema = self::build([], [
            'business_hours_fri_open' => '10:30', 'business_hours_fri_close' => '18:00',  // set before Monday on purpose
            'business_hours_mon_open' => '09:00', 'business_hours_mon_close' => '17:00',
            'business_hours_tue_open' => '09:00',                                         // no closing time
            'business_hours_wed_close' => '17:00',                                        // no opening time
            'business_hours_thu_open' => '0', 'business_hours_thu_close' => '0',         // '0' is not a time
            'business_hours_sat_open' => '', 'business_hours_sat_close' => '14:00',      // blank opening time
            'business_hours_sun_open' => '11:00', 'business_hours_sun_close' => '15:00',
        ]);

        self::assertSame(
            [self::spec('https://schema.org/Monday', '09:00', '17:00'), self::spec('https://schema.org/Friday', '10:30', '18:00'), self::spec('https://schema.org/Sunday', '11:00', '15:00')],
            $schema['openingHoursSpecification']
        );
        self::assertSame('openingHoursSpecification', array_key_last($schema));
    }

    /** All seven days map to their schema.org DayOfWeek URLs, Monday to Sunday, whatever order the settings were stored in. */
    public function testEveryDayMapsToItsSchemaDayOfWeek(): void
    {
        $settings = [];
        foreach (['sun', 'sat', 'fri', 'thu', 'wed', 'tue', 'mon'] as $day) {
            $settings["business_hours_{$day}_close"] = '17:00';
            $settings["business_hours_{$day}_open"]  = '09:00';
        }

        self::assertSame(
            array_map(
                static fn (string $dayOfWeek): array => self::spec($dayOfWeek, '09:00', '17:00'),
                [
                    'https://schema.org/Monday', 'https://schema.org/Tuesday', 'https://schema.org/Wednesday', 'https://schema.org/Thursday',
                    'https://schema.org/Friday', 'https://schema.org/Saturday', 'https://schema.org/Sunday',
                ]
            ),
            self::build([], $settings)['openingHoursSpecification']
        );
    }

    /** No complete day, no section. */
    public function testOpeningHoursAreOmittedWhenNoDayIsComplete(): void
    {
        self::assertArrayNotHasKey('openingHoursSpecification', self::build());
        self::assertArrayNotHasKey(
            'openingHoursSpecification',
            self::build([], ['business_hours_mon_open' => '09:00', 'business_hours_tue_close' => '17:00', 'business_hours_wed_open' => '', 'business_hours_wed_close' => '0'])
        );
    }

    /**
     * Random hours, built so the expected output is known by construction: each day's opening and closing value
     * is either usable ('09:00' ...) or not (absent, '' or '0'). Exactly the days with two usable values must be
     * listed, in Monday-to-Sunday order, and the section must be absent when there are none.
     */
    public function testStochasticHoursListExactlyTheCompleteDays(): void
    {
        $rng = new Randomizer(new Mt19937(self::SEED));

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $settings = [];
            $expected = [];
            foreach (self::DAYS as $day => $dayOfWeek) {
                $times = [];
                foreach (['open', 'close'] as $edge) {
                    $times[$edge] = $rng->getInt(0, 3) > 0 ? ['09:00', '10:30', '17:00', '21:15'][$rng->getInt(0, 3)] : null;
                    $stored       = $times[$edge] ?? [null, '', '0'][$rng->getInt(0, 2)];
                    if ($stored !== null) {
                        $settings["business_hours_{$day}_{$edge}"] = $stored;
                    }
                }
                if ($times['open'] !== null && $times['close'] !== null) {
                    $expected[] = self::spec($dayOfWeek, $times['open'], $times['close']);
                }
            }

            $schema  = self::build([], $settings);
            $context = sprintf('seed %d, iteration %d, settings %s', self::SEED, $iteration, var_export($settings, true));

            self::assertSame($expected, $schema['openingHoursSpecification'] ?? [], $context);
            self::assertSame($expected === [], !isset($schema['openingHoursSpecification']), $context);
        }
    }
}
