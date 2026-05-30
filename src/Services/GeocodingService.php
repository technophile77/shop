<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Geocodes addresses and calculates great-circle distances using free, open APIs.
 *
 * All methods are static; this class is never instantiated.
 *
 * @see https://nominatim.openstreetmap.org/
 */
final class GeocodingService
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Geocodes an address string to lat/lng using Nominatim (OpenStreetMap).
     *
     * Sends a single US-restricted query to the Nominatim search endpoint and
     * returns the top result's coordinates. Returns null when the address
     * cannot be found, the response is malformed, or the network request fails.
     *
     * @param string $address Full address string, e.g. "6134 S Troost Ave, Tulsa, OK 74136".
     *
     * @return array{lat:float,lng:float}|null Coordinates on success, null on any failure.
     *
     * @example
     *   $coords = GeocodingService::geocode('6134 S Troost Ave, Tulsa, OK 74136');
     *   // ['lat' => 36.0814, 'lng' => -95.9987]
     */
    public static function geocode(string $address): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/search?q='
            . urlencode($address)
            . '&format=json&limit=1&countrycodes=us';

        $context = stream_context_create([
            'http' => [
                'header'  => "User-Agent: PerlaFlowers/1.0 (perla@flowers.cresswell.org)\r\n",
                'timeout' => 5,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            return null;
        }

        $results = json_decode($raw, true);

        if (!is_array($results) || count($results) === 0) {
            return null;
        }

        $result = $results[0];

        if (!isset($result['lat'], $result['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $result['lat'],
            'lng' => (float) $result['lon'],
        ];
    }

    /**
     * Calculates the great-circle distance in miles between two lat/lng points.
     *
     * Uses the Haversine formula, which gives accurate results for the distances
     * relevant to local delivery radius calculations.
     *
     * @param float $lat1 Latitude of the first point in decimal degrees.
     * @param float $lng1 Longitude of the first point in decimal degrees.
     * @param float $lat2 Latitude of the second point in decimal degrees.
     * @param float $lng2 Longitude of the second point in decimal degrees.
     *
     * @return float Distance in miles between the two points.
     *
     * @example
     *   $miles = GeocodingService::haversineDistance(36.0814, -95.9987, 36.1540, -95.9928);
     *   // ~5.3
     */
    public static function haversineDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        $earthRadiusMiles = 3958.8;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * asin(sqrt($a));

        return $earthRadiusMiles * $c;
    }
}
