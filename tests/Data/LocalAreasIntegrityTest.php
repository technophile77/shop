<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Support\LocalArea;
use PHPUnit\Framework\TestCase;

/**
 * Integrity + stochastic tests against the REAL config/local-areas.php and
 * config/local-services.php. Guards against a half-filled data file shipping:
 * every city must have the required keys and non-empty venue lists, and every
 * city × service combination must yield a well-formed title and JSON-LD graph.
 *
 * @see config/local-areas.php
 * @see config/local-services.php
 * @see \App\Support\LocalArea
 */
final class LocalAreasIntegrityTest extends TestCase
{
    public function testRealAreaDataIsStructurallyValid(): void
    {
        $problems = LocalArea::validateAreas(LocalArea::areas());
        self::assertSame([], $problems, "local-areas.php has integrity problems:\n" . implode("\n", $problems));
    }

    public function testRealServiceDataIsStructurallyValid(): void
    {
        $problems = LocalArea::validateServices(LocalArea::services());
        self::assertSame([], $problems, "local-services.php has integrity problems:\n" . implode("\n", $problems));
    }

    public function testEveryExpectedCityIsPresent(): void
    {
        $slugs = array_keys(LocalArea::areas());
        foreach (['tulsa', 'jenks', 'broken-arrow', 'bixby', 'glenpool', 'sapulpa', 'catoosa', 'sand-springs'] as $expected) {
            self::assertContains($expected, $slugs, "Missing city: {$expected}");
        }
    }

    public function testAddressesLookWellFormed(): void
    {
        foreach (LocalArea::areas() as $slug => $city) {
            foreach (['funeral_homes', 'hospitals'] as $listKey) {
                foreach ($city[$listKey] as $i => $venue) {
                    // A real US address should contain a state abbreviation + 5-digit ZIP.
                    self::assertMatchesRegularExpression(
                        '/\b[A-Z]{2}\b.*\b\d{5}\b/',
                        $venue['address'],
                        "city '{$slug}' {$listKey}[{$i}] address looks malformed: {$venue['address']}"
                    );
                }
            }
        }
    }

    public function testEveryVenueAddressIsGeocoded(): void
    {
        $coords = LocalArea::coords();
        if ($coords === []) {
            self::markTestSkipped('No coordinate cache yet — run bin/geocode-local-areas.php.');
        }

        $missing = [];
        foreach (LocalArea::areas() as $slug => $city) {
            foreach (['funeral_homes', 'hospitals'] as $listKey) {
                foreach ($city[$listKey] as $venue) {
                    $addr = $venue['address'];
                    if (!isset($coords[$addr]['lat'], $coords[$addr]['lng'])) {
                        $missing[] = "{$slug}: {$addr}";
                    }
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            "Venues missing coordinates (re-run bin/geocode-local-areas.php):\n" . implode("\n", $missing)
        );
    }

    /**
     * Stochastic sweep: random city × service combos must always produce a
     * non-empty localized title that mentions the city, and a JSON-LD graph
     * whose first node is the Service. Venue-bearing services must list venues.
     */
    public function testRandomCityServiceCombosAlwaysWellFormed(): void
    {
        $areas    = LocalArea::areas();
        $services  = LocalArea::services();
        $slugs     = array_keys($areas);
        $codes     = array_keys($services);
        $langs     = ['en', 'es'];

        $business = [
            'name' => "Perla's Flowers", 'telephone' => '(720) 388-3496',
            'url' => 'https://flowers.cresswell.org', 'street' => '6134 S Troost Ave',
            'city' => 'Tulsa', 'state' => 'OK', 'postal' => '74136',
        ];

        for ($n = 0; $n < 200; $n++) {
            $slug    = $slugs[mt_rand(0, count($slugs) - 1)];
            $code    = $codes[mt_rand(0, count($codes) - 1)];
            $lang    = $langs[mt_rand(0, 1)];
            $city    = $areas[$slug];
            $context = "combo {$code}/{$slug}/{$lang}";

            $title = LocalArea::serviceTitle($code, $city, $lang, $services);
            self::assertNotSame('', $title, $context);
            self::assertStringContainsString($city['name'], $title, $context);

            $business['pageUrl'] = $business['url'] . '/' . $lang . LocalArea::path($code, $slug, $services);
            $ld = LocalArea::buildJsonLd($code, $city, $lang, $services, $business);
            self::assertSame('Service', $ld['@graph'][0]['@type'], $context);
            self::assertSame($city['name'], $ld['@graph'][0]['areaServed']['name'], $context);

            // Funeral/hospital pages must surface at least one named venue.
            if ($services[$code]['entities'] !== null) {
                self::assertNotEmpty(LocalArea::venues($code, $city, $services), $context);
            }
        }
    }
}
