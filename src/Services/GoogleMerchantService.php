<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Support\MerchantProduct;

/**
 * Thin raw-curl client over the Google Merchant API v1 Products sub-API.
 *
 * Inserts (upserts) and deletes a single product feed entry at a time against
 * the Merchant API v1 `productInputs` resource, using a bearer token minted by
 * {@see \App\Services\GoogleAuth}. The product payload is produced by the pure
 * mapper {@see \App\Support\MerchantProduct::toProductInput()}, so this class
 * owns only the HTTP transport, configuration, and the house result contract.
 *
 * No Google API client library is required — this uses PHP's built-in `curl`
 * extension only, mirroring {@see \App\Services\TwilioService}. No method ever
 * throws: every failure is logged via error_log() and surfaced as a result
 * array with `success => false`.
 *
 * The client is a safe no-op until configured: when {@see isConfigured()} is
 * false, the write methods return an error result WITHOUT any network call, so
 * it can be wired into request flows before real credentials exist.
 *
 * Configuration is read from the environment (via {@see \App\Core\Config}):
 *   GOOGLE_MERCHANT_ENABLED    — must be the exact string "true" to enable.
 *   GOOGLE_MERCHANT_ID         — the Merchant Center account id.
 *   GOOGLE_MERCHANT_DATASOURCE — the API data source id/name (required by the
 *                                Merchant API v1 product insert as the `dataSource`
 *                                query parameter).
 *   APP_URL                    — public site base URL, for product links/images.
 *   BUSINESS_NAME              — brand name for the `brand` attribute.
 *   GOOGLE_PRODUCT_CATEGORY    — Google product taxonomy value.
 * Plus everything {@see \App\Services\GoogleAuth} needs to mint a token.
 *
 * All methods are static; this class is never instantiated.
 *
 * @see \App\Services\GoogleAuth      Supplies the OAuth2 bearer token.
 * @see \App\Support\MerchantProduct  Pure mapper that builds the productInput body.
 * @see \App\Services\TwilioService   Sibling raw-curl service this mirrors in style.
 */
final class GoogleMerchantService
{
    /** Base URL of the Merchant API v1 service. */
    private const API_BASE = 'https://merchantapi.googleapis.com';

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Returns true when the Merchant client is fully configured and can call out.
     *
     * Requires GOOGLE_MERCHANT_ENABLED to be the exact string "true", a non-empty
     * merchant id and data source, AND that {@see \App\Services\GoogleAuth} can
     * mint a token (a readable service-account key). When any of these is missing
     * the write methods short-circuit to an error result with no network call.
     *
     * @return bool True when enabled, identified, sourced, and auth-capable.
     *
     * @example
     *   if (GoogleMerchantService::isConfigured()) {
     *       GoogleMerchantService::upsert($product, 'en');
     *   }
     */
    public static function isConfigured(): bool
    {
        return Config::get('GOOGLE_MERCHANT_ENABLED', '') === 'true'
            && (string) Config::get('GOOGLE_MERCHANT_ID', '')         !== ''
            && (string) Config::get('GOOGLE_MERCHANT_DATASOURCE', '') !== ''
            && GoogleAuth::isConfigured();
    }

    /**
     * Returns the non-row configuration array that {@see MerchantProduct} consumes.
     *
     * Exposes the same `$cfg` that {@see upsert()} uses internally so that
     * offline tooling — notably the `--dry-run` mode of `bin/merchant-sync.php`
     * — can render real productInput payloads from a single source of truth,
     * without needing Merchant credentials or a token.
     *
     * @return array{app_url: string, brand: string, google_category: string, placeholder_image: string}
     *         The config consumed by {@see MerchantProduct::toProductInput()}.
     *
     * @example
     *   $cfg  = GoogleMerchantService::configForDryRun();
     *   $body = MerchantProduct::toProductInput($product, 'en', $cfg);
     */
    public static function configForDryRun(): array
    {
        return self::cfg();
    }

