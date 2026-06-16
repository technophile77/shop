<?php

declare(strict_types=1);

/**
 * One-time / on-demand geocoder for the local-area venue addresses.
 *
 * Reads every funeral-home and hospital address from config/local-areas.php,
 * geocodes any address not already cached, and writes the address→coordinate
 * map to config/local-areas-coords.php. Those coordinates feed
 * App\Support\LocalArea::enrichVenues, which shows the straight-line distance
 * from the studio and the estimated delivery fee next to each venue — using the
 * same Haversine + fee math as the order form.
 *
 * Geocoder chain (first success per address wins):
 *   1. Google Geocoding API when a usable key is available (GOOGLE_MAPS_API_KEY
 *      in the environment or in the project .env). Note: a browser/referrer-
 *      restricted key will be rejected server-side, so the chain falls through.
 *   2. U.S. Census Bureau geocoder — free, keyless, accurate for U.S. street
 *      addresses (no rate limit concerns).
 *   3. OpenStreetMap Nominatim — last-resort fallback, rate-limited to ~1 req/sec.
 * Coordinates from any provider differ by only meters, which is negligible for a
 * straight-line delivery estimate.
 *
 * The cache is incremental: addresses already present are skipped, so re-running
 * after adding venues only geocodes the new ones. Delete the cache file to force
 * a full re-geocode (e.g. to switch providers).
 *
 * Usage:
 *   php bin/geocode-local-areas.php
 *
 * @see \App\Support\LocalArea::coords()
 * @see \App\Support\LocalArea::enrichVenues()
 */

$root       = dirname(__DIR__);
$areasFile  = $root . '/config/local-areas.php';
$cacheFile  = $root . '/config/local-areas-coords.php';

/** @var array<string, array<string, mixed>> $areas */
$areas = require $areasFile;

// Existing cache (incremental geocoding).
$cache = is_file($cacheFile) ? (array) require $cacheFile : [];

$apiKey = google_api_key($root);
fwrite(STDOUT, 'Geocoder chain: ' . ($apiKey !== '' ? 'Google → ' : '') . "Census → Nominatim\n");

// Collect unique venue addresses across all cities.
$addresses = [];
foreach ($areas as $city) {
    foreach (['funeral_homes', 'hospitals'] as $listKey) {
        foreach (($city[$listKey] ?? []) as $venue) {
            $addr = (string) ($venue['address'] ?? '');
            if ($addr !== '') {
                $addresses[$addr] = true;
            }
        }
    }
}

$done = 0;
$new  = 0;
$fail = 0;
foreach (array_keys($addresses) as $address) {
    $done++;
    if (isset($cache[$address]['lat'], $cache[$address]['lng'])) {
        continue; // already cached
    }

    [$coord, $provider, $usedNominatim] = geocode_address($address, $apiKey);

    if ($coord === null) {
        $fail++;
        fwrite(STDERR, "  ✗ could not geocode: {$address}\n");
        continue;
    }

    $cache[$address] = $coord;
    $new++;
    fwrite(STDOUT, sprintf("  ✓ %-50s %.5f, %.5f  [%s]\n", substr($address, 0, 50), $coord['lat'], $coord['lng'], $provider));

    // Respect Nominatim's 1 request/second usage policy.
    if ($usedNominatim) {
        usleep(1_100_000);
    }
}

ksort($cache);
write_cache($cacheFile, $cache);

fwrite(STDOUT, sprintf("\nDone. %d addresses, %d newly geocoded, %d failed. Cache: %s\n", $done, $new, $fail, $cacheFile));
exit($fail > 0 ? 1 : 0);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Resolve the Google Maps API key from the environment or the project .env.
 *
 * @param string $root Project root directory.
 *
 * @return string The key, or '' when none is configured.
 */
function google_api_key(string $root): string
{
    $env = getenv('GOOGLE_MAPS_API_KEY');
    if (is_string($env) && $env !== '') {
        return trim($env);
    }

    $envFile = $root . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*GOOGLE_MAPS_API_KEY\s*=\s*(.+)$/', $line, $m)) {
                return trim(trim($m[1]), "\"'");
            }
        }
    }

    return '';
}

