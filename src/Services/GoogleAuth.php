<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Mints short-lived OAuth2 access tokens from a Google service-account JSON key.
 *
 * Implements the standard JWT-bearer grant flow against Google's token
 * endpoint so the application can call the Merchant API (Content for Shopping)
 * without a user OAuth dance. A self-signed RS256 JWT is built from the
 * service-account credentials, exchanged for a bearer access token, and the
 * resulting token is cached on disk and reused while it has more than 60
 * seconds of life left — so most calls incur no network round-trip.
 *
 * No Google API client library is required; this uses PHP's built-in `openssl`
 * extension (for RS256 signing) and `curl` (for the token request) only. Both
 * extensions are therefore required at runtime.
 *
 * Configuration is read from the environment:
 *   GOOGLE_SERVICE_ACCOUNT_KEY_PATH — path to the service-account JSON key file.
 *   A relative path is resolved against the project root (dirname(__DIR__, 2)).
 *
 * No method ever throws: failures are logged via error_log() and surfaced as a
 * null return, matching the house "never throw from a service" contract.
 *
 * All methods are static; this class is never instantiated.
 *
 * @see \App\Services\TwilioService  Sibling raw-HTTP service following the same style.
 */
final class GoogleAuth
{
    /** OAuth2 scope granting access to the Merchant / Content API. */
    private const SCOPE = 'https://www.googleapis.com/auth/content';

    /** Google's OAuth2 token endpoint and JWT audience. */
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Lifetime, in seconds, of each minted JWT/access token (Google max is 3600). */
    private const TOKEN_TTL = 3600;

    /** Seconds of remaining life below which a cached token is treated as stale. */
    private const REFRESH_MARGIN = 60;

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Returns true when a usable service-account key file is configured.
     *
     * Checks that GOOGLE_SERVICE_ACCOUNT_KEY_PATH is non-empty and that the
     * resolved file exists and is readable. Does not validate the file's JSON
     * contents — that happens lazily in {@see accessToken()}.
     *
     * @return bool True when the key path is set and points at a readable file.
     *
     * @example
     *   if (GoogleAuth::isConfigured()) {
     *       $token = GoogleAuth::accessToken();
     *   }
     */
    public static function isConfigured(): bool
    {
        $path = (string) Config::get('GOOGLE_SERVICE_ACCOUNT_KEY_PATH', '');
        if ($path === '') {
            return false;
        }

        $resolved = self::resolvePath($path);

        return is_file($resolved) && is_readable($resolved);
    }

    /**
     * Mints (or returns a cached) OAuth2 bearer access token for the Merchant API.
     *
     * On a cache hit (a previously minted token with more than 60 seconds left)
     * the token is returned with no network call. Otherwise a fresh RS256 JWT is
     * built from the service-account key, exchanged at Google's token endpoint,
     * cached to a temp file, and returned.
     *
     * Never throws. Any failure — missing/invalid key, signing error, or a
     * non-200 token response — is logged via error_log() and yields null.
     *
     * @return string|null A valid bearer access token, or null on any failure.
     *
     * @example
     *   $token = GoogleAuth::accessToken();
     *   if ($token !== null) {
     *       // curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
     *   }
     */
    public static function accessToken(): ?string
    {
        if (!self::isConfigured()) {
            error_log('[GoogleAuth] GOOGLE_SERVICE_ACCOUNT_KEY_PATH not configured or unreadable.');
            return null;
        }

        $cached = self::readCachedToken();
        if ($cached !== null) {
            return $cached;
        }

        $keyPath = self::resolvePath((string) Config::get('GOOGLE_SERVICE_ACCOUNT_KEY_PATH', ''));

        $rawKey = @file_get_contents($keyPath);
        if (!is_string($rawKey) || $rawKey === '') {
            error_log("[GoogleAuth] Could not read service-account key file: {$keyPath}");
            return null;
        }

        /** @var mixed $key */
        $key = json_decode($rawKey, associative: true);
        if (!is_array($key)) {
            error_log("[GoogleAuth] Service-account key file is not valid JSON: {$keyPath}");
            return null;
        }

        $clientEmail = isset($key['client_email']) ? (string) $key['client_email'] : '';
        $privateKey  = isset($key['private_key']) ? (string) $key['private_key'] : '';
        if ($clientEmail === '' || $privateKey === '') {
            error_log('[GoogleAuth] Service-account key missing client_email or private_key.');
            return null;
        }

        $jwt = self::buildSignedJwt($clientEmail, $privateKey);
        if ($jwt === null) {
            return null; // buildSignedJwt already logged the reason.
        }

        return self::exchangeJwtForToken($jwt);
    }

