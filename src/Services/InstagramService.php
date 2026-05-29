<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Fetches Instagram account insights via the Instagram Graph API.
 *
 * Requires two .env keys to be present and non-empty:
 *   META_IG_ACCESS_TOKEN — a long-lived User access token.
 *   META_IG_USER_ID      — the numeric Instagram Business or Creator account ID.
 *
 * All methods are static; this class is never instantiated. On any API or
 * network error the affected method logs via error_log() and returns null,
 * so callers must always check for null before consuming the result.
 *
 * @see https://developers.facebook.com/docs/instagram-api
 * @see \App\Services\TwilioService  Similar curl-based service pattern.
 */
final class InstagramService
{
    /** Instagram Graph API version used for all requests. */
    private const API_VERSION = 'v20.0';

    /** Base URL for the Meta Graph API. */
    private const BASE_URL = 'https://graph.facebook.com/';

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Returns true when both required Instagram API credentials are present.
     *
     * @return bool True when META_IG_ACCESS_TOKEN and META_IG_USER_ID are set.
     *
     * @example
     *   if (InstagramService::isConfigured()) { $info = InstagramService::accountInfo(); }
     */
    public static function isConfigured(): bool
    {
        return Config::get('META_IG_ACCESS_TOKEN', '') !== ''
            && Config::get('META_IG_USER_ID', '')      !== '';
    }

    /**
     * Returns basic account information: follower count, media count, username.
     *
     * Calls GET /{META_IG_USER_ID}?fields=followers_count,media_count,username.
     *
     * @return array{followers_count: int, media_count: int, username: string}|null
     *         Null when credentials are missing or the API request fails.
     *
     * @example
     *   $info = InstagramService::accountInfo();
     *   if ($info) { echo "@{$info['username']} — {$info['followers_count']} followers"; }
     */
    public static function accountInfo(): ?array
    {
        if (!self::isConfigured()) {
            return null;
        }

        $userId = (string) Config::get('META_IG_USER_ID', '');
        $data   = self::get("/{$userId}", ['fields' => 'followers_count,media_count,username']);

        if ($data === null) {
            return null;
        }

        return [
            'followers_count' => (int)    ($data['followers_count'] ?? 0),
            'media_count'     => (int)    ($data['media_count']     ?? 0),
            'username'        => (string) ($data['username']        ?? ''),
        ];
    }

