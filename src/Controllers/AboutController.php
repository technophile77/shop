<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * Renders the public About / Our Story page.
 *
 * Route: GET /about
 */
final class AboutController extends BaseController
{
    /**
     * Renders the about page.
     *
     * Reads the page title and the meta description from site settings so the
     * shop owner can update them through the admin panel without a code
     * deployment. The description is resolved by {@see metaDescription()}.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response Rendered HTML response.
     *
     * @example
     *   // GET /about
     *
     * @see self::metaDescription() Supplies the `metaDesc` template variable.
     */
    public function index(Request $request, array $params = []): Response
    {
        $lang      = Lang::current();
        $pageTitle = (string) Settings::get('about_page_title_' . $lang, 'Our Story');

        $html = $this->render('public/about', [
            'lang'      => $lang,
            'pageTitle' => $pageTitle,
            'metaDesc'  => self::metaDescription($lang),
        ]);

        return Response::html($html);
    }

    /**
     * Resolves the About page's meta description for one language.
     *
     * Search engines show this text under the page title, so a missing value
     * would leave the layout's `<meta name="description">` empty. The
     * `about_meta_desc_{lang}` setting wins; when it is absent or blank
     * (empty, whitespace-only or NULL) the English description is used
     * instead, and '' when that is missing too. English never borrows the
     * Spanish text. This is the same language-then-English fallback the
     * products page applies to `products_meta_desc_*`.
     *
     * @param string $lang Locale code of the page being rendered, 'en' or 'es'
     *                     (as returned by Lang::current()).
     *
     * @return string The stored description exactly as saved (no trimming or
     *                escaping; the layout escapes it), or '' when neither the
     *                language's nor the English description is set.
     *
     * @example
     *   // about_meta_desc_en = 'Meet Perla, a Tulsa florist.'; about_meta_desc_es is blank.
     *   AboutController::metaDescription('es'); // 'Meet Perla, a Tulsa florist.'
     *
     * @example
     *   // Neither description is set.
     *   AboutController::metaDescription('en'); // ''
     *
     * @see self::index() Passes the result to the layout as `metaDesc`.
     * @see ProductController::index() Resolves products_meta_desc_* the same way.
     * @see \App\Core\Settings::get() Treats blank rows as missing, which makes the fallback work.
     */
    public static function metaDescription(string $lang): string
    {
        return (string) Settings::get('about_meta_desc_' . $lang, Settings::get('about_meta_desc_en', ''));
    }
}
