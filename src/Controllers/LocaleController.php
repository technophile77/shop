<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
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
     * The destination is the language-prefixed version of the HTTP_REFERER path
     * when it belongs to the same origin as the application (comparing scheme +
     * host).  Any existing lang prefix on the referer path is replaced with the
     * new code.  Cross-origin or absent referers redirect to `/{code}/` to
     * prevent open-redirect vulnerabilities.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters; `params['code']`
     *                                      is the requested locale code.
     *
     * @return Response 302 redirect response.
     *
     * @example
     *   // GET /lang/es  →  sets locale to 'es', redirects back or to /es/
     *   // GET /lang/xx  →  unrecognised; redirects to /
     */
    public function switch(Request $request, array $params = []): Response
    {
        $code = $params['code'] ?? '';

        if (!in_array($code, ['en', 'es'], true)) {
            return $this->redirect('/');
        }

        Lang::set($code);

        // Determine a safe redirect destination: the same page in the new language.
        $appUrl  = rtrim((string) (\App\Core\Config::get('APP_URL', '')), '/');
        $safeBack = '/' . $code . '/';

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer !== '') {
            $refOrigin = parse_url($referer, PHP_URL_SCHEME) . '://' . parse_url($referer, PHP_URL_HOST);
            $appOrigin = parse_url($appUrl, PHP_URL_SCHEME) . '://' . parse_url($appUrl, PHP_URL_HOST);
            $isSameOrigin = ($appUrl !== '' && $refOrigin === $appOrigin)
                         || ($appUrl === '');

            if ($isSameOrigin) {
                $refPath = parse_url($referer, PHP_URL_PATH) ?? '/';
                // Strip existing lang prefix from referer path so we can add the new one.
                $refPath  = preg_replace('#^/(en|es)(/|$)#', '$2', $refPath) ?: '/';
                $refPath  = '/' . ltrim($refPath, '/');
                $safeBack = '/' . $code . $refPath;
            }
        }

        return $this->redirect($safeBack);
    }
}
