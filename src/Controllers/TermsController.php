<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * Renders the public Terms & Conditions page.
 *
 * Exists to satisfy the Terms & Conditions URL required by Twilio's A2P
 * 10DLC campaign registration. The page is bilingual (EN/ES) and its title
 * is settings-driven so the shop owner can localise it from the admin panel
 * without a code deployment.
 *
 * Route: GET /terms
 *
 * @see \App\Controllers\ReturnPolicyController Sibling static-policy controller this mirrors.
 */
final class TermsController extends BaseController
{
    /**
     * Renders the terms & conditions page.
     *
     * Reads the page title from site settings (keyed by locale) so the shop
     * owner can update it through the admin panel without a deployment,
     * falling back to a sensible localised default.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response Rendered HTML response.
     *
     * @example
     *   // GET /terms
     *   $response = (new TermsController())->index($request);
     */
    public function index(Request $request, array $params = []): Response
    {
        $lang      = Lang::current();
        $pageTitle = (string) Settings::get(
            'terms_page_title_' . $lang,
            $lang === 'es' ? 'Términos y Condiciones' : 'Terms & Conditions'
        );

        $html = $this->render('public/terms', [
            'lang'      => $lang,
            'pageTitle' => $pageTitle,
        ]);

        return Response::html($html);
    }
}
