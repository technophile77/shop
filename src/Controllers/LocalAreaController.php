<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Addon;
use App\Models\FlowerColor;
use App\Models\FlowerType;
use App\Models\FlowerTypeColor;
use App\Models\PaperColor;
use App\Models\Product;
use App\Models\ProductFlowerType;
use App\Models\ProductFlowerTypeColor;
use App\Support\Destination;
use App\Support\FlowerColorResolver;
use App\Support\LocalArea;
use App\Support\Shop;

/**
 * Serves the local-SEO city landing pages and their hub.
 *
 * Routes:
 *   GET /funeral-flowers-{city}                  → funeral()
 *   GET /hospital-flower-delivery-{city}         → hospital()
 *   GET /birthday-delivery-{city}                → birthday()
 *   GET /funeral-flowers-{city}/{venue}          → funeralVenue()
 *   GET /hospital-flower-delivery-{city}/{venue} → hospitalVenue()
 *   GET /flower-delivery-{city}                  → cityHub()
 *   GET /delivery-areas                          → hub()
 *
 * Each service action is a thin wrapper over {@see renderService()}, which
 * delegates all SEO logic (title/meta/JSON-LD) to the pure
 * {@see \App\Support\LocalArea} helper so the controller stays glue-only. An
 * unknown city slug yields a 404.
 *
 * @see \App\Support\LocalArea         Pure logic + data loaders.
 * @see config/local-areas.php         City/venue data.
 * @see config/local-services.php      Service definitions.
 */
final class LocalAreaController extends BaseController
{
    /**
     * Funeral-flowers landing page for a city.
     *
     * Route: GET /funeral-flowers-{city}
     *
     * @param Request              $request The current HTTP request.
     * @param array<string,string> $params  Route params; expects 'city'.
     *
     * @return Response Rendered HTML, or 404 for an unknown city.
     *
     * @example
     *   // Matched by: GET /funeral-flowers-broken-arrow
     *   (new LocalAreaController())->funeral($request, ['city' => 'broken-arrow']);
     */
    public function funeral(Request $request, array $params = []): Response
    {
        return $this->renderService($request, 'funeral', $params);
    }

    /**
     * Hospital flower-delivery landing page for a city.
     *
     * Route: GET /hospital-flower-delivery-{city}
     *
     * @param Request              $request The current HTTP request.
     * @param array<string,string> $params  Route params; expects 'city'.
     *
     * @return Response Rendered HTML, or 404 for an unknown city.
     */
    public function hospital(Request $request, array $params = []): Response
    {
        return $this->renderService($request, 'hospital', $params);
    }

    /**
     * Birthday flower-delivery landing page for a city.
     *
     * Route: GET /birthday-delivery-{city}
     *
     * @param Request              $request The current HTTP request.
     * @param array<string,string> $params  Route params; expects 'city'.
     *
     * @return Response Rendered HTML, or 404 for an unknown city.
     */
    public function birthday(Request $request, array $params = []): Response
    {
        return $this->renderService($request, 'birthday', $params);
    }

    /**
     * The "Delivery Areas" hub linking every city × service page.
     *
     * Route: GET /delivery-areas
     *
     * @param Request              $request The current HTTP request.
     * @param array<string,string> $params  Route params (unused).
     *
     * @return Response Rendered HTML hub page.
     */
    public function hub(Request $request, array $params = []): Response
    {
        $lang     = Lang::current();
        $areas    = LocalArea::areas();
        $services = LocalArea::services();

        $coords    = LocalArea::coords();
        $bizLat    = (float) Config::get('BUSINESS_LAT', 36.0814);
        $bizLng    = (float) Config::get('BUSINESS_LNG', -95.9987);
        $cityMiles = array_map(
            static fn (array $c): ?array => LocalArea::cityMilesRange($c, $coords, $bizLat, $bizLng),
            $areas,
        );

        $pageTitle = $lang === 'es'
            ? 'Áreas de Entrega de Flores — Tulsa y Alrededores'
            : 'Flower Delivery Areas — Tulsa & Surrounding Cities';
        $metaDesc  = $lang === 'es'
            ? "Perla's Flowers entrega flores en Tulsa, Jenks, Broken Arrow, Bixby y más. Vea todas nuestras áreas y servicios de entrega."
            : "Perla's Flowers delivers in Tulsa, Jenks, Broken Arrow, Bixby and more. See every city and service we deliver to.";

        $html = $this->render('public/local-areas-hub', [
            'lang'      => $lang,
            'areas'     => $areas,
            'services'  => $services,
            'cityMiles' => $cityMiles,
            'pageTitle' => $pageTitle,
            'metaDesc'  => $metaDesc,
        ]);

        return Response::html($html);
    }

