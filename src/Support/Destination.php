<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Context holder for a "send flowers to this venue" delivery destination.
 *
 * The pure normalize() method sanitises raw input into a canonical destination
 * array. The thin session helpers (set/get/clear) store and retrieve that
 * array under a fixed session key, keeping session coupling isolated and
 * testable independently of the sanitisation logic.
 *
 * Destination shape:
 * <pre>
 * [
 *   'service'       => string|null,  // e.g. 'hospital', 'funeral', 'birthday'; lowercased
 *   'city'          => string|null,  // lowercased
 *   'venue_name'    => string|null,
 *   'venue_address' => string|null,
 *   'occasion'      => string|null,
 * ]
 * </pre>
 */
final class Destination
{
    /** Session key used to persist the destination across requests. */
    private const SESSION_KEY = 'send_destination';

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Sanitise raw destination input into a canonical destination array.
     *
     * Each field is passed through strip_tags() and trim(). Empty results are
     * stored as null rather than empty strings. The service and city fields are
     * additionally lowercased for consistent slug comparisons.
     *
     * @param array $raw Associative array with any of the recognised keys:
     *                   service, city, venue_name, venue_address, occasion.
     *                   Unrecognised keys are silently ignored.
     *
     * @return array{
     *     service: string|null,
     *     city: string|null,
     *     venue_name: string|null,
     *     venue_address: string|null,
     *     occasion: string|null
     * } Canonical destination array.
     *
     * @example
     *   Destination::normalize([
     *       'service'    => 'Hospital',
     *       'city'       => '  Tulsa  ',
     *       'venue_name' => '<b>St. Francis</b>',
     *   ]);
     *   // ['service'=>'hospital','city'=>'tulsa','venue_name'=>'St. Francis','venue_address'=>null,'occasion'=>null]
     */
    public static function normalize(array $raw): array
    {
        $sanitize = static function (mixed $value): ?string {
            $str = trim(strip_tags((string) ($value ?? '')));
            return $str !== '' ? $str : null;
        };

        $slugLower = static function (?string $value): ?string {
            return $value !== null ? strtolower($value) : null;
        };

        return [
            'service'       => $slugLower($sanitize($raw['service']      ?? null)),
            'city'          => $slugLower($sanitize($raw['city']         ?? null)),
            'venue_name'    => $sanitize($raw['venue_name']   ?? null),
            'venue_address' => $sanitize($raw['venue_address'] ?? null),
            'occasion'      => $sanitize($raw['occasion']     ?? null),
        ];
    }

    /**
     * Persist a destination into the session.
     *
     * The $dest array should be the output of normalize(); arbitrary raw arrays
     * are accepted but only the canonical keys will be useful to consumers.
     *
     * @param array $dest Canonical destination array.
     *
     * @return void
     *
     * @example
     *   Destination::set(Destination::normalize($_GET));
     */
    public static function set(array $dest): void
    {
        $_SESSION[self::SESSION_KEY] = $dest;
    }

    /**
     * Return the currently stored destination, or null when none is set.
     *
     * @return array|null Canonical destination array, or null.
     *
     * @example
     *   $dest = Destination::get();
     *   if ($dest !== null) { echo $dest['venue_name']; }
     */
    public static function get(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * Remove the stored destination from the session.
     *
     * @return void
     *
     * @example
     *   Destination::clear();
     */
    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