    /**
     * Builds a base64url-encoded, RS256-signed JWT bearer assertion.
     *
     * @param string $clientEmail Service-account email used as the `iss` and `sub`-style issuer.
     * @param string $privateKey  PEM-encoded RSA private key from the service-account JSON.
     *
     * @return string|null The signed `header.claims.signature` JWT, or null on a signing failure.
     */
    private static function buildSignedJwt(string $clientEmail, string $privateKey): ?string
    {
        $now = time();

        $header = self::base64url((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $claims = self::base64url((string) json_encode([
            'iss'   => $clientEmail,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + self::TOKEN_TTL,
        ]));

        $signingInput = "{$header}.{$claims}";

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if ($signed === false || $signature === '') {
            error_log('[GoogleAuth] openssl_sign failed: ' . openssl_error_string());
            return null;
        }

        return "{$signingInput}." . self::base64url($signature);
    }

    /**
     * Exchanges a signed JWT assertion for an OAuth2 access token at Google's endpoint.
     *
     * On success the token is cached to disk before being returned.
     *
     * @param string $jwt A signed JWT bearer assertion from {@see buildSignedJwt()}.
     *
     * @return string|null The access token, or null on any transport / non-200 / parse failure.
     */
    private static function exchangeJwtForToken(string $jwt): ?string
    {
        $payload = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $ch = curl_init(self::TOKEN_URL);
        if ($ch === false) {
            error_log('[GoogleAuth] curl_init failed for token request.');
            return null;
        }

        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        15);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/x-www-form-urlencoded']);

        $rawResponse = curl_exec($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($rawResponse) || $rawResponse === '') {
            error_log("[GoogleAuth] Empty or failed token response (HTTP {$httpCode}).");
            return null;
        }

        if ($httpCode !== 200) {
            error_log("[GoogleAuth] Token request failed (HTTP {$httpCode}): {$rawResponse}");
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawResponse, associative: true);
        $token   = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($token === '') {
            error_log("[GoogleAuth] Token response missing access_token: {$rawResponse}");
            return null;
        }

        $expiresIn = (is_array($decoded) && isset($decoded['expires_in']))
            ? (int) $decoded['expires_in']
            : self::TOKEN_TTL;

        self::writeCachedToken($token, time() + $expiresIn);

        return $token;
    }

    /**
     * Reads a still-valid access token from the on-disk cache.
     *
     * A token is returned only when the cache file parses cleanly and the token
     * has more than {@see REFRESH_MARGIN} seconds of life remaining. A malformed
     * or expired cache file is silently ignored (so the caller re-mints).
     *
     * @return string|null The cached token, or null when there is no usable cache.
     */
    private static function readCachedToken(): ?string
    {
        $file = self::cacheFile();
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $data */
        $data = json_decode($raw, associative: true);
        if (!is_array($data) || !isset($data['access_token'], $data['expires_at'])) {
            return null; // Malformed cache — ignore and re-mint.
        }

        $token     = (string) $data['access_token'];
        $expiresAt = (int) $data['expires_at'];

        if ($token === '' || $expiresAt - time() <= self::REFRESH_MARGIN) {
            return null;
        }

        return $token;
    }

    /**
     * Writes the minted token and its absolute expiry to the on-disk cache.
     *
     * Best-effort: a write failure is logged but does not affect the return of
     * a freshly minted token (it will simply be re-minted next time).
     *
     * @param string $token     The access token to cache.
     * @param int    $expiresAt Absolute Unix timestamp at which the token expires.
     */
    private static function writeCachedToken(string $token, int $expiresAt): void
    {
        $json = json_encode(['access_token' => $token, 'expires_at' => $expiresAt]);
        if ($json === false) {
            return;
        }

        if (@file_put_contents(self::cacheFile(), $json, LOCK_EX) === false) {
            error_log('[GoogleAuth] Failed to write token cache: ' . self::cacheFile());
        }
    }

    /**
     * Returns the absolute path of the token cache file in the system temp dir.
     *
     * @return string Cache file path, e.g. '/tmp/perlas_gmc_token.json'.
     */
    private static function cacheFile(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'perlas_gmc_token.json';
    }

    /**
     * Resolves a configured key path to an absolute path against the project root.
     *
     * Absolute paths are returned unchanged; relative paths are joined to the
     * project root (dirname(__DIR__, 2)).
     *
     * @param string $path A configured path, absolute or relative to the project root.
     *
     * @return string The resolved absolute path.
     */
    private static function resolvePath(string $path): string
    {
        $isAbsolute = $path !== '' && (
            $path[0] === '/'
            || $path[0] === '\\'
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
        );

        if ($isAbsolute) {
            return $path;
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * URL-safe base64 encoding (RFC 7515 base64url), without padding.
     *
     * @param string $data Raw bytes to encode.
     *
     * @return string The base64url representation (`+`→`-`, `/`→`_`, no `=`).
     *
     * @example
     *   GoogleAuth::base64url('{"alg":"RS256"}'); // "eyJhbGciOiJSUzI1NiJ9"
     */
    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