    /**
     * Per-city hub: lists all services (funeral / hospital / birthday) for one city.
     *
     * Route: GET /flower-delivery-{city}. Lets visitors choose a service instead of
     * assuming one, and adds an indexable city landing page. 404 for unknown cities.
     *
     * @param Request              $request The current HTTP request.
     * @param array<string,string> $params  Route params; expects 'city'.
     *
     * @return Response Rendered HTML, or 404 for an unknown city.
     *
     * @example
     *   // Matched by: GET /flower-delivery-bixby
     *   (new LocalAreaController())->cityHub($request, ['city' => 'bixby']);
     */
    public function cityHub(Request $request, array $params = []): Response
    {
        $lang     = Lang::current();
        $areas    = LocalArea::areas();
        $services = LocalArea::services();

        $slug = $params['city'] ?? '';
        $city = LocalArea::cityBySlug($slug, $areas);
        if ($city === null) {
            return Response::notFound();
        }

        $coords    = LocalArea::coords();
        $bizLat    = (float) Config::get('BUSINESS_LAT', 36.0814);
        $bizLng    = (float) Config::get('BUSINESS_LNG', -95.9987);
        $cityMiles = LocalArea::cityMilesRange($city, $coords, $bizLat, $bizLng);

        $cityName  = (string) $city['name'];
        $pageTitle = $lang === 'es'
            ? "Entrega de Flores en {$cityName}, OK"
            : "Flower Delivery to {$cityName}, OK";
        $metaDesc  = $lang === 'es'
            ? "Entrega de flores hechas a mano en {$cityName}, OK — funerarias, hospitales y cumpleaños. Elige un servicio."
            : "Hand-crafted flower delivery to {$cityName}, OK — funeral homes, hospitals, and birthdays. Choose a service.";

        $html = $this->render('public/local-city-hub', [
            'lang'      => $lang,
            'city'      => $city,
            'citySlug'  => $slug,
            'services'  => $services,
            'cityMiles' => $cityMiles,
            'pageTitle' => $pageTitle,
            'metaDesc'  => $metaDesc,
        ]);

        return Response::html($html);
    }

    /**
     * Per-venue funeral-flowers landing page.
     *
     * Route: GET /funeral-flowers-{city}/{venue}
     *
     * @param Request              $request The current HTTP request.
     * @param array<string,string> $params  Route params; expects 'city' and 'venue'.
     *
     * @return Response Rendered HTML, or 404 for an unknown city or venue.
     *
     * @example
     *   // Matched by: GET /funeral-flowers-bixby/smith-funeral-home
     *   (new LocalAreaController())->funeralVenue($request, ['city' => 'bixby', 'venue' => 'smith-funeral-home']);
     */
    public function funeralVenue(Request $request, array $params = []): Response
    {
        return $this->renderVenue($request, 'funeral', $params);
    }

    /**
     * Per-venue hospital flower-delivery landing page.
     *
     * Route: GET /hospital-flower-delivery-{city}/{venue}
     *
     * @param Request              $request The current HTTP request.
     * @param array<string,string> $params  Route params; expects 'city' and 'venue'.
     *
     * @return Response Rendered HTML, or 404 for an unknown city or venue.
     */
    public function hospitalVenue(Request $request, array $params = []): Response
    {
        return $this->renderVenue($request, 'hospital', $params);
    }

    /**
     * Shared rendering path for the per-venue landing pages.
     *
     * Resolves the city (404 if unknown) and the venue by its URL slug within the
     * service's venue list (404 if unmatched), enriches the venue with a real
     * straight-line distance + estimated delivery fee, and renders the focused
     * `public/local-venue` page with a "Send flowers" CTA that pre-fills the venue
     * as the delivery destination.
     *
     * @param Request              $request The current HTTP request (for CSRF token).
     * @param string               $service 'funeral' or 'hospital' (venue-based services only).
     * @param array<string,string> $params  Route params; expects 'city' and 'venue'.
     *
     * @return Response Rendered HTML, or 404 when the city or venue is unknown.
     */
    private function renderVenue(Request $request, string $service, array $params): Response
    {
        $lang     = Lang::current();
        $areas    = LocalArea::areas();
        $services = LocalArea::services();

        $citySlug  = $params['city'] ?? '';
        $venueSlug = $params['venue'] ?? '';
        $city      = LocalArea::cityBySlug($citySlug, $areas);
        if ($city === null || !isset($services[$service])) {
            return Response::notFound();
        }

        $venue = LocalArea::venueBySlug($service, $city, $venueSlug, $services);
        if ($venue === null) {
            return Response::notFound();
        }

        // Enrich the single matched venue with distance + estimated fee.
        $coords = LocalArea::coords();
        $bizLat = (float) Config::get('BUSINESS_LAT', 36.0814);
        $bizLng = (float) Config::get('BUSINESS_LNG', -95.9987);
        $venue  = LocalArea::enrichVenues(
            [$venue],
            $coords,
            $bizLat,
            $bizLng,
            (float) Config::get('BUSINESS_DELIVERY_BASE_MILES', 5),
            (float) Config::get('BUSINESS_DELIVERY_BASE_FEE', 10),
            (float) Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1),
        )[0];

