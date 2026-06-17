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
use App\Models\Occasion;
use App\Models\PaperColor;
use App\Models\Product;
use App\Models\ProductFlowerType;
use App\Support\Destination;
use App\Support\FlowerColorResolver;
use App\Support\Shop;

/**
 * Handles public-facing occasion bouquet pages.
 *
 * Routes served:
 *   GET /flowers/occasion/{slug} — product grid filtered by occasion.
 *
 * Each page shows an occasion-specific heading + blurb (from Shop::occasionCopy()),
 * a product grid with per-card CTAs, and a JSON-LD ItemList block for SEO.
 *
 * Product cards carry two CTAs:
 *  - "Add to Cart" button (buyable products only) — Phase 3 wires the panel.
 *  - "Customize this bouquet" link (all products) — links to /order with
 *    prefill query params ?product= and ?occasion=.
 *
 * @see \App\Models\Occasion     Provides occasion lookup by slug.
 * @see \App\Models\Product      Provides products filtered by occasion.
 * @see \App\Support\Shop        Provides isBuyable() and occasionCopy().
 * @see \App\Controllers\BaseController::render()
 */
final class ShopController extends BaseController
{
    /**
     * Render the occasion bouquet page for the given slug.
     *
     * Looks up the occasion by slug and returns 404 for unknown or inactive
     * occasions. Loads all products tagged with that occasion, builds JSON-LD
     * ItemList structured data, derives per-language occasion copy, and renders
     * the occasion view.
     *
     * @param Request              $request The current HTTP request.
     * @param array<string, mixed> $params  Route parameters; expects $params['slug'].
     *
     * @return Response HTML response, or 404 when the slug is unknown/inactive.
     *
     * @example
     *   // Matched by: GET /flowers/occasion/birthday
     *   $response = (new ShopController())->occasion($request, ['slug' => 'birthday']);
     */
    public function occasion(Request $request, array $params = []): Response
    {
        $slug     = (string) ($params['slug'] ?? '');
        $occasion = Occasion::findBySlug($slug);

        if ($occasion === null || !(bool) $occasion['active']) {
            return Response::notFound();
        }

        $lang     = Lang::current();
        $products = Product::byOccasion($slug);
        $copy     = Shop::occasionCopy($slug, $lang);
        $appUrl   = rtrim((string) Config::get('APP_URL', ''), '/');

        // Record the "send flowers to this venue" destination when arriving from
        // a venue card on a city page (Phase 5 emits these query params). The
        // destination persists in the session through checkout.
        if ($request->query('venue_name') !== null || $request->query('dest_city') !== null) {
            Destination::set(Destination::normalize([
                'service'       => $request->query('dest_service'),
                'city'          => $request->query('dest_city'),
                'venue_name'    => $request->query('venue_name'),
                'venue_address' => $request->query('venue_address'),
                'occasion'      => $slug,
            ]));
        }

        // Pre-load the global catalogs once, then resolve per-product flower-type
        // color options for buyable products (the add-to-cart panel data).
        $paperColors    = PaperColor::allActive();
        $addons         = Addon::allActive();
        $flowerTypesById = [];
        foreach (FlowerType::allActive() as $_ft) {
            $flowerTypesById[(int) $_ft['id']] = $_ft;
        }
        $flowerColorsById = [];
        foreach (FlowerColor::allActive() as $_fc) {
            $flowerColorsById[(int) $_fc['id']] = $_fc;
        }
        $flowerTypeColorMap = FlowerTypeColor::map();

        $productColorOptions = [];
        foreach ($products as $_p) {
            if (!Shop::isBuyable($_p)) {
                continue;
            }
            $pid = (int) $_p['id'];
            $productColorOptions[$pid] = FlowerColorResolver::availableColorsForProduct(
                ProductFlowerType::flowerTypeIdsForProduct($pid),
                $flowerTypesById,
                $flowerTypeColorMap,
                $flowerColorsById,
                isset($_p['pictured_flower_color_id']) ? (int) $_p['pictured_flower_color_id'] : null
            );
        }

        // Build JSON-LD ItemList — same pattern as ProductController.
        $listItems = [];
        $position  = 1;
        foreach ($products as $_p) {
            $name  = $_p['name_' . $lang] ?? $_p['name_en'] ?? '';
            $desc  = $_p['description_' . $lang] ?? $_p['description_en'] ?? '';
            $image = !empty($_p['image_path'])
                ? $appUrl . '/public/uploads/products/' . $_p['image_path']
                : $appUrl . '/public/assets/images/placeholder-flower.jpg';

            $listItems[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'item'     => [
                    '@type'       => 'Product',
                    'name'        => $name,
                    'description' => $desc,
                    'image'       => $image,
                    'offers'      => [
                        '@type'         => 'Offer',
                        'priceCurrency' => 'USD',
                        'price'         => !empty($_p['price_from'])
                            ? number_format((float) $_p['price_from'], 2)
                            : '0.00',
                        'availability'  => 'https://schema.org/InStock',
                    ],
                ],
            ];
        }

        $pageTitle = htmlspecialchars($copy['heading']);
        $jsonLd    = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => $pageTitle,
            'itemListElement' => $listItems,
        ];

        $html = $this->render('public/occasion', [
            'occasion'            => $occasion,
            'products'            => $products,
            'copy'                => $copy,
            'lang'                => $lang,
            'slug'                => $slug,
            'pageTitle'           => $pageTitle,
            'metaDesc'            => $copy['blurb'],
            'jsonLd'              => $jsonLd,
            'productColorOptions' => $productColorOptions,
            'paperColors'         => $paperColors,
            'addons'              => $addons,
            'destination'         => Destination::get(),
            'csrfToken'           => $request->csrfToken(),
        ]);

        return Response::html($html);
    }
}
