<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\LocalArea;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure SEO logic in App\Support\LocalArea.
 *
 * These run entirely on in-memory fixtures (no filesystem/DB) so they exercise
 * the title/meta/JSON-LD/validation logic deterministically.
 *
 * @see \App\Support\LocalArea
 */
final class LocalAreaTest extends TestCase
{
    /** @return array<string, array<string, mixed>> A two-city fixture. */
    private function areasFixture(): array
    {
        return [
            'testville' => [
                'name'           => 'Testville',
                'state'          => 'OK',
                'distance_miles' => '5–7',
                'direction_en'   => 'east of Tulsa',
                'direction_es'   => 'al este de Tulsa',
                'zips'           => ['74000'],
                'funeral_homes'  => [
                    ['name' => 'Acme Funeral Home', 'address' => '1 Main St, Testville, OK 74000'],
                    ['name' => 'Rest Easy Chapel',  'address' => '2 Oak Ave, Testville, OK 74000'],
                ],
                'hospitals'      => [
                    ['name' => 'Testville General', 'address' => '3 Health Blvd, Testville, OK 74000'],
                ],
            ],
            'exampleburg' => [
                'name'           => 'Exampleburg',
                'state'          => 'OK',
                'distance_miles' => '12',
                'direction_en'   => 'south of Tulsa',
                'direction_es'   => 'al sur de Tulsa',
                'zips'           => ['74111'],
                'funeral_homes'  => [
                    ['name' => 'Sample Funeral Care', 'address' => '9 Elm St, Exampleburg, OK 74111'],
                ],
                'hospitals'      => [
                    ['name' => 'Exampleburg Medical', 'address' => '10 Care Way, Exampleburg, OK 74111'],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> A three-service fixture. */
    private function servicesFixture(): array
    {
        return [
            'funeral' => [
                'prefix' => 'funeral-flowers', 'entities' => 'funeral_homes',
                'service_type' => 'Funeral flower delivery',
                'label_en' => 'Funeral Flowers', 'label_es' => 'Flores para Funerales',
                'title_en' => 'Funeral Flower Delivery to %s, OK', 'title_es' => 'Flores para Funerales en %s, OK',
                'meta_en' => 'Funeral flowers to %s.', 'meta_es' => 'Flores fúnebres a %s.',
                'h1_en' => 'Funeral Flowers in %s', 'h1_es' => 'Flores Fúnebres en %s',
                'venue_heading_en' => 'Funeral homes in %s', 'venue_heading_es' => 'Funerarias en %s',
            ],
            'hospital' => [
                'prefix' => 'hospital-flower-delivery', 'entities' => 'hospitals',
                'service_type' => 'Hospital flower delivery',
                'label_en' => 'Hospital Delivery', 'label_es' => 'Entrega a Hospitales',
                'title_en' => 'Hospital Flower Delivery to %s, OK', 'title_es' => 'Flores a Hospitales en %s, OK',
                'meta_en' => 'Hospital flowers to %s.', 'meta_es' => 'Flores a hospitales en %s.',
                'h1_en' => 'Hospital Flowers in %s', 'h1_es' => 'Flores a Hospitales en %s',
                'venue_heading_en' => 'Hospitals serving %s', 'venue_heading_es' => 'Hospitales en %s',
            ],
            'birthday' => [
                'prefix' => 'birthday-delivery', 'entities' => null,
                'service_type' => 'Birthday flower delivery',
                'label_en' => 'Birthday Delivery', 'label_es' => 'Entrega de Cumpleaños',
                'title_en' => 'Birthday Flower Delivery in %s, OK', 'title_es' => 'Flores de Cumpleaños en %s, OK',
                'meta_en' => 'Birthday flowers to %s.', 'meta_es' => 'Flores de cumpleaños a %s.',
                'h1_en' => 'Birthday Flowers in %s', 'h1_es' => 'Flores de Cumpleaños en %s',
                'venue_heading_en' => null, 'venue_heading_es' => null,
            ],
        ];
    }

    public function testCityBySlugHitAndMiss(): void
    {
        $areas = $this->areasFixture();
        self::assertSame('Testville', LocalArea::cityBySlug('testville', $areas)['name']);
        self::assertNull(LocalArea::cityBySlug('atlantis', $areas));
    }

    public function testServiceTitleSubstitutesCityPerLanguage(): void
    {
        $areas = $this->areasFixture();
        $svc   = $this->servicesFixture();
        $city  = $areas['testville'];

        self::assertSame('Funeral Flower Delivery to Testville, OK', LocalArea::serviceTitle('funeral', $city, 'en', $svc));
        self::assertSame('Flores para Funerales en Testville, OK', LocalArea::serviceTitle('funeral', $city, 'es', $svc));
    }

    public function testMetaAndHeadlineSubstituteCity(): void
    {
        $areas = $this->areasFixture();
        $svc   = $this->servicesFixture();
        $city  = $areas['exampleburg'];

        self::assertStringContainsString('Exampleburg', LocalArea::serviceMeta('hospital', $city, 'en', $svc));
        self::assertSame('Hospital Flowers in Exampleburg', LocalArea::serviceHeadline('hospital', $city, 'en', $svc));
    }

    public function testVenueHeadingNullForBirthday(): void
    {
        $areas = $this->areasFixture();
        $svc   = $this->servicesFixture();
        $city  = $areas['testville'];

        self::assertNull(LocalArea::venueHeading('birthday', $city, 'en', $svc));
        self::assertSame('Funeral homes in Testville', LocalArea::venueHeading('funeral', $city, 'en', $svc));
    }

    public function testVenuesReturnsCorrectListAndEmptyForBirthday(): void
    {
        $areas = $this->areasFixture();
        $svc   = $this->servicesFixture();
        $city  = $areas['testville'];

        self::assertCount(2, LocalArea::venues('funeral', $city, $svc));
        self::assertCount(1, LocalArea::venues('hospital', $city, $svc));
        self::assertSame([], LocalArea::venues('birthday', $city, $svc));
    }

    public function testPathAndAllPaths(): void
    {
        $areas = $this->areasFixture();
        $svc   = $this->servicesFixture();

        self::assertSame('/funeral-flowers-testville', LocalArea::path('funeral', 'testville', $svc));
        self::assertSame('/hospital-flower-delivery-exampleburg', LocalArea::path('hospital', 'exampleburg', $svc));

        self::assertSame('/flower-delivery-bixby', LocalArea::cityHubPath('bixby'));

        $paths = LocalArea::allPaths($areas, $svc);
        self::assertContains('/delivery-areas', $paths);
        self::assertContains('/flower-delivery-testville', $paths);
        self::assertContains('/flower-delivery-exampleburg', $paths);
        // hub + (3 services × 2 cities) + (1 city hub × 2 cities)
        self::assertCount(1 + 3 * 2 + 2, $paths);
    }

    public function testFaqItemsIncludeCityAndServiceSpecificQuestion(): void
    {
        $areas = $this->areasFixture();
        $city  = $areas['testville'];

        $funeralFaq = LocalArea::faqItems('funeral', $city, 'en');
        self::assertGreaterThanOrEqual(3, count($funeralFaq));
        self::assertStringContainsString('Testville', $funeralFaq[0]['q']);
        // funeral adds a funeral-home-specific question
        $joined = implode(' ', array_column($funeralFaq, 'q'));
        self::assertStringContainsString('funeral home', strtolower($joined));

        // birthday has no service-specific third question
        $birthdayFaq = LocalArea::faqItems('birthday', $city, 'en');
        self::assertCount(2, $birthdayFaq);
    }

    public function testBuildJsonLdShape(): void
    {
        $areas = $this->areasFixture();
        $svc   = $this->servicesFixture();
        $city  = $areas['testville'];

        $ld = LocalArea::buildJsonLd('funeral', $city, 'en', $svc, [
            'name' => "Perla's Flowers", 'telephone' => '(720) 388-3496',
            'url' => 'https://example.com', 'street' => '6134 S Troost Ave',
            'city' => 'Tulsa', 'state' => 'OK', 'postal' => '74136',
            'pageUrl' => 'https://example.com/en/funeral-flowers-testville',
        ]);

        self::assertSame('https://schema.org', $ld['@context']);
        self::assertArrayHasKey('@graph', $ld);

        $graph = $ld['@graph'];
        self::assertSame('Service', $graph[0]['@type']);
        self::assertSame('City', $graph[0]['areaServed']['@type']);
        self::assertSame('Testville', $graph[0]['areaServed']['name']);
        self::assertSame('Florist', $graph[0]['provider']['@type']);

        // Two funeral homes → two Place nodes.
        $places = array_values(array_filter($graph, static fn ($n) => ($n['@type'] ?? '') === 'Place'));
        self::assertCount(2, $places);
        self::assertSame('Acme Funeral Home', $places[0]['name']);

        // FAQPage present with matching question count.
        $faqNodes = array_values(array_filter($graph, static fn ($n) => ($n['@type'] ?? '') === 'FAQPage'));
        self::assertCount(1, $faqNodes);
        self::assertSame(
            count(LocalArea::faqItems('funeral', $city, 'en')),
            count($faqNodes[0]['mainEntity'])
        );
    }

    public function testBuildJsonLdBirthdayHasNoPlaceNodes(): void
    {
        $areas = $this->areasFixture();
        $svc   = $this->servicesFixture();
        $city  = $areas['testville'];

        $ld    = LocalArea::buildJsonLd('birthday', $city, 'en', $svc, ['name' => 'X']);
        $places = array_filter($ld['@graph'], static fn ($n) => ($n['@type'] ?? '') === 'Place');
        self::assertSame([], $places);
    }

    public function testValidateAreasPassesCleanFixture(): void
    {
        self::assertSame([], LocalArea::validateAreas($this->areasFixture()));
    }

    public function testValidateAreasCatchesBrokenData(): void
    {
        $broken = [
            'Bad_Slug' => [
                'name' => '', 'state' => 'OK', 'distance_miles' => '1',
                'direction_en' => 'x', 'direction_es' => 'y',
                'funeral_homes' => [],
                'hospitals' => [['name' => 'No Address Hospital']],
            ],
        ];
        $problems = LocalArea::validateAreas($broken);

        self::assertNotEmpty($problems);
        $blob = implode("\n", $problems);
        self::assertStringContainsString('slug', $blob);            // invalid slug chars
        self::assertStringContainsString("missing or empty 'name'", $blob);
        self::assertStringContainsString('funeral_homes', $blob);   // empty list
        self::assertStringContainsString('missing address', $blob);
    }

    public function testHaversineMiles(): void
    {
        // Zero distance to self.
        self::assertSame(0.0, LocalArea::haversineMiles(36.0814, -95.9987, 36.0814, -95.9987));

        // One degree of latitude ≈ 69.1 miles (2πR/360 with R = 3958.8).
        self::assertEqualsWithDelta(69.09, LocalArea::haversineMiles(36.0, -95.0, 37.0, -95.0), 0.2);
    }

    public function testDeliveryFee(): void
    {
        self::assertSame(10.0, LocalArea::deliveryFee(3, 5, 10, 1));   // inside base radius
        self::assertSame(10.0, LocalArea::deliveryFee(5, 5, 10, 1));   // exactly at base radius
        self::assertSame(11.0, LocalArea::deliveryFee(6, 5, 10, 1));   // 1 mile beyond
        self::assertSame(20.0, LocalArea::deliveryFee(15, 5, 10, 1));  // 10 miles beyond
        self::assertSame(17.5, LocalArea::deliveryFee(10, 5, 10, 1.5)); // fractional per-mile
    }

    public function testEnrichVenuesAttachesMilesAndFeeWhenCoordsKnown(): void
    {
        $venues = [
            ['name' => 'Known',   'address' => '1 Main St'],
            ['name' => 'Unknown', 'address' => '2 Oak Ave'],
        ];
        $coords = ['1 Main St' => ['lat' => 36.10, 'lng' => -95.95]];

        $rich = LocalArea::enrichVenues($venues, $coords, 36.0814, -95.9987, 5, 10, 1);

        // Known address enriched with matching miles/fee.
        self::assertArrayHasKey('miles', $rich[0]);
        self::assertArrayHasKey('fee', $rich[0]);
        $expMiles = LocalArea::haversineMiles(36.0814, -95.9987, 36.10, -95.95);
        self::assertEqualsWithDelta(round($expMiles, 1), $rich[0]['miles'], 0.0001);
        self::assertEqualsWithDelta(LocalArea::deliveryFee($expMiles, 5, 10, 1), $rich[0]['fee'], 0.0001);

        // Unknown address passes through untouched.
        self::assertArrayNotHasKey('miles', $rich[1]);
        self::assertArrayNotHasKey('fee', $rich[1]);
        self::assertSame('Unknown', $rich[1]['name']);
    }

    public function testCityMilesRangeSpansAllVenues(): void
    {
        $city   = $this->areasFixture()['testville'];
        $coords = [
            '1 Main St, Testville, OK 74000'     => ['lat' => 36.10, 'lng' => -95.95],
            '2 Oak Ave, Testville, OK 74000'     => ['lat' => 36.05, 'lng' => -95.90],
            '3 Health Blvd, Testville, OK 74000' => ['lat' => 36.20, 'lng' => -96.00],
        ];

        $range = LocalArea::cityMilesRange($city, $coords, 36.0814, -95.9987);
        self::assertNotNull($range);

        $d = [
            LocalArea::haversineMiles(36.0814, -95.9987, 36.10, -95.95),
            LocalArea::haversineMiles(36.0814, -95.9987, 36.05, -95.90),
            LocalArea::haversineMiles(36.0814, -95.9987, 36.20, -96.00),
        ];
        self::assertEqualsWithDelta(round(min($d), 1), $range['min'], 0.0001);
        self::assertEqualsWithDelta(round(max($d), 1), $range['max'], 0.0001);
    }

    public function testCityMilesRangeNullWithoutCoords(): void
    {
        $city = $this->areasFixture()['testville'];
        self::assertNull(LocalArea::cityMilesRange($city, [], 36.0814, -95.9987));
    }

    public function testValidateServicesPassesCleanAndCatchesBroken(): void
    {
        self::assertSame([], LocalArea::validateServices($this->servicesFixture()));

        $broken = ['oops' => ['prefix' => 'oops', 'entities' => 'not_a_real_key']];
        $problems = LocalArea::validateServices($broken);
        self::assertNotEmpty($problems);
        self::assertStringContainsString("'entities'", implode("\n", $problems));
    }

    /**
     * occasionSlugForService maps each city-page service to its occasion slug,
     * and returns null for unknown services.
     */
    public function testOccasionSlugForService(): void
    {
        self::assertSame('sympathy', LocalArea::occasionSlugForService('funeral'));
        self::assertSame('hospital', LocalArea::occasionSlugForService('hospital'));
        self::assertSame('birthday', LocalArea::occasionSlugForService('birthday'));
        self::assertNull(LocalArea::occasionSlugForService('unknown'));
        self::assertNull(LocalArea::occasionSlugForService(''));
    }
}