/**
 * Geocode an address through the provider chain, returning the first success.
 *
 * @param string $address Full postal address.
 * @param string $apiKey  Google Maps API key, or '' to skip Google.
 *
 * @return array{0: array{lat: float, lng: float}|null, 1: string, 2: bool}
 *         [coordinates|null, provider label, whether Nominatim was used].
 */
function geocode_address(string $address, string $apiKey): array
{
    if ($apiKey !== '' && ($c = geocode_google($address, $apiKey)) !== null) {
        return [$c, 'google', false];
    }
    if (($c = geocode_census($address)) !== null) {
        return [$c, 'census', false];
    }
    if (($c = geocode_nominatim($address)) !== null) {
        return [$c, 'nominatim', true];
    }

    return [null, 'none', false];
}

/**
 * Geocode an address with the U.S. Census Bureau geocoder (keyless, US-only).
 *
 * @param string $address Full postal address.
 *
 * @return array{lat: float, lng: float}|null Coordinates, or null on failure.
 */
function geocode_census(string $address): ?array
{
    $url = 'https://geocoding.geo.census.gov/geocoder/locations/onelineaddress?benchmark=Public_AR_Current&format=json&address='
         . rawurlencode($address);
    $json = http_get_json($url, 'perlas-flowers-geocoder');
    $match = $json['result']['addressMatches'][0]['coordinates'] ?? null;
    if (!is_array($match) || !isset($match['x'], $match['y'])) {
        return null;
    }

    return ['lat' => (float) $match['y'], 'lng' => (float) $match['x']];
}

/**
 * Geocode an address with the Google Geocoding API.
 *
 * @param string $address Full postal address.
 * @param string $key     Google Maps API key.
 *
 * @return array{lat: float, lng: float}|null Coordinates, or null on failure.
 */
function geocode_google(string $address, string $key): ?array
{
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
         . rawurlencode($address) . '&key=' . rawurlencode($key);
    $json = http_get_json($url, 'perlas-flowers-geocoder');
    if (($json['status'] ?? '') !== 'OK' || empty($json['results'][0]['geometry']['location'])) {
        return null;
    }
    $loc = $json['results'][0]['geometry']['location'];

    return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
}

/**
 * Geocode an address with OpenStreetMap Nominatim.
 *
 * @param string $address Full postal address.
 *
 * @return array{lat: float, lng: float}|null Coordinates, or null on failure.
 */
function geocode_nominatim(string $address): ?array
{
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=us&q='
         . rawurlencode($address);
    $json = http_get_json($url, 'perlas-flowers-geocoder/1.0 (contact: alex@cresswell.org)');
    if (empty($json[0]['lat']) || empty($json[0]['lon'])) {
        return null;
    }

    return ['lat' => (float) $json[0]['lat'], 'lng' => (float) $json[0]['lon']];
}

/**
 * Fetch a URL and decode the JSON body.
 *
 * @param string $url       The request URL.
 * @param string $userAgent A descriptive User-Agent (required by Nominatim).
 *
 * @return array<mixed>|null Decoded JSON, or null on transport/parse failure.
 */
function http_get_json(string $url, string $userAgent): ?array
{
    $ctx = stream_context_create([
        'http' => ['header' => "User-Agent: {$userAgent}\r\n", 'timeout' => 20],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    $data = json_decode($body, true);

    return is_array($data) ? $data : null;
}

/**
 * Write the coordinate cache as a formatted, returnable PHP array file.
 *
 * @param string                                        $file  Destination path.
 * @param array<string, array{lat: float, lng: float}>  $cache Address→coord map.
 */
function write_cache(string $file, array $cache): void
{
    $lines = [
        '<?php',
        '',
        'declare(strict_types=1);',
        '',
        '/**',
        ' * Geocoded coordinates for local-area venues — address => [lat, lng].',
        ' *',
        ' * GENERATED by bin/geocode-local-areas.php. Do not edit by hand; re-run the',
        ' * script after changing venue addresses in config/local-areas.php.',
        ' *',
        ' * @return array<string, array{lat: float, lng: float}>',
        ' */',
        '',
        'return [',
    ];
    foreach ($cache as $address => $coord) {
        $lines[] = sprintf(
            "    %s => ['lat' => %.6f, 'lng' => %.6f],",
            var_export((string) $address, true),
            (float) $coord['lat'],
            (float) $coord['lng']
        );
    }
    $lines[] = '];';
    $lines[] = '';

    file_put_contents($file, implode("\n", $lines));
}
