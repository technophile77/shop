<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Integrates with the Facebook Marketing API to create and manage
 * Facebook and Instagram ad campaigns from the admin panel.
 * All API calls use PHP's curl extension (no Facebook PHP SDK needed).
 *
 * Credentials are read from the environment: META_AD_ACCOUNT_ID,
 * META_ADS_ACCESS_TOKEN, and META_APP_ID. When any of those values are
 * absent, isConfigured() returns false and no API calls are made.
 *
 * All methods are static; this class is never instantiated.
 *
 * @see https://developers.facebook.com/docs/marketing-apis
 */
final class FacebookAdsService
{
    /** Facebook Graph API version used for every request. */
    private const API_VERSION = 'v20.0';

    /** Base URL for the Graph API (no trailing slash). */
    private const BASE_URL = 'https://graph.facebook.com/';

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns true if all three Meta Marketing API credentials are configured.
     *
     * Checks META_AD_ACCOUNT_ID, META_ADS_ACCESS_TOKEN, and META_APP_ID.
     * When any is absent or empty the service is considered unconfigured and
     * every other method will decline to make API calls.
     *
     * @return bool True when all three env vars are present and non-empty.
     *
     * @example
     *   if (FacebookAdsService::isConfigured()) { FacebookAdsService::createCampaign(...); }
     */
    public static function isConfigured(): bool
    {
        return Config::get('META_AD_ACCOUNT_ID',     '') !== ''
            && Config::get('META_ADS_ACCESS_TOKEN',  '') !== ''
            && Config::get('META_APP_ID',            '') !== '';
    }

    /**
     * Uploads an image file to the ad account's image library.
     *
     * Uses a multipart POST to /act_{accountId}/adimages with the raw file
     * contents in the `filename` field. Returns the image hash and CDN URL on
     * success, or null on any failure.
     *
     * @param string $filePath Absolute path to the image file on disk.
     *
     * @return array{hash: string, url: string}|null  null on failure.
     *
     * @example
     *   $img = FacebookAdsService::uploadImage('/tmp/ad.jpg');
     *   if ($img) { echo $img['hash']; }
     */
    public static function uploadImage(string $filePath): ?array
    {
        $accountId = self::accountId();
        $endpoint  = "{$accountId}/adimages";

        $data = ['filename' => new \CURLFile($filePath)];

        $response = self::post($endpoint, $data, multipart: true);

        if ($response === null) {
            return null;
        }

        // Response shape: {"images": {"<filename>": {"hash": "...", "url": "..."}}}
        $images = $response['images'] ?? [];
        if (empty($images)) {
            error_log('[FacebookAdsService] uploadImage: no images key in response.');
            return null;
        }

        $entry = reset($images);

        if (empty($entry['hash'])) {
            error_log('[FacebookAdsService] uploadImage: missing hash in response entry.');
            return null;
        }

        return [
            'hash' => (string) $entry['hash'],
            'url'  => (string) ($entry['url'] ?? ''),
        ];
    }