    /**
     * Inserts (upserts) a single product into the Merchant feed for one language.
     *
     * Builds the productInput body via {@see MerchantProduct::toProductInput()},
     * mints a bearer token, and POSTs to the Merchant API v1 `productInputs:insert`
     * endpoint. The insert endpoint upserts: re-sending the same offerId replaces
     * the existing entry. Any 2xx HTTP status is treated as success.
     *
     * Never throws. When the client is not configured the call returns immediately
     * with an error result and performs NO network I/O. A missing token, transport
     * failure, or non-2xx response is logged via error_log() and returned as an
     * error result.
     *
     * @param array<string, mixed> $product Product row (see {@see \App\Models\Product}).
     * @param string               $lang    Content language: 'en' or 'es'.
     *
     * @return array{success: bool, error: ?string, http: int, offerId: string}
     *         On success: ['success' => true, 'error' => null, 'http' => 200, 'offerId' => 'prod-12'].
     *         On failure: ['success' => false, 'error' => '…', 'http' => int, 'offerId' => 'prod-12'].
     *
     * @example
     *   $r = GoogleMerchantService::upsert(['id' => 12, 'name_en' => 'Roses', 'price_from' => 45], 'en');
     *   if (!$r['success']) { error_log($r['error']); }
     */
    public static function upsert(array $product, string $lang): array
    {
        $body    = MerchantProduct::toProductInput($product, $lang, self::cfg());
        $offerId = (string) ($body['offerId'] ?? '');

        if (!self::isConfigured()) {
            return ['success' => false, 'error' => 'Merchant not configured', 'http' => 0, 'offerId' => $offerId];
        }

        $token = GoogleAuth::accessToken();
        if ($token === null) {
            error_log("[GoogleMerchantService] No access token available for upsert of {$offerId}.");
            return ['success' => false, 'error' => 'No access token', 'http' => 0, 'offerId' => $offerId];
        }

        $merchantId = rawurlencode((string) Config::get('GOOGLE_MERCHANT_ID', ''));
        $dataSource = rawurlencode((string) Config::get('GOOGLE_MERCHANT_DATASOURCE', ''));

        // Verified live (2026-06-20) against Merchant API v1:
        //   POST products/v1/accounts/{account}/productInputs:insert?dataSource={dataSource}
        $url = self::API_BASE
            . "/products/v1/accounts/{$merchantId}/productInputs:insert?dataSource={$dataSource}";

        $result = self::request('POST', $url, $body, $token);

        if ($result['success']) {
            error_log("[GoogleMerchantService] Upserted {$offerId} ({$lang}) — HTTP {$result['http']}.");
        } else {
            error_log("[GoogleMerchantService] Upsert of {$offerId} ({$lang}) failed (HTTP {$result['http']}): {$result['error']}");
        }

        return [
            'success' => $result['success'],
            'error'   => $result['error'],
            'http'    => $result['http'],
            'offerId' => $offerId,
        ];
    }