    /**
     * Returns the most recent N media posts with per-post engagement metrics.
     *
     * First fetches the media list with basic fields, then for each post
     * fetches reach and impressions from the post insights endpoint. Posts
     * that fail the insights sub-request receive zeros for those metrics.
     *
     * @param int $limit Maximum posts to return (default 12).
     *
     * @return array<int, array{
     *   id: string,
     *   media_type: string,
     *   thumbnail_url: string|null,
     *   permalink: string,
     *   timestamp: string,
     *   like_count: int,
     *   comments_count: int,
     *   reach: int,
     *   impressions: int,
     * }>|null  Null when credentials are missing or the media list request fails.
     *
     * @example
     *   $posts = InstagramService::recentPosts(9);
     *   foreach ($posts ?? [] as $post) { echo $post['permalink']; }
     */
    public static function recentPosts(int $limit = 12): ?array
    {
        if (!self::isConfigured()) {
            return null;
        }

        $userId = (string) Config::get('META_IG_USER_ID', '');
        $data   = self::get("/{$userId}/media", [
            'fields' => 'id,media_type,thumbnail_url,permalink,timestamp,like_count,comments_count',
            'limit'  => (string) $limit,
        ]);

        if ($data === null || !isset($data['data']) || !is_array($data['data'])) {
            return null;
        }

        $posts = [];

        foreach ($data['data'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $postId   = (string) ($item['id'] ?? '');
            $reach    = 0;
            $impressions = 0;

            // Fetch per-post insights for reach and impressions.
            if ($postId !== '') {
                $insights = self::get("/{$postId}/insights", [
                    'metric' => 'reach,impressions',
                ]);

                if ($insights !== null && isset($insights['data']) && is_array($insights['data'])) {
                    foreach ($insights['data'] as $metric) {
                        if (!is_array($metric)) {
                            continue;
                        }
                        $name  = (string) ($metric['name']  ?? '');
                        $value = (int)    ($metric['values'][0]['value'] ?? $metric['value'] ?? 0);

                        if ($name === 'reach') {
                            $reach = $value;
                        } elseif ($name === 'impressions') {
                            $impressions = $value;
                        }
                    }
                }
            }

            $thumbnailUrl = isset($item['thumbnail_url']) ? (string) $item['thumbnail_url'] : null;

            $posts[] = [
                'id'             => $postId,
                'media_type'     => (string) ($item['media_type']     ?? ''),
                'thumbnail_url'  => $thumbnailUrl,
                'permalink'      => (string) ($item['permalink']      ?? ''),
                'timestamp'      => (string) ($item['timestamp']      ?? ''),
                'like_count'     => (int)    ($item['like_count']     ?? 0),
                'comments_count' => (int)    ($item['comments_count'] ?? 0),
                'reach'          => $reach,
                'impressions'    => $impressions,
            ];
        }

        return $posts;
    }

    /**
     * Returns account-level insights aggregated over the last 30 days.
     *
     * Calls GET /{META_IG_USER_ID}/insights?metric=reach,impressions,
     * profile_views,website_clicks&period=month. Metrics not returned by the
     * API default to 0.
     *
     * @return array{
     *   reach: int,
     *   impressions: int,
     *   profile_views: int,
     *   website_clicks: int,
     * }|null  Null when credentials are missing or the API request fails.
     *
     * @example
     *   $insights = InstagramService::accountInsights();
     *   if ($insights) { echo "Reach: {$insights['reach']}"; }
     */
    public static function accountInsights(): ?array
    {
        if (!self::isConfigured()) {
            return null;
        }

        $userId = (string) Config::get('META_IG_USER_ID', '');
        $data   = self::get("/{$userId}/insights", [
            'metric' => 'reach,impressions,profile_views,website_clicks',
            'period' => 'month',
        ]);

        if ($data === null || !isset($data['data']) || !is_array($data['data'])) {
            return null;
        }

        $result = [
            'reach'          => 0,
            'impressions'    => 0,
            'profile_views'  => 0,
            'website_clicks' => 0,
        ];

        foreach ($data['data'] as $metric) {
            if (!is_array($metric)) {
                continue;
            }

            $name  = (string) ($metric['name'] ?? '');
            $value = (int)    ($metric['values'][0]['value'] ?? $metric['value'] ?? 0);

            if (array_key_exists($name, $result)) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Makes an authenticated GET request to the Meta Graph API.
     *
     * Appends the access_token query param automatically. Returns the decoded
     * JSON response as an associative array on success, or null on any curl or
     * HTTP error. All errors are written to the PHP error log.
     *
     * @param string  $endpoint  Path relative to BASE_URL/API_VERSION,
     *                           e.g. '/123456/media'.
     * @param array   $params    Additional query parameters (access_token is added
     *                           automatically and must not be included here).
     *
     * @return array<string, mixed>|null Decoded response body, or null on error.
     *
     * @example
     *   $data = self::get('/me', ['fields' => 'username']);
     */
    private static function get(string $endpoint, array $params = []): ?array
    {
        $token = (string) Config::get('META_IG_ACCESS_TOKEN', '');

        $params['access_token'] = $token;

        $url = self::BASE_URL . self::API_VERSION . $endpoint . '?' . http_build_query($params);

        $ch = curl_init($url);

        if ($ch === false) {
            error_log('[InstagramService] curl_init() failed for endpoint: ' . $endpoint);
            return null;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            error_log('[InstagramService] curl error for ' . $endpoint . ': ' . $curlError);
            return null;
        }

        if (!is_string($raw) || $raw === '') {
            error_log('[InstagramService] Empty response for ' . $endpoint . ' (HTTP ' . $httpCode . ')');
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true);

        if (!is_array($decoded)) {
            error_log('[InstagramService] Non-array JSON for ' . $endpoint . ': ' . $raw);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string) ($decoded['error']['message'] ?? $raw);
            error_log('[InstagramService] HTTP ' . $httpCode . ' for ' . $endpoint . ': ' . $message);
            return null;
        }

        return $decoded;
    }
}