    /**
     * Creates a complete ad campaign with one ad set and one or two ads.
     *
     * If $creativeB is provided, a second ad (variant B) is created in the same
     * ad set so Facebook's delivery system A/B tests them automatically.
     *
     * Sequence:
     *   1. POST /act_{accountId}/campaigns
     *   2. POST /act_{accountId}/adsets
     *   3. POST /act_{accountId}/adcreatives  (creative A)
     *   4. POST /act_{accountId}/ads          (ad A)
     *   5. If $creativeB: repeat steps 3–4 for creative/ad B.
     *
     * @param array{
     *   name: string,
     *   objective: string,
     *   daily_budget: int,
     *   start_time: string,
     *   end_time: string,
     *   location: string,
     *   age_min: int,
     *   age_max: int,
     * } $campaign Campaign-level settings. daily_budget is in cents (1000 = $10/day).
     *             objective must be one of: TRAFFIC, LEAD_GENERATION, REACH, CONVERSIONS.
     *             location is a city name or lat,lng radius string.
     *
     * @param array{
     *   image_hash: string,
     *   headline: string,
     *   body: string,
     *   cta_type: string,
     *   link_url: string,
     * } $creativeA Creative for variant A. image_hash comes from uploadImage().
     *              cta_type must be one of: LEARN_MORE, SHOP_NOW, ORDER_NOW, GET_QUOTE.
     *
     * @param array{
     *   image_hash: string,
     *   headline: string,
     *   body: string,
     *   cta_type: string,
     *   link_url: string,
     * }|null $creativeB Optional creative for variant B (same structure as $creativeA).
     *
     * @return array{
     *   campaign_id: string,
     *   adset_id: string,
     *   ad_a_id: string,
     *   ad_b_id: ?string,
     * }|null  null on any step failure.
     *
     * @example
     *   $ids = FacebookAdsService::createCampaign($campaign, $creativeA, $creativeB);
     *   if ($ids) { saveToDb($ids['campaign_id'], $ids['ad_a_id']); }
     */
    public static function createCampaign(
        array  $campaign,
        array  $creativeA,
        ?array $creativeB = null
    ): ?array {
        $accountId = self::accountId();

        // ------------------------------------------------------------------
        // Step 1: Campaign
        // ------------------------------------------------------------------
        $campaignRes = self::post("{$accountId}/campaigns", [
            'name'                => $campaign['name'],
            'objective'           => $campaign['objective'],
            'status'              => 'ACTIVE',
            'special_ad_categories' => '[]',
        ]);

        if ($campaignRes === null || empty($campaignRes['id'])) {
            error_log('[FacebookAdsService] createCampaign: campaign creation failed.');
            return null;
        }

        $campaignId = (string) $campaignRes['id'];

        // ------------------------------------------------------------------
        // Step 2: Ad Set
        // ------------------------------------------------------------------
        $optimizationGoal = self::optimizationGoalFor($campaign['objective']);

        $adsetRes = self::post("{$accountId}/adsets", [
            'name'              => $campaign['name'] . ' — Ad Set',
            'campaign_id'       => $campaignId,
            'daily_budget'      => (int) $campaign['daily_budget'],
            'billing_event'     => 'IMPRESSIONS',
            'optimization_goal' => $optimizationGoal,
            'bid_strategy'      => 'LOWEST_COST_WITHOUT_CAP',
            'targeting'         => json_encode([
                'geo_locations' => [
                    'cities' => [['key' => $campaign['location']]],
                ],
                'age_min'            => (int) ($campaign['age_min'] ?? 18),
                'age_max'            => (int) ($campaign['age_max'] ?? 65),
                'publisher_platforms' => ['facebook', 'instagram'],
            ]),
            'start_time'        => $campaign['start_time'],
            'end_time'          => $campaign['end_time'],
            'status'            => 'ACTIVE',
        ]);

        if ($adsetRes === null || empty($adsetRes['id'])) {
            error_log('[FacebookAdsService] createCampaign: ad set creation failed.');
            return null;
        }

        $adsetId = (string) $adsetRes['id'];

        // ------------------------------------------------------------------
        // Step 3 & 4: Creative A + Ad A
        // ------------------------------------------------------------------
        $adAId = self::createCreativeAndAd(
            $accountId,
            $adsetId,
            $campaign['name'] . ' — A',
            $creativeA
        );

        if ($adAId === null) {
            return null;
        }

        // ------------------------------------------------------------------
        // Step 5: Optional Creative B + Ad B
        // ------------------------------------------------------------------
        $adBId = null;

        if ($creativeB !== null) {
            $adBId = self::createCreativeAndAd(
                $accountId,
                $adsetId,
                $campaign['name'] . ' — B',
                $creativeB
            );
            // A null $adBId is non-fatal — we still return A's IDs.
        }

        return [
            'campaign_id' => $campaignId,
            'adset_id'    => $adsetId,
            'ad_a_id'     => $adAId,
            'ad_b_id'     => $adBId,
        ];
    }

