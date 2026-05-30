<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Manages bilingual (EN / ES) translation strings for Perla's Flowers.
 *
 * Language files live in lang/{code}.php and each return a flat associative
 * array whose keys use dot notation (e.g. 'nav.home', 'order.submit').
 *
 * Typical bootstrap sequence:
 *   Lang::set(Lang::current());   // restore from session or fall back to 'en'
 *   Lang::load(Lang::current());  // pre-warm the cache for the active locale
 *   $lang = Lang::fromAcceptHeader();  // detect preferred language on first visit
 *
 * @see __t()  Global shorthand helper defined in src/Core/helpers.php
 */
class Lang
{
    /** @var array<string, array<string, string>> Keyed by language code. */
    private static array $cache = [];

    /** @var string|null Active language code; null until first call to current(). */
    private static ?string $current = null;

    /** @var list<string> Supported locale codes. */
    private const AVAILABLE = ['en', 'es'];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Loads a language file into the in-memory cache.
     *
     * The file is expected at lang/{code}.php relative to the project root and
     * must return an array<string, string>.  Calling this method a second time
     * for the same code is a no-op (the cached result is reused).
     *
     * @param string $code A supported language code — 'en' or 'es'.
     *
     * @throws \InvalidArgumentException When $code is not in the available list.
     * @throws \RuntimeException         When the language file cannot be found.
     *
     * @example Lang::load('en');
     */
    public static function load(string $code): void
    {
        self::assertAvailable($code);

        if (isset(self::$cache[$code])) {
            return;
        }

        $path = self::langPath($code);

        if (!is_file($path)) {
            throw new \RuntimeException("Language file not found: {$path}");
        }

        /** @var mixed $strings */
        $strings = require $path;

        if (!is_array($strings)) {
            throw new \RuntimeException("Language file must return an array: {$path}");
        }

        /** @var array<string, string> $strings */
        self::$cache[$code] = $strings;
    }

    /**
     * Returns the translation for $key in the given (or current) locale.
     *
     * When the language file has not been loaded yet this method loads it
     * on demand.  If the key is absent the key itself is returned, making
     * missing translations easy to spot in the UI without fatal errors.
     *
     * @param string      $key  Dot-notation translation key, e.g. 'nav.home'.
     * @param string|null $lang Language code to look up; defaults to current().
     *
     * @return string Translated string, or $key when the key is not found.
     *
     * @example
     *   Lang::get('nav.home');        // 'Home' when current locale is 'en'
     *   Lang::get('nav.home', 'es'); // 'Inicio'
     */
    public static function get(string $key, ?string $lang = null): string
    {
        $code = $lang ?? self::current();

        if (!isset(self::$cache[$code])) {
            self::load($code);
        }

        return self::$cache[$code][$key] ?? $key;
    }

    /**
     * Returns the active language code.
     *
     * On the first call per request the value is read from $_SESSION['lang']
     * (written by set()).  Falls back to 'en' when the session has no value or
     * contains an unrecognised code.  Subsequent calls within the same request
     * return the in-memory cached value.
     *
     * @return string 'en' or 'es'
     *
     * @example $code = Lang::current(); // 'es' after Lang::set('es')
     */
    public static function current(): string
    {
        if (self::$current === null) {
            $session = (session_status() === PHP_SESSION_ACTIVE) ? ($_SESSION['lang'] ?? 'en') : 'en';
            self::$current = in_array($session, self::AVAILABLE, true) ? $session : 'en';
        }

        return self::$current;
    }

    /**
     * Sets the active language code and persists it in the session.
     *
     * Starts the session if one is not already active.  Only codes returned by
     * available() are accepted.
     *
     * @param string $code 'en' or 'es'
     *
     * @throws \InvalidArgumentException When $code is not in the available list.
     *
     * @example Lang::set('es');
     */
    public static function set(string $code): void
    {
        self::assertAvailable($code);

        self::$current = $code;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['lang'] = $code;
    }

    /**
     * Returns all supported language codes.
     *
     * @return list<string> Always ['en', 'es'].
     *
     * @example $codes = Lang::available(); // ['en', 'es']
     */
    public static function available(): array
    {
        return self::AVAILABLE;
    }

    /**
     * Detects the preferred language from the HTTP Accept-Language header.
     *
     * Parses the browser-sent header (e.g. "es-US,es;q=0.9,en;q=0.8") and
     * returns 'es' when any Spanish locale outranks or ties with English.
     * Falls back to 'en' when no header is present or parsing fails.
     *
     * @return string 'es' or 'en'
     *
     * @example
     *   // $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'es-MX,es;q=0.9,en;q=0.8'
     *   Lang::fromAcceptHeader(); // 'es'
     */
    public static function fromAcceptHeader(): string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

        if ($header === '') {
            return 'en';
        }

        preg_match_all('/([a-z]{2})(?:-[A-Z]{2})?(?:;q=([\d.]+))?/i', $header, $m, PREG_SET_ORDER);

        $scores = [];
        foreach ($m as $match) {
            $code  = strtolower($match[1]);
            $score = isset($match[2]) && $match[2] !== '' ? (float) $match[2] : 1.0;
            if (!isset($scores[$code])) {
                $scores[$code] = $score;
            }
        }

        $esScore = $scores['es'] ?? -1.0;
        $enScore = $scores['en'] ?? 0.0;

        return $esScore >= $enScore ? 'es' : 'en';
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolves the absolute path to a language file.
     *
     * Assumes this file lives at src/Core/Lang.php, so the project root is
     * three directory levels up (src/Core → src → project root).
     *
     * @param string $code Language code, e.g. 'en'.
     * @return string Absolute filesystem path.
     */
    private static function langPath(string $code): string
    {
        $root = dirname(__DIR__, 2);
        return $root . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $code . '.php';
    }

    /**
     * Throws when $code is not in the supported list.
     *
     * @param string $code Language code to validate.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertAvailable(string $code): void
    {
        if (!in_array($code, self::AVAILABLE, true)) {
            $supported = implode(', ', self::AVAILABLE);
            throw new \InvalidArgumentException(
                "Unsupported language code '{$code}'. Supported: {$supported}."
            );
        }
    }
}
