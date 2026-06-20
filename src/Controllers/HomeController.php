<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\Addon;
use App\Models\PaperColor;
use App\Models\Product;
use App\Services\BouquetColorOptions;
use App\Services\OccasionMenu;

/**
 * Handles the public home page of Perla's Flowers.
 *
 * Fetches up to six featured, active products with their category names,
 * injects a CSRF token for the promotion signup form, and renders the
 * home view wrapped in the public layout.
 *
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 * @see \App\Models\Product              Supplies the featured-products query.
 * @see \App\Core\Settings               Provides hero copy and button labels.
 */
class HomeController extends BaseController
{
    /**
     * Render the public home page.
     *
     * Fetches up to six featured products via {@see Product::featured()} and
     * resolves their add-to-cart options (per-product flower colors, active
     * paper colors, and add-ons) so the Featured Products grid can reuse the
     * shared bouquet card with its add-to-cart panel. The CSRF token is
     * generated here so both the add-to-cart forms and the promotion signup
     * form can embed it without a second Request instantiation in the view. The
     * page title is read from the `home_page_title_{lang}` setting (e.g.
     * `home_page_title_en`), falling back to the `BUSINESS_NAME` env value when
     * the setting is empty.
     *
     * @param Request              $request The current HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response HTML response for the home page.
     *
     * @example
     *   // Dispatched automatically by the router for GET /
     *   $response = (new HomeController())->index($request, []);
     */
    public function index(Request $request, array $params = []): Response
    {
        $lang = Lang::current();

        $products = Product::featured(6);

        $csrfToken = $request->csrfToken();

        $metaDesc = Settings::get('hero_subtext_' . $lang, '');

        return Response::html(
            $this->render('public/home', [
                'products'           => $products,
                'occasionTiles'      => OccasionMenu::tiles($lang),
                'productColorOptions' => BouquetColorOptions::forProducts($products),
                'paperColors'        => PaperColor::allActive(),
                'addons'             => Addon::allActive(),
                'csrfToken' => $csrfToken,
                'pageTitle' => (string) Settings::get('home_page_title_' . $lang, Config::get('BUSINESS_NAME', '')),
                'metaDesc'  => $metaDesc,
            ])
        );
    }
}