    /**
     * Fetches performance insights for one or more ad IDs.
     *
     * Issues one GET /{adId}/insights request per ad ID. Failures for
     * individual IDs are silently skipped (logged), so partial results may
     * be returned.
     *
     * @param string[] $adIds       Meta ad IDs to query.
     * @param string   $datePreset  One of: today, last_7d, last_30d, last_90d.
     *
     * @return array<string, array{impressions: int, clicks: int, spend: float, actions: array}>
     *         Keyed by ad ID. Missing IDs were not returned by the API.
     *
     * @example
     *   $data = FacebookAdsService::getInsights(['987654321'], 'last_7d');
     *   echo $data['987654321']['impressions'];
     */
    public static function getInsights(array $adIds, string $datePreset = 'last_30d'): array
    {
        $results = [];

        foreach ($adIds as $adId) {
            $response = self::get("{$adId}/insights", [
                'fields'      => 'impressions,clicks,spend,actions',
                'date_preset' => $datePreset,
            ]);

            if ($response === null || empty($response['data'])) {
                error_log("[FacebookAdsService] getInsights: no data for ad {$adId}.");
                continue;
            }

            $row = $response['data'][0] ?? [];

            $results[$adId] = [
                'impressions' => (int)   ($row['impressions'] ?? 0),
                'clicks'      => (int)   ($row['clicks']      ?? 0),
                'spend'       => (float) ($row['spend']       ?? 0.0),
                'actions'     => (array) ($row['actions']     ?? []),
            ];
        }

        return $results;
    }

