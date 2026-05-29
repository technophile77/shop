<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\AdCampaign;
use App\Services\FacebookAdsService;

/**
 * Admin controller for the Facebook/Instagram Campaigns section.
 *
 * Handles listing campaigns, creating new campaigns (with optional A/B
 * variants) via the Facebook Marketing API, showing live metrics on a
 * campaign detail page, and pausing active campaigns.
 *
 * When the Marketing API is not configured (META_* env vars absent), campaigns
 * are saved with status = 'draft' and no fb_* IDs. The admin can later launch
 * them manually from Facebook Ads Manager.
 *
 * Flash messages are stored in $_SESSION['flash'] as
 * ['type' => 'success'|'error', 'message' => '…'] and are read and cleared
 * by the view templates.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Models\AdCampaign               Provides all database read/write operations.
 * @see \App\Services\FacebookAdsService     Provides all Meta Marketing API calls.
 * @see \App\Controllers\BaseController      Provides render() and redirect().
 */
final class CampaignsController extends BaseController
{
    /** Absolute path to the shared product image upload directory. */
    private const UPLOAD_DIR = __DIR__ . '/../../../public/uploads/products/';

    /** Maximum allowed upload size in bytes (5 MB). */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** MIME types accepted for ad creative images. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    // -------------------------------------------------------------------------
    // GET /admin/campaigns
    // -------------------------------------------------------------------------

