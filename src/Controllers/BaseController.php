<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Lang;
use App\Core\Response;
use App\Core\Settings;

/**
 * Abstract base class that all application controllers extend.
 *
 * Provides shared view-rendering logic so every controller automatically
 * receives a populated set of template variables (language code, settings,
 * config closure, translation helper, page metadata) without boilerplate.
 * Also exposes shortcut methods for the two most-common Response factories.
 *
 * @see \App\Core\Response  Factory class used by redirect() and json().
 * @see \App\Core\Lang      Provides the active locale used by render().
 * @see \App\Core\Settings  Provides the site settings map used by render().
 */
abstract class BaseController
{
    /**
     * Renders a view file wrapped in a layout and returns the complete HTML string.
     *
     * Steps:
     *  1. Build a shared variable map (lang, settings, config, t, pageTitle, metaDesc, bodyClass).
     *  2. Merge the caller-supplied $data (caller values win over defaults for metaDesc/bodyClass).
     *  3. Extract the merged map into local scope, then include the view file, capturing output
     *     into $content.
     *  4. Extract the same map again (so the layout sees identical variables), add $content,
     *     then include the layout file and capture the result.
     *  5. Return the captured layout HTML.
     *
     * @param string $view   Relative path inside views/ without the .php extension,
     *                       e.g. 'public/home' resolves to views/public/home.php.
     * @param array  $data   Variables to make available inside the view and layout.
     *                       Passed values for 'pageTitle', 'metaDesc', and 'bodyClass'
     *                       override the automatically generated defaults.
     * @param string $layout Layout file name inside views/layouts/ without the .php
     *                       extension, e.g. 'public' → views/layouts/public.php.
     *
     * @return string The rendered HTML document produced by the layout.
     *
     * @throws \RuntimeException When the resolved view or layout file does not exist.
     *
     * @example
     *   return $this->render('public/home', ['products' => $products, 'pageTitle' => 'Welcome']);
     */
    protected function render(string $view, array $data = [], string $layout = 'public'): string
    {
        $viewFile   = dirname(__DIR__, 2) . '/views/' . $view . '.php';
        $layoutFile = dirname(__DIR__, 2) . '/views/layouts/' . $layout . '.php';

        if (!is_file($viewFile)) {
            throw new \RuntimeException("View file not found: {$viewFile}");
        }

        if (!is_file($layoutFile)) {
            throw new \RuntimeException("Layout file not found: {$layoutFile}");
        }

        // Build the shared variable set available in every view and layout.
        $shared = [
            'lang'      => Lang::current(),
            'settings'  => Settings::all(),
            'config'    => fn (string $key, mixed $default = null): mixed => Config::get($key, $default),
            't'         => fn (string $key): string => __t($key),
            'pageTitle' => $data['pageTitle'] ?? Config::get('BUSINESS_NAME', ''),
            'metaDesc'  => $data['metaDesc'] ?? '',
            'bodyClass' => $data['bodyClass'] ?? '',
            'ogImage'   => null,
        ];

        // Merge: $shared supplies defaults; caller $data wins for everything else.
        // For pageTitle/metaDesc/bodyClass the caller value was already folded in above,
        // so $data values take precedence naturally when array_merge is ordered this way.
        $merged = array_merge($shared, $data);

        // Render the view into $content.
        $content = (static function (string $_viewFile, array $_vars): string {
            extract($_vars);
            ob_start();
            include $_viewFile;
            return (string) ob_get_clean();
        })($viewFile, $merged);

        // Render the layout (which echoes $content) and return the full HTML.
        return (static function (string $_layoutFile, array $_vars, string $content): string {
            extract($_vars);
            ob_start();
            include $_layoutFile;
            return (string) ob_get_clean();
        })($layoutFile, $merged, $content);
    }

    /**
     * Redirect the client to the given URL.
     *
     * Thin wrapper around Response::redirect() so controllers don't need a
     * direct dependency on the Response class for this common operation.
     *
     * @param string $url    Destination path or absolute URL, e.g. '/admin/login'.
     * @param int    $status HTTP redirect status code (301, 302, 303, 307, 308).
     *                       Defaults to 302 (Found).
     *
     * @return \App\Core\Response Configured redirect response.
     *
     * @example
     *   return $this->redirect('/admin');
     *   return $this->redirect('/login', 301);
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * Return a JSON response containing the given data.
     *
     * Thin wrapper around Response::json() so controllers don't need a
     * direct dependency on the Response class for this common operation.
     *
     * @param mixed $data   Any JSON-serialisable value (array, object, scalar, null).
     * @param int   $status HTTP status code. Defaults to 200.
     *
     * @return \App\Core\Response Configured JSON response.
     *
     * @throws \JsonException When $data cannot be serialised to JSON.
     *
     * @example
     *   return $this->json(['ok' => true, 'id' => $id], 201);
     *   return $this->json(['error' => 'Not found'], 404);
     */
    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /**
     * Build the locale-specific strings App\Support\Closures needs to stay locale-free.
     *
     * Closures never hardcodes English/Spanish copy itself (see its class
     * DocBlock), so this method bridges the translation layer: it pulls the
     * 'closure.*' keys for the given language out of Lang and shapes them
     * into the two arrays Closures::formatRange()/formatList()/
     * rejectionMessage() expect. Safe to call even before the 'closure.*'
     * lang keys exist — Lang::get() falls back to returning the key itself,
     * so a missing translation degrades to a visible-but-harmless string
     * rather than an exception.
     *
     * @param string $lang Language code to look up, e.g. 'en' or 'es'.
     *
     * @return array{months: list<string>, strings: array<string, string>}
     *   'months' is 12 short month names (index 0 = January) parsed from the
     *   'closure.months' key (expected as a comma-separated list, e.g.
     *   'Jan,Feb,Mar,...'). 'strings' maps 'rejected', 'rejected_reason',
     *   'upcoming', and 'choose_another' to their translated sprintf
     *   templates.
     *
     * @example
     *   ['months' => $months, 'strings' => $strings] = $this->closureStrings('en');
     *   $message = Closures::rejectionMessage($date, $upcoming, $strings, $months);
     */
    protected function closureStrings(string $lang): array
    {
        $months = array_map('trim', explode(',', Lang::get('closure.months', $lang)));

        $strings = [];
        foreach (['rejected', 'rejected_reason', 'upcoming', 'choose_another'] as $key) {
            $strings[$key] = Lang::get('closure.' . $key, $lang);
        }

        return ['months' => $months, 'strings' => $strings];
    }
}