    /**
     * Updates an ad's delivery status.
     *
     * @param string $adId   Meta ad ID.
     * @param string $status One of: ACTIVE, PAUSED, DELETED.
     *
     * @return bool True when the API acknowledged the update, false otherwise.
     *
     * @example
     *   FacebookAdsService::updateAdStatus('987654321', 'PAUSED');
     */
    public static function updateAdStatus(string $adId, string $status): bool
    {
        $response = self::post($adId, ['status' => $status]);

        if ($response === null) {
            return false;
        }

        return !empty($response['success']) || !empty($response['id']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Maps a campaign objective to the appropriate ad set optimization goal.
     *
     * Facebook requires that optimization_goal is compatible with the campaign
     * objective. These pairings follow Meta's Marketing API recommendations.
     *
     * @param string $objective TRAFFIC, LEAD_GENERATION, REACH, or CONVERSIONS.
     *
     * @return string The corresponding optimization_goal value.
     */
    private static function optimizationGoalFor(string $objective): string
    {
        return match ($objective) {
            'TRAFFIC'         => 'LINK_CLICKS',
            'LEAD_GENERATION' => 'LEAD_GENERATION',
            'REACH'           => 'REACH',
            'CONVERSIONS'     => 'OFFSITE_CONVERSIONS',
            default           => 'LINK_CLICKS',
        };
    }

    /**
     * Creates an ad creative then an ad that references it, returning the ad ID.
     *
     * Encapsulates the two-step create-creative / create-ad sequence used for
     * both variant A and variant B. Returns null and logs on any API failure.
     *
     * @param string $accountId The ad account ID (e.g. 'act_123456').
     * @param string $adsetId   The parent ad set ID.
     * @param string $name      Display name shared by the creative and the ad.
     * @param array{
     *   image_hash: string,
     *   headline: string,
     *   body: string,
     *   cta_type: string,
     *   link_url: string,
     * } $creative Creative details.
     *
     * @return string|null The created ad ID, or null on failure.
     */
    private static function createCreativeAndAd(
        string $accountId,
        string $adsetId,
        string $name,
        array  $creative
    ): ?string {
        $pageId = (string) Config::get('META_APP_ID', '');

        $creativeRes = self::post("{$accountId}/adcreatives", [
            'name'               => $name . ' Creative',
            'object_story_spec'  => json_encode([
                'page_id'   => $pageId,
                'link_data' => [
                    'image_hash'   => $creative['image_hash'],
                    'message'      => $creative['body'],
                    'link'         => $creative['link_url'],
                    'name'         => $creative['headline'],
                    'call_to_action' => [
                        'type' => $creative['cta_type'],
                    ],
                ],
            ]),
        ]);

        if ($creativeRes === null || empty($creativeRes['id'])) {
            error_log("[FacebookAdsService] createCreativeAndAd: creative creation failed for {$name}.");
            return null;
        }

        $creativeId = (string) $creativeRes['id'];

        $adRes = self::post("{$accountId}/ads", [
            'name'     => $name,
            'adset_id' => $adsetId,
            'creative' => json_encode(['creative_id' => $creativeId]),
            'status'   => 'ACTIVE',
        ]);

        if ($adRes === null || empty($adRes['id'])) {
            error_log("[FacebookAdsService] createCreativeAndAd: ad creation failed for {$name}.");
            return null;
        }

        return (string) $adRes['id'];
    }

    /**
     * Makes a GET request to the Graph API.
     *
     * Appends access_token to the query string. Returns the decoded JSON body
     * on 2xx responses, or null on curl error or non-2xx status.
     *
     * @param string               $endpoint Graph API path (without base URL or version).
     * @param array<string, mixed> $params   Additional query parameters.
     *
     * @return array|null Decoded response body, or null on failure.
     */
    private static function get(string $endpoint, array $params = []): ?array
    {
        $params['access_token'] = self::token();
        $url = self::BASE_URL . self::API_VERSION . '/' . ltrim($endpoint, '/');
        $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        if ($ch === false) {
            error_log("[FacebookAdsService] GET curl_init failed for {$endpoint}.");
            return null;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '' || !is_string($raw)) {
            error_log("[FacebookAdsService] GET curl error for {$endpoint}: {$curlErr}");
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("[FacebookAdsService] GET non-2xx ({$httpCode}) for {$endpoint}: {$raw}");
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Makes a POST request to the Graph API.
     *
     * Includes access_token in the POST body. When $multipart is true the
     * body is sent as multipart/form-data (required for file uploads);
     * otherwise application/x-www-form-urlencoded is used.
     *
     * @param string               $endpoint   Graph API path (without base URL or version).
     * @param array<string, mixed> $data       POST body fields.
     * @param bool                 $multipart  True for file upload requests.
     *
     * @return array|null Decoded response body, or null on failure.
     */
    private static function post(string $endpoint, array $data = [], bool $multipart = false): ?array
    {
        if (!$multipart) {
            $data['access_token'] = self::token();
        }

        $url = self::BASE_URL . self::API_VERSION . '/' . ltrim($endpoint, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            error_log("[FacebookAdsService] POST curl_init failed for {$endpoint}.");
            return null;
        }

        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        30);

        if ($multipart) {
            // Add token as a query parameter instead for multipart requests.
            $url .= '?access_token=' . urlencode(self::token());
            curl_setopt($ch, CURLOPT_URL,        $url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '' || !is_string($raw)) {
            error_log("[FacebookAdsService] POST curl error for {$endpoint}: {$curlErr}");
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("[FacebookAdsService] POST non-2xx ({$httpCode}) for {$endpoint}: {$raw}");
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Returns the Meta Ads access token from Config.
     *
     * @return string The META_ADS_ACCESS_TOKEN value.
     */
    private static function token(): string
    {
        return (string) Config::get('META_ADS_ACCESS_TOKEN', '');
    }

    /**
     * Returns the ad account ID from Config, e.g. 'act_123456'.
     *
     * @return string The META_AD_ACCOUNT_ID value.
     */
    private static function accountId(): string
    {
        return (string) Config::get('META_AD_ACCOUNT_ID', '');
    }
}
