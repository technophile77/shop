<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure helper that composes the title text of a public page.
 *
 * Public pages are titled "{page title} | {site name}", so a visitor can tell
 * the shop apart in a row of browser tabs, search results and social cards.
 * Gluing the two together blindly repeats the shop name whenever the page title
 * already is the shop name: the home page and any page without a title of its
 * own fall back to BUSINESS_NAME, which produced "Perla's Flowers | Perla's
 * Flowers". compose() adds the site name only when it tells the visitor
 * something new. No I/O; the result is raw text, so callers escape it for
 * whatever context they print it into.
 *
 * @see views/layouts/public.php Builds <title>, og:title and twitter:title from compose().
 * @see \App\Controllers\BaseController::render() Defaults the page title to BUSINESS_NAME.
 */
final class PageTitle
{
    /** Text placed between the page title and the site name. */
    private const SEPARATOR = ' | ';

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Join a page title and the site name into the text shown as the page's title.
     *
     * Surrounding whitespace, as PHP's trim() defines it, is dropped from both
     * parts first. Then:
     *  - a blank page title yields the site name alone;
     *  - a page title equal to the site name, ignoring letter case (Unicode
     *    aware), yields the site name alone, in the site name's own casing;
     *  - a blank site name yields the page title alone;
     *  - anything else yields "{page title} | {site name}".
     *
     * Only case and surrounding whitespace are ignored when comparing: a title
     * that merely contains the site name ("Perla's Flowers in Tulsa") or differs
     * in interior whitespace still gets the site name appended.
     *
     * @param string $pageTitle Page-specific title, e.g. from a `*_page_title_{lang}`
     *                          setting; may be blank, which means "no title of its own".
     * @param string $siteName  Business name, e.g. the BUSINESS_NAME config value; may be blank.
     *
     * @return string The title as raw text, not HTML-escaped; '' only when both parts are blank.
     *
     * @example
     *   PageTitle::compose('Our Flowers', "Perla's Flowers");       // "Our Flowers | Perla's Flowers"
     *   PageTitle::compose("Perla's Flowers", "Perla's Flowers");   // "Perla's Flowers"
     *   PageTitle::compose(" PERLA'S flowers ", "Perla's Flowers"); // "Perla's Flowers"
     *   PageTitle::compose('', "Perla's Flowers");                  // "Perla's Flowers"
     *   PageTitle::compose('Our Flowers', '');                      // 'Our Flowers'
     */
    public static function compose(string $pageTitle, string $siteName): string
    {
        $title = trim($pageTitle);
        $site  = trim($siteName);

        if ($title === '' || self::sameIgnoringCase($title, $site)) {
            return $site;
        }

        return $site === '' ? $title : $title . self::SEPARATOR . $site;
    }

    /**
     * Whether two strings are the same text apart from letter case.
     *
     * @param string $a First string, already trimmed.
     * @param string $b Second string, already trimmed.
     *
     * @return bool True when both lower-case to the same UTF-8 text.
     */
    private static function sameIgnoringCase(string $a, string $b): bool
    {
        return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
    }
}
