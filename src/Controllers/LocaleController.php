<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;

/**
 * Switches the active UI language and redirects back to the referring page.
 *
 * Route: GET /lang/{code}
 *
 * Validates the requested locale against the supported list, persists it in
 * the session via Lang::set(), then sends the visitor back to where they came
 * from — or to the site root if the referer is absent or cross-origin.
 */
final class LocaleController extends BaseController
{
    /**
     * Switches the active locale and redirects.
     *
     * The destination is the HTTP_REFERER when it belongs to the same origin
     * as the application (comparing scheme + host).  Cross-origin or absent
     * referers redirect to `/` to prevent open-redirect vulnerabilities.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters; `params['code']`
     *                                      is the requested locale code.
     *
     * @return Response 302 redirect response.
     *
     * @example
     *   // GET /lang/es  →  sets locale to 'es', redirects back or to /
     *   // GET /lang/xx  →  unrecognised; redirects to /
     */
    public function switch(Request $request, array $params = []): Response
    {
        $code = $params['code'] ?? '';

        if (!in_array($code, ['en', 'es'], true)) {
            return $this->redirect('/');
        }

        Lang::set($code);

        // Determine a safe redirect destination.
        $referer  = $_SERVER['HTTP_REFERER'] ?? '';
        $appUrl   = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $safeBack = '/';

        if ($referer !== '' && $appUrl !== '') {
            // Only redirect to referer when it shares the same origin.
            $refOrigin = parse_url($referer, PHP_URL_SCHEME) . '://'
                . parse_url($referer, PHP_URL_HOST);
            $appOrigin = parse_url($appUrl, PHP_URL_SCHEME) . '://'
                . parse_url($appUrl, PHP_URL_HOST);

            if ($refOrigin === $appOrigin) {
                $safeBack = $referer;
            }
        } elseif ($referer !== '') {
            // No APP_URL configured — trust the referer path only.
            $path = parse_url($referer, PHP_URL_PATH);
            if ($path !== false && $path !== null && $path !== '') {
                $safeBack = $path;
            }
        }

        return $this->redirect($safeBack);
    }
}