        $cityName    = (string) $city['name'];
        $state       = (string) ($city['state'] ?? 'OK');
        $venueName   = (string) $venue['name'];
        $occasionSlug = (string) LocalArea::occasionSlugForService($service);

        // "Send flowers" CTA → the occasion bouquet page, pre-filling this venue
        // as the delivery destination (mirrors the city-page venue cards).
        $sendUrl = '/' . $lang . '/flowers/occasion/' . $occasionSlug
            . '?dest_service='  . urlencode($service)
            . '&dest_city='     . urlencode($citySlug)
            . '&venue_name='    . urlencode($venueName)
            . '&venue_address=' . urlencode((string) $venue['address']);

        $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode((string) $venue['address']);

        $pageTitle = $lang === 'es'
            ? "Entrega de Flores a {$venueName} — {$cityName}, {$state}"
            : "Flower Delivery to {$venueName} — {$cityName}, {$state}";

        if ($service === 'funeral') {
            $metaDesc = $lang === 'es'
                ? "Envíe flores fúnebres y de condolencia a {$venueName} en {$cityName}, {$state}. Arreglos hechos a mano por Perla's Flowers, entregados a tiempo para el servicio."
                : "Send funeral and sympathy flowers to {$venueName} in {$cityName}, {$state}. Locally handcrafted arrangements from Perla's Flowers, delivered ahead of the service.";
        } else {
            $metaDesc = $lang === 'es'
                ? "Envíe flores de pronta recuperación a {$venueName} en {$cityName}, {$state}. Ramos hechos a mano por Perla's Flowers, entregados el mismo día cuando es posible."
                : "Send get-well flowers to {$venueName} in {$cityName}, {$state}. Locally handcrafted bouquets from Perla's Flowers, delivered same-day when possible.";
        }

