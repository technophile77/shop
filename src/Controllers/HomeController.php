<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * Handles the public home page of Perla's Flowers.
 *
 * Fetches up to six featured, active products with their category names,
 * injects a CSRF token for the promotion signup form, and renders the
 * home view wrapped in the public layout.
 *
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 * @see \App\Core\Database               Supplies the read-only PDO connection.
 * @see \App\Core\Settings               Provides hero copy and button labels.
 */
class HomeController extends BaseController
{
    /**
     * Render the public home page.
     *
     * Fetches featured products from the database using the current locale so
     * the correct category name (name_en or name_es) is selected in SQL, which
     * avoids an N+1 PHP-side lookup. The CSRF token is generated here so the
     * promotion signup form can embed it as a hidden field without a second
     * Request instantiation in the view. The page title is read from the
     * `home_page_title_{lang}` setting (e.g. `home_page_title_en`), falling
     * back to the `BUSINESS_NAME` env value when the setting is empty.
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

        // Fetch up to 6 featured, active products with their localised category name.
        // The category name column alias avoids a PHP-side join and keeps the query
        // a single round-trip.
        $sql = <<<SQL
            SELECT
                p.*,
                c.name_{$lang} AS category_name
            FROM products p
            JOIN product_categories c ON c.id = p.category_id
            WHERE p.featured = 1
              AND p.active   = 1
            ORDER BY p.sort_order ASC
            LIMIT 6
            SQL;

        $stmt     = Database::ro()->query($sql);
        $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $csrfToken = $request->csrfToken();

        $metaDesc = Settings::get('hero_subtext_' . $lang, '');

        return Response::html(
            $this->render('public/home', [
                'products'  => $products,
                'csrfToken' => $csrfToken,
                'pageTitle' => (string) Settings::get('home_page_title_' . $lang, Config::get('BUSINESS_NAME', '')),
                'metaDesc'  => $metaDesc,
            ])
        );
    }
}
