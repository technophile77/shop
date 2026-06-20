<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\Addon;
use App\Models\PaperColor;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\BouquetColorOptions;

/**
 * Handles the public-facing products gallery routes.
 *
 * Two routes are served:
 *   GET /products         — full gallery with all categories and products.
 *   GET /products/{slug}  — gallery filtered to a single category by slug.
 *
 * Both routes render the same `public/products` view, varying only the
 * $products and $activeSlug template variables so the filter bar can
 * highlight the active tab correctly.
 *
 * @see \App\Models\Product         Supplies the product rows.
 * @see \App\Models\ProductCategory Supplies the category tabs.
 * @see \App\Controllers\BaseController::render()
 */
final class ProductController extends BaseController
{
    /**
     * Shows the full products gallery with all active categories and products.
     *
     * Route: GET /products
     *
     * Loads all active categories (for the filter bar) and all active products
     * (for the product grid). The active filter tab is set to 'all'. When no
     * products exist an empty-state CTA is shown instead of the grid.
     *
     * @param Request $request The current HTTP request.
     * @param array   $params  Route parameters (unused for this route).
     *
     * @return Response An HTML response containing the rendered products page.
     *
     * @example
     *   // Matched by: GET /products
     *   $response = (new ProductController())->index($request);
     */
    public function index(Request $request, array $params = []): Response
    {
        $lang       = Lang::current();
        $categories = ProductCategory::allActive();
        $products   = Product::allActive();

        $pageTitle = Settings::get('products_page_title_' . $lang)
            ?? Settings::get('products_page_title_en', __t('products.title'));

        $metaDesc = Settings::get('products_meta_desc_' . $lang)
            ?? Settings::get('products_meta_desc_en', '');

        $appUrl  = rtrim((string) \App\Core\Config::get('APP_URL', ''), '/');
        $ogImage = null;
        foreach ($products as $_p) {
            if (!empty($_p['image_path'])) {
                $ogImage = $appUrl . '/public/uploads/products/' . $_p['image_path'];
                break;
            }
        }

        $listItems = [];
        $position  = 1;
        foreach ($products as $_p) {
            $name  = $_p['name_' . $lang] ?? $_p['name_en'] ?? '';
            $desc  = $_p['description_' . $lang] ?? $_p['description_en'] ?? '';
            $image = !empty($_p['image_path'])
                ? $appUrl . '/public/uploads/products/' . $_p['image_path']
                : $appUrl . '/public/assets/images/placeholder-flower.jpg';
            $item = [
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
                        'price'         => !empty($_p['price_from']) ? number_format((float)$_p['price_from'], 2) : '0.00',
                        'availability'  => 'https://schema.org/InStock',
                    ],
                ],
            ];
            $listItems[] = $item;
        }
        $jsonLd = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => $pageTitle,
            'itemListElement' => $listItems,
        ];

        $html = $this->render('public/products', [
            'categories' => $categories,
            'products'   => $products,
            'activeSlug' => 'all',
            'lang'       => $lang,
            'pageTitle'  => $pageTitle,
            'metaDesc'   => $metaDesc,
            'ogImage'    => $ogImage,
            'jsonLd'     => $jsonLd,
            'productColorOptions' => BouquetColorOptions::forProducts($products),
            'paperColors'         => PaperColor::allActive(),
            'addons'              => Addon::allActive(),
            'csrfToken'           => $request->csrfToken(),
        ]);

        return Response::html($html);
    }

    /**
     * Shows the products gallery filtered to a specific category slug.
     *
     * Route: GET /products/{slug}
     *
     * Looks up the category by slug; returns a 404 response when the slug does
     * not match any active category. Loads all active categories (for the
     * filter bar tabs) and only the products belonging to the matched category.
     *
     * @param Request $request The current HTTP request.
     * @param array   $params  Route parameters; expects $params['slug'].
     *
     * @return Response An HTML response, or a 404 response when the slug is unknown.
     *
     * @example
     *   // Matched by: GET /products/bouquets
     *   $response = (new ProductController())->byCategory($request, ['slug' => 'bouquets']);
     */
    public function byCategory(Request $request, array $params = []): Response
    {
        $slug     = $params['slug'] ?? '';
        $category = ProductCategory::findBySlug($slug);

        if ($category === null) {
            return Response::notFound();
        }

        $lang       = Lang::current();
        $categories = ProductCategory::allActive();
        $products   = Product::byCategory((int) $category['id']);

        $categoryName = $category['name_' . $lang] ?? $category['name_en'];
        $siteTitle    = Settings::get('shop_name', "Perla's Flowers");
        $pageTitle    = htmlspecialchars($categoryName) . ' — ' . htmlspecialchars($siteTitle);

        $metaDesc = Settings::get('products_meta_desc_' . $lang)
            ?? Settings::get('products_meta_desc_en')
            ?? sprintf(
                $lang === 'es'
                    ? 'Explora nuestros %s en %s. Arreglos florales frescos con pedidos personalizados disponibles.'
                    : 'Shop our %s at %s. Fresh floral arrangements with custom orders available.',
                strtolower((string) ($category['name_' . $lang] ?? $category['name_en'] ?? '')),
                \App\Core\Config::get('BUSINESS_NAME', '')
            );

        $appUrl  = rtrim((string) \App\Core\Config::get('APP_URL', ''), '/');
        $ogImage = null;
        foreach ($products as $_p) {
            if (!empty($_p['image_path'])) {
                $ogImage = $appUrl . '/public/uploads/products/' . $_p['image_path'];
                break;
            }
        }

        $listItems = [];
        $position  = 1;
        foreach ($products as $_p) {
            $name  = $_p['name_' . $lang] ?? $_p['name_en'] ?? '';
            $desc  = $_p['description_' . $lang] ?? $_p['description_en'] ?? '';
            $image = !empty($_p['image_path'])
                ? $appUrl . '/public/uploads/products/' . $_p['image_path']
                : $appUrl . '/public/assets/images/placeholder-flower.jpg';
            $item = [
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
                        'price'         => !empty($_p['price_from']) ? number_format((float)$_p['price_from'], 2) : '0.00',
                        'availability'  => 'https://schema.org/InStock',
                    ],
                ],
            ];
            $listItems[] = $item;
        }
        $jsonLd = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => $pageTitle,
            'itemListElement' => $listItems,
        ];

        $html = $this->render('public/products', [
            'categories' => $categories,
            'products'   => $products,
            'activeSlug' => $slug,
            'lang'       => $lang,
            'pageTitle'  => $pageTitle,
            'metaDesc'   => $metaDesc,
            'ogImage'    => $ogImage,
            'jsonLd'     => $jsonLd,
            'productColorOptions' => BouquetColorOptions::forProducts($products),
            'paperColors'         => PaperColor::allActive(),
            'addons'              => Addon::allActive(),
            'csrfToken'           => $request->csrfToken(),
        ]);

        return Response::html($html);
    }
}
