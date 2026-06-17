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
 *   GET /funeral-flowers-{city}          → funeral()
 *   GET /hospital-flower-delivery-{city} → hospital()
 *   GET /birthday-delivery-{city}        → birthday()
 *   GET /delivery-areas                  → hub()
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
