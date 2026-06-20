<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * Renders the public Return & Refund Policy page.
 *
 * Exists to satisfy Google Merchant Center's requirement for a clear,
 * site-wide-linked returns policy. The page is bilingual (EN/ES) and its
 * title is settings-driven so the shop owner can localise it from the admin
 * panel without a code deployment.
 *
 * Route: GET /returns
 */
final class ReturnPolicyController extends BaseController
{
    /**
     * Renders the return & refund policy page.
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
     *   // GET /returns
     *   $response = (new ReturnPolicyController())->index($request);
     */
    public function index(Request $request, array $params = []): Response
    {
        $lang      = Lang::current();
        $pageTitle = (string) Settings::get(
            'returns_page_title_' . $lang,
            $lang === 'es' ? 'Política de Devoluciones y Reembolsos' : 'Return & Refund Policy'
        );

        $html = $this->render('public/returns', [
            'lang'      => $lang,
            'pageTitle' => $pageTitle,
        ]);

        return Response::html($html);
    }
}
