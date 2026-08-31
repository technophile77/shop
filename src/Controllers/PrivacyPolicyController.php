<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * Renders the public Privacy Policy page.
 *
 * Exists to satisfy the Privacy Policy URL required by Twilio's A2P 10DLC
 * campaign registration and by Google Merchant Center / Google Ads. The page
 * is bilingual (EN/ES) and its title is settings-driven so the shop owner can
 * localise it from the admin panel without a code deployment.
 *
 * Route: GET /privacy
 *
 * @see \App\Controllers\ReturnPolicyController Sibling static-policy controller this mirrors.
 */
final class PrivacyPolicyController extends BaseController
{
    /**
     * Renders the privacy policy page.
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
     *   // GET /privacy
     *   $response = (new PrivacyPolicyController())->index($request);
     */
    public function index(Request $request, array $params = []): Response
    {
        $lang      = Lang::current();
        $pageTitle = (string) Settings::get(
            'privacy_page_title_' . $lang,
            $lang === 'es' ? 'Política de Privacidad' : 'Privacy Policy'
        );

        $html = $this->render('public/privacy', [
            'lang'      => $lang,
            'pageTitle' => $pageTitle,
        ]);

        return Response::html($html);
    }
}
