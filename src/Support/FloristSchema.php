<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure builder for the schema.org "Florist" structured data printed on every public page.
 *
 * The layout used to write this JSON-LD by hand and escape each value with
 * htmlspecialchars(). The browser does not decode HTML entities inside a
 * <script> block, so structured-data consumers read "Perla&#039;s Flowers" as
 * the shop's name and "&amp;" in a URL. build() returns plain PHP values
 * instead; the layout passes them through json_encode(), which does the one
 * escaping JSON needs. No I/O: the caller supplies both lookups, so the document
 * can be built, and tested, without an environment or a database.
 *
 * @see views/layouts/public.php Prints json_encode(FloristSchema::build(...)) in <script type="application/ld+json">.
 * @see \App\Core\Config   Supplies the $config lookup.
 * @see \App\Core\Settings Supplies the $setting lookup.
 */
final class FloristSchema
{
    /** schema.org DayOfWeek name for each site-setting day name (business_hours_{day}_open/_close), Monday first. */
    private const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    /** Start of an Instagram profile URL; the account handle is appended. */
    private const INSTAGRAM_PROFILE_URL = 'https://www.instagram.com/';

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Assemble the Florist JSON-LD document as plain PHP values.
     *
     * Text values are raw (not HTML-escaped), coordinates are floats, and the
     * keys come out in the order the layout has always printed them: @context,
     * @type, name, url, telephone, address, geo, sameAs, priceRange, and then
     * openingHoursSpecification, which is present only when at least one day has
     * both an opening and a closing time. Its entries run Monday to Sunday and
     * name the day as a schema.org DayOfWeek URL, https://schema.org/Monday to
     * https://schema.org/Sunday.
     *
     * sameAs lists the Facebook URL and the Instagram URL, in that order, with
     * blank ones left out (it stays a list, possibly empty). The Instagram URL
     * is INSTAGRAM_URL when that is set; otherwise it is built from
     * INSTAGRAM_HANDLE, and only when the handle is not blank, so a shop with
     * neither gets no Instagram entry rather than a bare instagram.com link.
     *
     * @param callable $config  Environment lookup with the signature of
     *                          \App\Core\Config::get(): `fn(string $key, mixed $default = null): mixed`.
     *                          Keys read: BUSINESS_NAME, APP_URL, BUSINESS_PHONE, BUSINESS_STREET_ADDRESS,
     *                          BUSINESS_CITY (default 'Tulsa'), BUSINESS_STATE (default 'OK'),
     *                          BUSINESS_POSTAL_CODE, BUSINESS_LAT (default 36.0814), BUSINESS_LNG (default -95.9987),
     *                          FACEBOOK_URL, INSTAGRAM_URL, INSTAGRAM_HANDLE, BUSINESS_PRICE_RANGE (default '$$').
     * @param callable $setting Site-setting lookup, `fn(string $key): mixed`, e.g. \App\Core\Settings::get(...).
     *                          Keys read: business_hours_{mon,tue,wed,thu,fri,sat,sun}_{open,close}, as
     *                          'HH:MM' text. A day is listed only when both values are truthy, so null, ''
     *                          and '0' all mean "not set".
     *
     * @return array<string, mixed> The document, ready for json_encode().
     *
     * @example
     *   // In views/layouts/public.php: lookups over Config::get() and Settings::get().
     *   $schema = FloristSchema::build(\App\Core\Config::get(...), \App\Core\Settings::get(...));
     *   echo json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
     *
     * @example
     *   // Array-backed lookups, e.g. in a test.
     *   $env    = ['BUSINESS_NAME' => "Perla's Flowers", 'INSTAGRAM_HANDLE' => 'perlaflowers4'];
     *   $hours  = ['business_hours_mon_open' => '09:00', 'business_hours_mon_close' => '17:00'];
     *   $schema = FloristSchema::build(
     *       static fn (string $key, mixed $default = null): mixed => $env[$key] ?? $default,
     *       static fn (string $key): mixed => $hours[$key] ?? null
     *   );
     *   $schema['name'];                                      // "Perla's Flowers"
     *   $schema['sameAs'];                                    // ['https://www.instagram.com/perlaflowers4']
     *   $schema['openingHoursSpecification'][0]['dayOfWeek']; // 'https://schema.org/Monday'
     *   $schema['openingHoursSpecification'][0]['opens'];     // '09:00'
     */
    public static function build(callable $config, callable $setting): array
    {
        $text = static fn (string $key, string $default = ''): string => (string) $config($key, $default);

        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'Florist',
            'name'       => $text('BUSINESS_NAME'),
            'url'        => $text('APP_URL'),
            'telephone'  => $text('BUSINESS_PHONE'),
            'address'    => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $text('BUSINESS_STREET_ADDRESS'),
                'addressLocality' => $text('BUSINESS_CITY', 'Tulsa'),
                'addressRegion'   => $text('BUSINESS_STATE', 'OK'),
                'postalCode'      => $text('BUSINESS_POSTAL_CODE'),
                'addressCountry'  => 'US',
            ],
            'geo'        => [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $config('BUSINESS_LAT', 36.0814),
                'longitude' => (float) $config('BUSINESS_LNG', -95.9987),
            ],
            'sameAs'     => self::sameAs($config),
            'priceRange' => $text('BUSINESS_PRICE_RANGE', '$$'),
        ];

        $hours = self::openingHours($setting);
        if ($hours !== []) {
            $schema['openingHoursSpecification'] = $hours;
        }

        return $schema;
    }

    /**
     * The shop's social profile URLs, Facebook first, blank ones dropped.
     *
     * @param callable $config Environment lookup, as for build().
     *
     * @return list<string> Trimmed URLs; empty when the shop has none.
     */
    private static function sameAs(callable $config): array
    {
        $handle = trim((string) $config('INSTAGRAM_HANDLE', ''));
        $links  = [
            (string) $config('FACEBOOK_URL', ''),
            (string) $config('INSTAGRAM_URL', $handle === '' ? '' : self::INSTAGRAM_PROFILE_URL . $handle),
        ];

        return array_values(array_filter(
            array_map('trim', $links),
            static fn (string $link): bool => $link !== ''
        ));
    }

    /**
     * One OpeningHoursSpecification per day that has both an opening and a closing time,
     * naming the day by its schema.org DayOfWeek URL (e.g. https://schema.org/Monday).
     *
     * @param callable $setting Site-setting lookup, as for build().
     *
     * @return list<array{'@type': string, dayOfWeek: string, opens: string, closes: string}> In Monday-to-Sunday order.
     */
    private static function openingHours(callable $setting): array
    {
        $specs = [];

        foreach (self::DAYS as $day => $weekday) {
            $opens  = $setting("business_hours_{$day}_open");
            $closes = $setting("business_hours_{$day}_close");

            if ($opens && $closes) {
                $specs[] = [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => 'https://schema.org/' . $weekday,
                    'opens'     => (string) $opens,
                    'closes'    => (string) $closes,
                ];
            }
        }

        return $specs;
    }
}