    /**
     * Deletes a single product feed entry by offerId for one language.
     *
     * Mints a bearer token and issues a DELETE against the Merchant API v1
     * `productInputs` resource. The fully-qualified productInput name encodes the
     * content language, feed label, and offerId (e.g. `en~US~prod-14`); this
     * mirrors the values produced by {@see MerchantProduct::toProductInput()}
     * (feed label `US`). Any 2xx HTTP status is treated as success.
     *
     * Never throws — same no-op-when-unconfigured and error-result contract as
     * {@see upsert()}.
     *
     * @param string $offerId The product offerId to delete, e.g. 'prod-12'.
     * @param string $lang    Content language the entry was published under ('en' | 'es').
     *
     * @return array{success: bool, error: ?string, http: int, offerId: string}
     *         On success: ['success' => true, 'error' => null, 'http' => 200, 'offerId' => 'prod-12'].
     *         On failure: ['success' => false, 'error' => '…', 'http' => int, 'offerId' => 'prod-12'].
     *
     * @example
     *   $r = GoogleMerchantService::delete('prod-12', 'en');
     *   if (!$r['success']) { error_log($r['error']); }
     */
    public static function delete(string $offerId, string $lang): array
    {
        if (!self::isConfigured()) {
            return ['success' => false, 'error' => 'Merchant not configured', 'http' => 0, 'offerId' => $offerId];
        }

        $token = GoogleAuth::accessToken();
        if ($token === null) {
            error_log("[GoogleMerchantService] No access token available for delete of {$offerId}.");
            return ['success' => false, 'error' => 'No access token', 'http' => 0, 'offerId' => $offerId];
        }

        $merchantId = (string) Config::get('GOOGLE_MERCHANT_ID', '');
        $dataSource = rawurlencode((string) Config::get('GOOGLE_MERCHANT_DATASOURCE', ''));

        // Verified live (2026-06-20): the productInput name is
        // {contentLanguage}~{feedLabel}~{offerId} — there is NO channel segment in
        // Merchant API v1, e.g. accounts/{account}/productInputs/en~US~prod-14.
        $name = "accounts/{$merchantId}/productInputs/{$lang}~US~{$offerId}";

        //   DELETE products/v1/{name}?dataSource={dataSource}
        $url = self::API_BASE . '/products/v1/' . $name . "?dataSource={$dataSource}";

        $result = self::request('DELETE', $url, null, $token);

        if ($result['success']) {
            error_log("[GoogleMerchantService] Deleted {$offerId} ({$lang}) — HTTP {$result['http']}.");
        } else {
            error_log("[GoogleMerchantService] Delete of {$offerId} ({$lang}) failed (HTTP {$result['http']}): {$result['error']}");
        }

        return [
            'success' => $result['success'],
            'error'   => $result['error'],
            'http'    => $result['http'],
            'offerId' => $offerId,
        ];
    }

    /**
     * Assembles the non-row configuration consumed by {@see MerchantProduct}.
     *
     * @return array{app_url: string, brand: string, google_category: string, placeholder_image: string}
     */
    private static function cfg(): array
    {
        $appUrl = (string) Config::get('APP_URL', '');

        return [
            'app_url'           => $appUrl,
            'brand'             => (string) Config::get('BUSINESS_NAME', ''),
            'google_category'   => (string) Config::get('GOOGLE_PRODUCT_CATEGORY', ''),
            'placeholder_image' => rtrim($appUrl, '/') . '/public/assets/images/placeholder-flower.jpg',
        ];
    }

    /**
     * Performs a single authenticated JSON request and normalises the result.
     *
     * Centralises curl setup, the bearer + content-type headers, status capture,
     * and the success/error contract shared by {@see upsert()} and {@see delete()}.
     * Any 2xx HTTP status is success; a missing curl handle, transport failure, or
     * non-2xx status yields an error result. Never throws.
     *
     * @param string                    $method   HTTP method, e.g. 'POST' or 'DELETE'.
     * @param string                    $url      Fully-composed request URL (already URL-encoded).
     * @param array<string, mixed>|null $jsonBody Body to JSON-encode and send, or null for none.
     * @param string                    $token    OAuth2 bearer access token.
     *
     * @return array{success: bool, error: ?string, http: int}
     *         Normalised transport result; `error` is null only on success.
     */
    private static function request(string $method, string $url, ?array $jsonBody, string $token): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'error' => 'curl_init failed', 'http' => 0];
        }

        $headers = [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json',
        ];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        30);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);

        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody);
            if ($encoded === false) {
                curl_close($ch);
                return ['success' => false, 'error' => 'JSON encode failed', 'http' => 0];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        }

        $rawResponse = curl_exec($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            return ['success' => false, 'error' => $curlError !== '' ? $curlError : 'curl_exec failed', 'http' => $httpCode];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'error' => null, 'http' => $httpCode];
        }

        $body    = is_string($rawResponse) ? $rawResponse : '';
        $decoded = json_decode($body, associative: true);
        $message = is_array($decoded)
            ? (string) ($decoded['error']['message'] ?? $body)
            : $body;

        return ['success' => false, 'error' => $message !== '' ? $message : "HTTP {$httpCode}", 'http' => $httpCode];
    }
}