        // Focused schema.org Service node naming the venue as the place served.
        $appUrl  = rtrim((string) Config::get('APP_URL', ''), '/');
        $pageUrl = $appUrl . '/' . $lang
            . LocalArea::venuePath($service, $citySlug, $venueSlug, $services);
        $jsonLd  = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Service',
            'serviceType' => (string) ($services[$service]['service_type'] ?? 'Flower delivery'),
            'name'        => $pageTitle,
            'url'         => $pageUrl,
            'provider'    => [
                '@type'     => 'Florist',
                'name'      => (string) Config::get('BUSINESS_NAME', ''),
                'telephone' => (string) Config::get('BUSINESS_PHONE', ''),
                'url'       => $appUrl,
            ],
            'areaServed'  => [
                '@type'   => 'Place',
                'name'    => $venueName,
                'address' => (string) $venue['address'],
            ],
        ];

        $html = $this->render('public/local-venue', [
            'lang'         => $lang,
            'service'      => $service,
            'serviceDef'   => $services[$service],
            'citySlug'     => $citySlug,
            'city'         => $city,
            'venue'        => $venue,
            'venueSlug'    => $venueSlug,
            'occasionSlug' => $occasionSlug,
            'sendUrl'      => $sendUrl,
            'mapsUrl'      => $mapsUrl,
            'jsonLd'       => $jsonLd,
            'pageTitle'    => $pageTitle,
            'metaDesc'     => $metaDesc,
            'csrfToken'    => $request->csrfToken(),
        ]);

        return Response::html($html);
    }

    /**
     * Shared rendering path for the three service pages.
     *
     * Looks up the city, returns 404 if unknown, then builds the localized
     * title/meta/JSON-LD via {@see LocalArea} and renders the shared
     * `public/local-area` view.
     *
     * @param Request              $request The current HTTP request (for CSRF token).
     * @param string               $service One of 'funeral'|'hospital'|'birthday'.
     * @param array<string,string> $params  Route params; expects 'city'.
     *
     * @return Response Rendered HTML, or 404 when the city slug is unknown.
     */
    private function renderService(Request $request, string $service, array $params): Response
    {
        $lang     = Lang::current();
        $areas    = LocalArea::areas();
        $services = LocalArea::services();

        $slug = $params['city'] ?? '';
        $city = LocalArea::cityBySlug($slug, $areas);
        if ($city === null || !isset($services[$service])) {
            return Response::notFound();
        }

        $appUrl  = rtrim((string) Config::get('APP_URL', ''), '/');
        $pagePath = LocalArea::path($service, $slug, $services);
        $pageUrl  = $appUrl . '/' . $lang . $pagePath;

        $business = [
            'name'      => (string) Config::get('BUSINESS_NAME', ''),
            'telephone' => (string) Config::get('BUSINESS_PHONE', ''),
            'url'       => $appUrl,
            'street'    => (string) Config::get('BUSINESS_STREET_ADDRESS', ''),
            'city'      => (string) Config::get('BUSINESS_CITY', 'Tulsa'),
            'state'     => (string) Config::get('BUSINESS_STATE', 'OK'),
            'postal'    => (string) Config::get('BUSINESS_POSTAL_CODE', ''),
            'pageUrl'   => $pageUrl,
        ];

        $jsonLd = LocalArea::buildJsonLd($service, $city, $lang, $services, $business);

        // Attach straight-line distance + estimated delivery fee to each venue,
        // using the same geocode coords + Haversine/fee math as the order form.
        $coords = LocalArea::coords();
        $bizLat = (float) Config::get('BUSINESS_LAT', 36.0814);
        $bizLng = (float) Config::get('BUSINESS_LNG', -95.9987);
        $venues = LocalArea::enrichVenues(
            LocalArea::venues($service, $city, $services),
            $coords,
            $bizLat,
            $bizLng,
            (float) Config::get('BUSINESS_DELIVERY_BASE_MILES', 5),
            (float) Config::get('BUSINESS_DELIVERY_BASE_FEE', 10),
            (float) Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1),
        );

        // Real min/max distance to this city's venues, for the "miles from our shop" line.
        $cityMiles = LocalArea::cityMilesRange($city, $coords, $bizLat, $bizLng);

        // Birthday pages have no venue list — instead they show the birthday-tagged
        // bouquets inline with add-to-cart, recording the city as the destination.
        $bouquetProducts     = [];
        $productColorOptions = [];
        $paperColors         = [];
        $addons              = [];
        $occasionLabel       = '';
        if ($service === 'birthday') {
            $occasionSlug    = LocalArea::occasionSlugForService('birthday');
            $bouquetProducts = Product::byOccasion((string) $occasionSlug);
            $occasionLabel   = $lang === 'es' ? 'Cumpleaños' : 'Birthday';

            $paperColors = PaperColor::allActive();
            $addons      = Addon::allActive();

            $flowerTypesById = [];
            foreach (FlowerType::allActive() as $_ft) {
                $flowerTypesById[(int) $_ft['id']] = $_ft;
            }
            $flowerColorsById = [];
            foreach (FlowerColor::allActive() as $_fc) {
                $flowerColorsById[(int) $_fc['id']] = $_fc;
            }
            $flowerTypeColorMap = FlowerTypeColor::map();

            foreach ($bouquetProducts as $_bp) {
                if (!Shop::isBuyable($_bp)) {
                    continue;
                }
                $pid = (int) $_bp['id'];
                $productColorOptions[$pid] = FlowerColorResolver::availableColorsForProduct(
                    ProductFlowerType::flowerTypeIdsForProduct($pid),
                    $flowerTypesById,
                    $flowerTypeColorMap,
                    $flowerColorsById,
                    ProductFlowerTypeColor::mapForProduct($pid),
                );
            }

            // Record the city itself (no specific venue) as the delivery destination.
            Destination::set(Destination::normalize([
                'service'  => 'birthday',
                'city'     => $slug,
                'occasion' => 'birthday',
            ]));
        }

        $html = $this->render('public/local-area', [
            'lang'                => $lang,
            'service'             => $service,
            'serviceDef'          => $services[$service],
            'services'            => $services,
            'areas'               => $areas,
            'citySlug'            => $slug,
            'city'                => $city,
            'cityMiles'           => $cityMiles,
            'headline'            => LocalArea::serviceHeadline($service, $city, $lang, $services),
            'venueHeading'        => LocalArea::venueHeading($service, $city, $lang, $services),
            'venues'              => $venues,
            'faqItems'            => LocalArea::faqItems($service, $city, $lang),
            'jsonLd'              => $jsonLd,
            'pageTitle'           => LocalArea::serviceTitle($service, $city, $lang, $services),
            'metaDesc'            => LocalArea::serviceMeta($service, $city, $lang, $services),
            'bouquetProducts'     => $bouquetProducts,
            'productColorOptions' => $productColorOptions,
            'paperColors'         => $paperColors,
            'addons'              => $addons,
            'occasionLabel'       => $occasionLabel,
            'csrfToken'           => $request->csrfToken(),
        ]);

        return Response::html($html);
    }
}
