<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Addon;
use App\Models\Occasion;
use App\Models\PaperColor;
use App\Models\Product;
use App\Services\BouquetColorOptions;
use App\Services\OccasionMenu;
use App\Support\Analytics;
use App\Support\Destination;
use App\Support\Shop;
use App\Support\Slug;

/**
 * Handles public-facing occasion bouquet pages.
 *
 * Routes served:
 *   GET /flowers/occasion/{slug} — product grid filtered by occasion.
 *
 * Each page shows an occasion-specific heading + blurb (admin-editable per
 * occasion via Shop::occasionCopyFromRow(), or Shop::occasionGroupCopy() for
 * virtual group pages),
 * a product grid with per-card CTAs, and a JSON-LD ItemList block for SEO.
 *
 * Product cards carry two CTAs:
 *  - "Add to Cart" button (buyable products only) — Phase 3 wires the panel.
 *  - "Customize this bouquet" link (all products) — links to /order with
 *    prefill query params ?product= and ?occasion=.
 *
 * @see \App\Models\Occasion     Provides occasion lookup by slug.
 * @see \App\Models\Product      Provides products filtered by occasion.
 * @see \App\Support\Shop        Provides isBuyable() and occasion copy resolution.
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
        $slug  = (string) ($params['slug'] ?? '');
        $group = Shop::occasionGroup($slug);
        $lang  = Lang::current();

        if ($group !== null) {
            // Virtual group page (e.g. hospital = get-well + new-baby) — union the
            // member occasions' products under one page; copy lives in code.
            $occasion = ['slug' => $slug, 'name_en' => ucfirst($slug), 'name_es' => ucfirst($slug), 'active' => 1];
            $products = Product::byOccasions($group);
            $copy     = Shop::occasionGroupCopy($slug, $lang);
        } else {
            $occasion = Occasion::findBySlug($slug);
            if ($occasion === null || !(bool) $occasion['active']) {
                return Response::notFound();
            }
            $products = Product::byOccasion($slug);
            $copy     = Shop::occasionCopyFromRow($occasion, $lang);
        }

        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');

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

        // Resolve per-product flower-type color options for buyable products
        // (the add-to-cart panel data).
        $paperColors         = PaperColor::allActive();
        $addons              = Addon::allActive();
        $productColorOptions = BouquetColorOptions::forProducts($products);

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

    /**
     * Render the individual bouquet (product) detail page.
     *
     * URLs are id-anchored: `/flowers/{name-slug}-{id}`. The trailing id is
     * authoritative, so a stale slug (after a rename) 301-redirects to the
     * canonical URL. Returns 404 for a missing or inactive product. Buyable
     * products get the full add-to-cart panel; unpriced ones get only the
     * "Customize this bouquet" quote link. Emits Product + BreadcrumbList JSON-LD.
     *
     * @param Request              $request The current HTTP request.
     * @param array<string, mixed> $params  Route parameters; expects $params['ref'].
     *
     * @return Response HTML page, a 301 to the canonical URL, or 404.
     *
     * @example
     *   // Matched by: GET /flowers/whisper-of-love-12
     *   (new ShopController())->product($request, ['ref' => 'whisper-of-love-12']);
     */
    public function product(Request $request, array $params = []): Response
    {
        $ref = (string) ($params['ref'] ?? '');
        $id  = Slug::parseId($ref);
        if ($id <= 0) {
            return Response::notFound();
        }

        $lang    = Lang::current();
        $product = Product::findActive($id);
        if ($product === null) {
            return Response::notFound();
        }

        // Canonicalise: 301 a stale/incorrect slug to the current canonical ref.
        $canonical = Slug::productRef($product);
        if ($ref !== $canonical) {
            return $this->redirect('/' . $lang . '/flowers/' . $canonical, 301);
        }

        $appUrl       = rtrim((string) Config::get('APP_URL', ''), '/');
        $canonicalUrl = $appUrl . '/' . $lang . '/flowers/' . $canonical;
        $buyable      = Shop::isBuyable($product);

        // Per-flower-type color options for the add-to-cart panel (buyable only).
        $colorOptions = $buyable
            ? (BouquetColorOptions::forProducts([$product])[(int) $product['id']] ?? [])
            : [];

        $name  = (string) ($product['name_' . $lang] ?? $product['name_en'] ?? '');
        $desc  = (string) ($product['description_' . $lang] ?? $product['description_en'] ?? '');
        $image = !empty($product['image_path'])
            ? $appUrl . '/public/uploads/products/' . $product['image_path']
            : $appUrl . '/public/assets/images/placeholder-flower.jpg';
        $categoryName = (string) ($product['category_name_' . $lang] ?? $product['category_name_en'] ?? '');
        $categorySlug = (string) ($product['category_slug'] ?? '');

        // Product JSON-LD.
        $productNode = [
            '@type'       => 'Product',
            'name'        => $name,
            'description' => $desc !== '' ? $desc : ($name . ' — handcrafted by ' . Config::get('BUSINESS_NAME', '') . ', Tulsa, OK.'),
            'image'       => $image,
            'sku'         => (string) $id,
            'brand'       => ['@type' => 'Brand', 'name' => (string) Config::get('BUSINESS_NAME', '')],
        ];
        if ($categoryName !== '') {
            $productNode['category'] = $categoryName;
        }
        if (!empty($product['price_from'])) {
            $productNode['offers'] = [
                '@type'         => 'Offer',
                'priceCurrency' => 'USD',
                'price'         => number_format((float) $product['price_from'], 2, '.', ''),
                'availability'  => 'https://schema.org/InStock',
                'url'           => $canonicalUrl,
            ];
        }

        // BreadcrumbList JSON-LD: Home → Products → Category → Name.
        $crumbs = [
            ['name' => $lang === 'es' ? 'Inicio' : 'Home',    'url' => $appUrl . '/' . $lang . '/'],
            ['name' => __t('nav.products'),                    'url' => $appUrl . '/' . $lang . '/products'],
        ];
        if ($categoryName !== '' && $categorySlug !== '') {
            $crumbs[] = ['name' => $categoryName, 'url' => $appUrl . '/' . $lang . '/products/' . $categorySlug];
        }
        $crumbs[] = ['name' => $name, 'url' => $canonicalUrl];

        $breadcrumbNode = [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn (int $i, array $c): array => [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $c['name'],
                    'item'     => $c['url'],
                ],
                array_keys($crumbs),
                $crumbs
            ),
        ];

        $jsonLd = ['@context' => 'https://schema.org', '@graph' => [$productNode, $breadcrumbNode]];

        $metaDesc = $desc !== ''
            ? mb_substr($desc, 0, 155)
            : sprintf(
                $lang === 'es'
                    ? '%s — ramo hecho a mano de %s, entrega en Tulsa, OK.'
                    : '%s — a handcrafted bouquet from %s, delivered in Tulsa, OK.',
                $name,
                (string) Config::get('BUSINESS_NAME', '')
            );

        $html = $this->render('public/product', [
            'product'      => $product,
            'lang'         => $lang,
            'buyable'      => $buyable,
            'colorOptions' => $colorOptions,
            'paperColors'  => PaperColor::allActive(),
            'addons'       => Addon::allActive(),
            'csrfToken'    => $request->csrfToken(),
            'crumbs'       => $crumbs,
            'jsonLd'       => $jsonLd,
            'pageTitle'    => $name,
            'metaDesc'     => $metaDesc,
            'ogImage'      => $image,
            'analyticsEvents' => [Analytics::viewItem($product)],
        ]);

        return Response::html($html);
    }

    /**
     * Render the "Shop by Occasion" hub — a grid of occasion tiles.
     *
     * The on-site entry point for browsing by occasion (the occasion pages were
     * previously reachable only from venue links + the sitemap).
     *
     * @param Request              $request The current HTTP request.
     * @param array<string, mixed> $params  Route parameters (none).
     *
     * @return Response HTML hub page.
     *
     * @example
     *   // Matched by: GET /flowers/occasions
     *   (new ShopController())->occasions($request, []);
     */
    public function occasions(Request $request, array $params = []): Response
    {
        $lang = Lang::current();

        $html = $this->render('public/occasions', [
            'lang'          => $lang,
            'occasionTiles' => OccasionMenu::tiles($lang),
            'pageTitle'     => $lang === 'es' ? 'Comprar Flores por Ocasión' : 'Shop Flowers by Occasion',
            'metaDesc'      => $lang === 'es'
                ? 'Compra flores por ocasión — cumpleaños, aniversario, condolencias, pronta recuperación, nuevo bebé y más. Entrega el mismo día en Tulsa, OK.'
                : 'Shop flowers by occasion — birthday, anniversary, sympathy, get well, new baby and more. Same-day delivery in Tulsa, OK.',
        ]);

        return Response::html($html);
    }
}