    /**
     * Render the campaign list, including A/B pair groups.
     *
     * Loads all campaigns from the database, extracts A/B pairs for the
     * highlighted comparison section, and passes a flag indicating whether
     * the Facebook API is configured so the view can show the setup notice.
     *
     * @param Request              $request HTTP request (unused for GET).
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/campaigns/list.
     *
     * @example
     *   (new CampaignsController())->index($request, []);
     */
    public function index(Request $_request, array $_params = []): Response
    {
        $campaigns    = AdCampaign::all();
        $abPairs      = AdCampaign::abPairs();
        $fbConfigured = FacebookAdsService::isConfigured();

        return Response::html(
            $this->render('admin/campaigns/list', [
                'campaigns'    => $campaigns,
                'abPairs'      => $abPairs,
                'fbConfigured' => $fbConfigured,
                'pageTitle'    => 'Campaigns',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/campaigns/new
    // -------------------------------------------------------------------------

    /**
     * Render the campaign creation form.
     *
     * Passes a Facebook-configured flag and a fresh CSRF token.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/campaigns/form.
     *
     * @example
     *   (new CampaignsController())->newForm($request, []);
     */
    public function newForm(Request $_request, array $_params = []): Response
    {
        $fbConfigured = FacebookAdsService::isConfigured();
        $csrfToken    = $_request->csrfToken();

        return Response::html(
            $this->render('admin/campaigns/form', [
                'fbConfigured' => $fbConfigured,
                'csrfToken'    => $csrfToken,
                'pageTitle'    => 'New Campaign',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/campaigns
    // -------------------------------------------------------------------------

    /**
     * Handle campaign creation form submission.
     *
     * Validates CSRF, processes image uploads for creative A (required) and
     * creative B (optional, only when is_ab_test is checked). When the
     * Facebook API is configured the campaign is pushed to Meta and DB records
     * are saved with the returned IDs; otherwise records are saved as drafts.
     *
     * A/B pairs share the same ab_group_id (a uniqid() string). Variant A is
     * stored with variant='A' and variant B with variant='B'.
     *
     * @param Request              $request HTTP request containing POST data and
     *                                      file uploads under 'image_a' and 'image_b'.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/campaigns/{id} or /admin/campaigns/new.
     *
     * @example
     *   (new CampaignsController())->create($request, []);
     */
    public function create(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/campaigns/new');
        }

        // Collect and sanitise form fields.
        $str    = fn (string $k): string  => strip_tags(trim((string) $request->post($k, '')));
        $int    = fn (string $k, int $d): int => (int) ($request->post($k, $d) ?? $d);
        $isAbTest = $request->post('is_ab_test') !== null;

        $name              = $str('name');
        $platform          = $str('platform')  ?: 'both';
        $objective         = $str('objective') ?: 'TRAFFIC';
        $dailyBudgetDollar = (float) ($request->post('daily_budget_dollars', 10) ?? 10);
        $dailyBudgetCents  = (int) round($dailyBudgetDollar * 100);
        $startDate         = $str('start_date');
        $endDate           = $str('end_date');
        $location          = $str('location');
        $ageMin            = $int('age_min', 18);
        $ageMax            = $int('age_max', 65);

        // Creative A fields.
        $headlineA = $str('ad_headline_a');
        $copyA     = $str('ad_copy_a');
        $linkA     = $str('ad_link_a');
        $ctaA      = $str('ad_cta_a') ?: 'LEARN_MORE';

        // Creative B fields (only used when is_ab_test).
        $headlineB = $str('headline_b');
        $copyB     = $str('copy_b');
        $linkB     = $str('link_b');
        $ctaB      = $str('cta_b') ?: 'LEARN_MORE';

        if ($name === '') {
            $this->setFlash('error', 'Campaign name is required.');
            return $this->redirect('/admin/campaigns/new');
        }

        // Handle image upload for creative A (required).
        $imagePathA = $this->handleImageUpload($request, 'image_a', required: true);
        if ($imagePathA === false) {
            return $this->redirect('/admin/campaigns/new');
        }

        // Handle image upload for creative B (optional, only when A/B test).
        $imagePathB = null;
        if ($isAbTest) {
            $imagePathB = $this->handleImageUpload($request, 'image_b', required: false);
            if ($imagePathB === false) {
                return $this->redirect('/admin/campaigns/new');
            }
        }

        $fbConfigured = FacebookAdsService::isConfigured();

        if ($fbConfigured) {
            return $this->createViaApi(
                name:              $name,
                platform:          $platform,
                objective:         $objective,
                dailyBudgetCents:  $dailyBudgetCents,
                dailyBudgetDollar: $dailyBudgetDollar,
                startDate:         $startDate,
                endDate:           $endDate,
                location:          $location,
                ageMin:            $ageMin,
                ageMax:            $ageMax,
                headlineA:         $headlineA,
                copyA:             $copyA,
                linkA:             $linkA,
                ctaA:              $ctaA,
                imagePathA:        (string) $imagePathA,
                isAbTest:          $isAbTest,
                headlineB:         $headlineB,
                copyB:             $copyB,
                linkB:             $linkB,
                ctaB:              $ctaB,
                imagePathB:        $imagePathB,
            );
        }

        return $this->createAsDraft(
            name:              $name,
            platform:          $platform,
            objective:         $objective,
            dailyBudgetDollar: $dailyBudgetDollar,
            startDate:         $startDate,
            endDate:           $endDate,
            headlineA:         $headlineA,
            copyA:             $copyA,
            linkA:             $linkA,
            imagePathA:        (string) $imagePathA,
            isAbTest:          $isAbTest,
            headlineB:         $headlineB,
            copyB:             $copyB,
            linkB:             $linkB,
            imagePathB:        $imagePathB,
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/campaigns/{id}
    // -------------------------------------------------------------------------

    /**
     * Render the campaign detail page with live metrics and A/B comparison.
     *
     * When fb_ad_id is set, fetches fresh insights from the Facebook API and
     * updates the database before rendering. If this campaign is part of an
     * A/B pair, the partner campaign is loaded and passed for side-by-side
     * comparison.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Rendered HTML for admin/campaigns/detail, or 404.
     *
     * @example
     *   (new CampaignsController())->show($request, ['id' => '7']);
     */
    public function show(Request $request, array $params = []): Response
    {
        $id       = (int) ($params['id'] ?? 0);
        $campaign = AdCampaign::find($id);

        if ($campaign === null) {
            return Response::notFound();
        }

        // Fetch live metrics from Facebook and persist them.
        if (!empty($campaign['fb_ad_id'])) {
            $insights = FacebookAdsService::getInsights([$campaign['fb_ad_id']]);
            $row      = $insights[$campaign['fb_ad_id']] ?? null;

            if ($row !== null) {
                AdCampaign::updateMetrics($id, $row);
                // Refresh the local array to reflect the stored values.
                $campaign['impressions'] = $row['impressions'];
                $campaign['clicks']      = $row['clicks'];
                $campaign['spend']       = $row['spend'];
            }
        }

        // Load A/B partner when present.
        $partner = null;
        if (!empty($campaign['ab_group_id'])) {
            $pairs   = AdCampaign::abPairs();
            $groupId = (string) $campaign['ab_group_id'];
            $pair    = $pairs[$groupId] ?? null;

            if ($pair !== null) {
                // The partner is whichever element of the pair is NOT this campaign.
                $partner = ((int) $pair['a']['id'] === $id) ? $pair['b'] : $pair['a'];
            }
        }

        // Derived metrics (guard against division by zero).
        $impressions = (int)   ($campaign['impressions'] ?? 0);
        $clicks      = (int)   ($campaign['clicks']      ?? 0);
        $spend       = (float) ($campaign['spend']       ?? 0.0);

        $ctr = $impressions > 0 ? number_format($clicks / $impressions * 100, 2) : '0.00';
        $cpc = $clicks > 0      ? number_format($spend  / $clicks,            2) : '—';
        $cpm = $impressions > 0 ? number_format($spend  / $impressions * 1000, 2) : '—';

        // Determine A/B winner by CTR.
        $isWinner = false;
        if ($partner !== null) {
            $partnerImpressions = (int)   ($partner['impressions'] ?? 0);
            $partnerClicks      = (int)   ($partner['clicks']      ?? 0);
            $thisCtr            = $impressions > 0 ? $clicks / $impressions : 0;
            $partnerCtr         = $partnerImpressions > 0 ? $partnerClicks / $partnerImpressions : 0;
            $isWinner           = $thisCtr >= $partnerCtr;
        }

        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/campaigns/detail', [
                'campaign'   => $campaign,
                'partner'    => $partner,
                'ctr'        => $ctr,
                'cpc'        => $cpc,
                'cpm'        => $cpm,
                'isWinner'   => $isWinner,
                'csrfToken'  => $csrfToken,
                'pageTitle'  => htmlspecialchars($campaign['name'] ?? 'Campaign'),
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/campaigns/{id}/pause
    // -------------------------------------------------------------------------

    /**
     * Pause an active campaign via the Facebook API and update the DB status.
     *
     * Validates CSRF, calls the Meta API when fb_ad_id is set, then sets
     * status to 'paused' in the database regardless of API outcome (so the
     * admin always sees the intended state even when the API is unavailable).
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/campaigns/{id}.
     *
     * @example
     *   (new CampaignsController())->pause($request, ['id' => '7']);
     */
    public function pause(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect("/admin/campaigns/{$id}");
        }

        $campaign = AdCampaign::find($id);

        if ($campaign === null) {
            return Response::notFound();
        }

        // Call the Meta API when we have an ad ID.
        if (!empty($campaign['fb_ad_id'])) {
            $ok = FacebookAdsService::updateAdStatus((string) $campaign['fb_ad_id'], 'PAUSED');

            if (!$ok) {
                error_log("[CampaignsController] pause: Meta API call failed for campaign {$id}.");
            }
        }

        AdCampaign::update($id, ['status' => 'paused']);

        $this->setFlash('success', 'Campaign paused.');
        return $this->redirect("/admin/campaigns/{$id}");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Push the campaign to the Facebook Marketing API and save DB records.
     *
     * Uploads images, calls FacebookAdsService::createCampaign(), then saves
     * campaign A (and optionally campaign B) to the database. Returns a
     * redirect to the newly created campaign's detail page.
     *
     * @return Response Redirect to /admin/campaigns/{id} or /admin/campaigns/new.
     */
    private function createViaApi(
        string  $name,
        string  $platform,
        string  $objective,
        int     $dailyBudgetCents,
        float   $dailyBudgetDollar,
        string  $startDate,
        string  $endDate,
        string  $location,
        int     $ageMin,
        int     $ageMax,
        string  $headlineA,
        string  $copyA,
        string  $linkA,
        string  $ctaA,
        string  $imagePathA,
        bool    $isAbTest,
        string  $headlineB,
        string  $copyB,
        string  $linkB,
        string  $ctaB,
        ?string $imagePathB,
    ): Response {
        // Upload image A.
        $uploadA = FacebookAdsService::uploadImage(self::UPLOAD_DIR . $imagePathA);
        if ($uploadA === null) {
            $this->setFlash('error', 'Failed to upload creative A image to Facebook. Please try again.');
            return $this->redirect('/admin/campaigns/new');
        }

        // Upload image B when provided.
        $uploadB = null;
        if ($isAbTest && $imagePathB !== null) {
            $uploadB = FacebookAdsService::uploadImage(self::UPLOAD_DIR . $imagePathB);
            // Non-fatal — continue without variant B if upload fails.
            if ($uploadB === null) {
                error_log('[CampaignsController] createViaApi: image B upload failed; skipping variant B.');
                $isAbTest = false;
            }
        }

        // Build API parameter arrays.
        $campaignParams = [
            'name'         => $name,
            'objective'    => $objective,
            'daily_budget' => $dailyBudgetCents,
            'start_time'   => $startDate ? date('c', strtotime($startDate)) : date('c'),
            'end_time'     => $endDate   ? date('c', strtotime($endDate))   : date('c', strtotime('+30 days')),
            'location'     => $location,
            'age_min'      => $ageMin,
            'age_max'      => $ageMax,
        ];

        $creativeA = [
            'image_hash' => $uploadA['hash'],
            'headline'   => $headlineA,
            'body'       => $copyA,
            'cta_type'   => $ctaA,
            'link_url'   => $linkA,
        ];

        $creativeB = null;
        if ($isAbTest && $uploadB !== null) {
            $creativeB = [
                'image_hash' => $uploadB['hash'],
                'headline'   => $headlineB,
                'body'       => $copyB,
                'cta_type'   => $ctaB,
                'link_url'   => $linkB,
            ];
        }

        $fbIds = FacebookAdsService::createCampaign($campaignParams, $creativeA, $creativeB);

        if ($fbIds === null) {
            $this->setFlash('error', 'Facebook campaign creation failed. Campaign saved as draft.');
            return $this->createAsDraft(
                name:              $name,
                platform:          $platform,
                objective:         $objective,
                dailyBudgetDollar: $dailyBudgetDollar,
                startDate:         $startDate,
                endDate:           $endDate,
                headlineA:         $headlineA,
                copyA:             $copyA,
                linkA:             $linkA,
                imagePathA:        $imagePathA,
                isAbTest:          $isAbTest,
                headlineB:         $headlineB,
                copyB:             $copyB,
                linkB:             $linkB,
                imagePathB:        $imagePathB,
            );
        }

        // Save campaign A.
        $abGroupId = $isAbTest ? uniqid('abg_', true) : null;

        $idA = AdCampaign::create([
            'name'           => $name . ($isAbTest ? ' — A' : ''),
            'platform'       => $platform,
            'variant'        => $isAbTest ? 'A' : null,
            'ab_group_id'    => $abGroupId,
            'start_date'     => $startDate ?: null,
            'end_date'       => $endDate   ?: null,
            'budget'         => $dailyBudgetDollar,
            'status'         => 'active',
            'ad_headline'    => $headlineA,
            'ad_copy'        => $copyA,
            'ad_image_path'  => $imagePathA,
            'ad_link'        => $linkA,
            'objective'      => $objective,
            'fb_campaign_id' => $fbIds['campaign_id'],
            'fb_adset_id'    => $fbIds['adset_id'],
            'fb_ad_id'       => $fbIds['ad_a_id'],
        ]);

        // Save campaign B when it was created.
        if ($isAbTest && $fbIds['ad_b_id'] !== null) {
            AdCampaign::create([
                'name'           => $name . ' — B',
                'platform'       => $platform,
                'variant'        => 'B',
                'ab_group_id'    => $abGroupId,
                'start_date'     => $startDate ?: null,
                'end_date'       => $endDate   ?: null,
                'budget'         => $dailyBudgetDollar,
                'status'         => 'active',
                'ad_headline'    => $headlineB,
                'ad_copy'        => $copyB,
                'ad_image_path'  => $imagePathB,
                'ad_link'        => $linkB,
                'objective'      => $objective,
                'fb_campaign_id' => $fbIds['campaign_id'],
                'fb_adset_id'    => $fbIds['adset_id'],
                'fb_ad_id'       => $fbIds['ad_b_id'],
            ]);
        }

        $this->setFlash('success', 'Campaign created and submitted to Facebook.');
        return $this->redirect("/admin/campaigns/{$idA}");
    }

    /**
     * Save a campaign (or A/B pair) to the database without calling the API.
     *
     * Used when the Facebook API is not configured, or as a fallback when the
     * API call fails. Records are saved with status = 'draft'.
     *
     * @return Response Redirect to /admin/campaigns/{id}.
     */
    private function createAsDraft(
        string  $name,
        string  $platform,
        string  $objective,
        float   $dailyBudgetDollar,
        string  $startDate,
        string  $endDate,
        string  $headlineA,
        string  $copyA,
        string  $linkA,
        string  $imagePathA,
        bool    $isAbTest,
        string  $headlineB,
        string  $copyB,
        string  $linkB,
        ?string $imagePathB,
    ): Response {
        $abGroupId = $isAbTest ? uniqid('abg_', true) : null;

        $idA = AdCampaign::create([
            'name'          => $name . ($isAbTest ? ' — A' : ''),
            'platform'      => $platform,
            'variant'       => $isAbTest ? 'A' : null,
            'ab_group_id'   => $abGroupId,
            'start_date'    => $startDate ?: null,
            'end_date'      => $endDate   ?: null,
            'budget'        => $dailyBudgetDollar,
            'status'        => 'draft',
            'ad_headline'   => $headlineA,
            'ad_copy'       => $copyA,
            'ad_image_path' => $imagePathA,
            'ad_link'       => $linkA,
            'objective'     => $objective,
        ]);

        if ($isAbTest) {
            AdCampaign::create([
                'name'          => $name . ' — B',
                'platform'      => $platform,
                'variant'       => 'B',
                'ab_group_id'   => $abGroupId,
                'start_date'    => $startDate ?: null,
                'end_date'      => $endDate   ?: null,
                'budget'        => $dailyBudgetDollar,
                'status'        => 'draft',
                'ad_headline'   => $headlineB,
                'ad_copy'       => $copyB,
                'ad_image_path' => $imagePathB,
                'ad_link'       => $linkB,
                'objective'     => $objective,
            ]);
        }

        $this->setFlash('success', 'Campaign saved as draft. Launch it manually from Facebook Ads Manager.');
        return $this->redirect("/admin/campaigns/{$idA}");
    }

    /**
     * Validate and persist an uploaded ad creative image.
     *
     * Validates MIME type (JPEG, PNG, WebP only) and file size (max 5 MB).
     * Generates a collision-resistant filename, moves the temp file to the
     * shared upload directory, and returns the basename.
     *
     * Returns null when no file was uploaded (not an error when $required is
     * false), a filename string on success, or false on validation failure
     * (the flash message is set before returning false).
     *
     * @param Request $request  The HTTP request.
     * @param string  $field    The file input field name ('image_a' or 'image_b').
     * @param bool    $required When true, a missing upload is treated as an error.
     *
     * @return string|false|null
     *   - string  → saved filename (basename only).
     *   - null    → no file uploaded and $required is false.
     *   - false   → validation failed; flash error already set.
     */
    private function handleImageUpload(Request $request, string $field, bool $required): string|false|null
    {
        $file = $request->file($field);

        if ($file === null) {
            if ($required) {
                $this->setFlash('error', "Creative image is required (field: {$field}).");
                return false;
            }
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->setFlash('error', 'Image upload failed (error code ' . $file['error'] . ').');
            return false;
        }

        if ($file['size'] > self::MAX_BYTES) {
            $this->setFlash('error', 'Image must be smaller than 5 MB.');
            return false;
        }

        $mime = mime_content_type($file['tmp_name']);
        if ($mime === false || !in_array($mime, self::ALLOWED_MIME, true)) {
            $this->setFlash('error', 'Only JPEG, PNG, and WebP images are allowed.');
            return false;
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        };

        $filename = uniqid('ad_', true) . '.' . $ext;
        $dest     = self::UPLOAD_DIR . $filename;

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->setFlash('error', 'Failed to save the uploaded image.');
            return false;
        }

        return $filename;
    }

    /**
     * Store a flash message in the session for the next page render.
     *
     * @param 'success'|'error' $type    Severity level; controls CSS class selection.
     * @param string            $message Human-readable message to display.
     *
     * @return void
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}
