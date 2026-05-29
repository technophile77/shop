<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\PageView;
use App\Services\InstagramService;

/**
 * Renders the admin analytics dashboard.
 *
 * Aggregates three data sources and passes them to the analytics view:
 *   1. Site traffic — daily page view counts, top pages, top referrers.
 *   2. Ad attribution — UTM campaign conversion funnel from ad_sessions.
 *   3. Instagram insights — account stats, recent posts, 30-day metrics
 *      (only when META_IG_ACCESS_TOKEN and META_IG_USER_ID are configured).
 *
 * The route is protected by the 'auth' middleware; this controller does not
 * repeat the session check.
 *
 * @see \App\Controllers\BaseController    Provides render() and redirect().
 * @see \App\Models\PageView               Supplies all traffic aggregation methods.
 * @see \App\Services\InstagramService     Supplies Instagram Graph API data.
 */
final class AnalyticsController extends BaseController
{
    /**
     * Renders the analytics overview page.
     *
     * Data collected:
     *   - dailyCounts    — page views per day for the last 30 days.
     *   - topPages       — top 10 pages by view count.
     *   - topReferrers   — top 10 referrers by visit count.
     *   - campaignFunnel — UTM campaign conversion funnel from ad_sessions.
     *   - igConfigured   — true when Instagram credentials are set.
     *   - igAccount      — account info (followers, posts, username) or null.
     *   - igPosts        — 9 most recent posts with engagement metrics or null.
     *   - igInsights     — 30-day account-level reach/impressions or null.
     *
     * @param Request              $request Current HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response HTML response for the analytics page.
     *
     * @example
     *   // Dispatched by the router for GET /admin/analytics
     *   $response = (new AnalyticsController())->index($request, []);
     */
    public function index(Request $request, array $params = []): Response
    {
        $igConfigured = InstagramService::isConfigured();

        return Response::html(
            $this->render('admin/analytics/index', [
                'pageTitle'      => 'Analytics',
                'dailyCounts'    => PageView::dailyCounts(30),
                'topPages'       => PageView::topPages(),
                'topReferrers'   => PageView::topReferrers(),
                'campaignFunnel' => PageView::campaignFunnel(),
                'igConfigured'   => $igConfigured,
                'igAccount'      => $igConfigured ? InstagramService::accountInfo()    : null,
                'igPosts'        => $igConfigured ? InstagramService::recentPosts(9)   : null,
                'igInsights'     => $igConfigured ? InstagramService::accountInsights() : null,
            ], 'admin')
        );
    }
}
