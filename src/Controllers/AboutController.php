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
     * Reads the page title from site settings so the shop owner can update it
     * through the admin panel without a code deployment.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response Rendered HTML response.
     *
     * @example
     *   // GET /about
     */
    public function index(Request $request, array $params = []): Response
    {
        $lang      = Lang::current();
        $pageTitle = (string) Settings::get('about_page_title_' . $lang, 'Our Story');

        $html = $this->render('public/about', [
            'lang'      => $lang,
            'pageTitle' => $pageTitle,
        ]);

        return Response::html($html);
    }
}
