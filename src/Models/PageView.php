<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Static model for recording and querying page-view and ad-session data.
 *
 * All write methods swallow exceptions silently so that tracking failures
 * never interrupt a page load. Read methods propagate exceptions normally
 * because they are used in admin views that should surface data errors.
 *
 * @see \App\Core\Database  Supplies the read-only and read-write PDO connections.
 */
final class PageView
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Records a single page view in the page_views table.
     *
     * Silently swallows any database errors so tracking can never break
     * a page load. The raw client IP is never stored; only a SHA-256 hash
     * (salted with the User-Agent) is persisted.
     *
     * @param string   $sessionToken  A stable per-session identifier: the PHP
     *                                session ID or a random hex string when no
     *                                session exists.
     * @param string   $pageUrl       The current URL path without query string.
     * @param string   $referrer      HTTP_REFERER header value, or '' when absent.
     * @param array    $utm           UTM parameters keyed as source, medium,
     *                                campaign, content, term.
     * @param string   $ipHash        SHA-256 hash of client IP + User-Agent.
     * @param int|null $customerId    Linked customer ID when the visitor is known.
     *
     * @example
     *   PageView::record(session_id(), '/products', 'https://google.com', $_SESSION['utm'] ?? [], $ipHash);
     */
    public static function record(
        string $sessionToken,
        string $pageUrl,
        string $referrer,
        array  $utm,
        string $ipHash,
        ?int   $customerId = null
    ): void {
        try {
            $stmt = Database::rw()->prepare(
                'INSERT INTO page_views
                    (session_token, page_url, referrer,
                     utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                     ip_hash, customer_id)
                 VALUES
                    (:session_token, :page_url, :referrer,
                     :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term,
                     :ip_hash, :customer_id)'
            );

            $stmt->execute([
                ':session_token' => $sessionToken,
                ':page_url'      => $pageUrl,
                ':referrer'      => $referrer,
                ':utm_source'    => $utm['source']   ?? '',
                ':utm_medium'    => $utm['medium']   ?? '',
                ':utm_campaign'  => $utm['campaign'] ?? '',
                ':utm_content'   => $utm['content']  ?? '',
                ':utm_term'      => $utm['term']     ?? '',
                ':ip_hash'       => $ipHash,
                ':customer_id'   => $customerId,
            ]);
        } catch (\Throwable) {
            // Tracking must never break a page load.
        }
    }

    /**
     * Records an ad session when a visitor arrives with UTM parameters.
     *
     * Uses INSERT IGNORE so a duplicate session_token never creates a second
     * row — the attribution is locked to the first UTM-tagged landing. Silently
     * swallows errors.
     *
     * @param string $sessionToken  Per-session identifier (matches page_views).
     * @param array  $utm           UTM parameters keyed as source, medium,
     *                              campaign, content, term.
     * @param string $ipHash        SHA-256 hash of client IP + User-Agent.
     *
     * @example
     *   if (!empty($_GET['utm_source'])) {
     *       PageView::recordAdSession(session_id(), $_SESSION['utm'], $ipHash);
     *   }
     */
    public static function recordAdSession(string $sessionToken, array $utm, string $ipHash): void
    {
        try {
            $stmt = Database::rw()->prepare(
                'INSERT IGNORE INTO ad_sessions
                    (session_token, utm_source, utm_medium, utm_campaign,
                     utm_content, utm_term, ip_hash, conversion_type)
                 VALUES
                    (:session_token, :utm_source, :utm_medium, :utm_campaign,
                     :utm_content, :utm_term, :ip_hash, \'none\')'
            );

            $stmt->execute([
                ':session_token' => $sessionToken,
                ':utm_source'    => $utm['source']   ?? '',
                ':utm_medium'    => $utm['medium']   ?? '',
                ':utm_campaign'  => $utm['campaign'] ?? '',
                ':utm_content'   => $utm['content']  ?? '',
                ':utm_term'      => $utm['term']     ?? '',
                ':ip_hash'       => $ipHash,
            ]);
        } catch (\Throwable) {
            // Tracking must never break a page load.
        }
    }

    /**
     * Marks an existing ad session as converted.
     *
     * Updates the first matching session_token row with the given conversion
     * type and the current timestamp. No-ops when the session is not found.
     *
     * @param string $sessionToken   Per-session identifier to update.
     * @param string $conversionType One of 'signup', 'order', or 'quote'.
     *
     * @example
     *   PageView::markConversion(session_id(), 'order');
     */
    public static function markConversion(string $sessionToken, string $conversionType): void
    {
        try {
            $stmt = Database::rw()->prepare(
                'UPDATE ad_sessions
                 SET conversion_type = :type, converted_at = NOW()
                 WHERE session_token = :token'
            );

            $stmt->execute([
                ':type'  => $conversionType,
                ':token' => $sessionToken,
            ]);
        } catch (\Throwable) {
            // Tracking must never break a page load.
        }
    }

    /**
     * Returns page view counts grouped by day for the last N days.
     *
     * Days with zero views are omitted from the result set. The array is
     * ordered chronologically (oldest day first).
     *
     * @param int $days Number of calendar days to look back (default 30).
     *
     * @return array<int, array{date: string, count: int}>
     *         Each element has a 'date' string (Y-m-d) and integer 'count'.
     *
     * @example
     *   $counts = PageView::dailyCounts(7);
     *   // [['date' => '2026-05-22', 'count' => 41], ...]
     */
    public static function dailyCounts(int $days = 30): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT DATE(created_at) AS date, COUNT(*) AS count
             FROM page_views
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY DATE(created_at)
             ORDER BY DATE(created_at) ASC'
        );

        $stmt->execute([':days' => $days]);

        return array_map(
            static fn (array $row): array => [
                'date'  => (string) $row['date'],
                'count' => (int)    $row['count'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Returns the top N referrers by total visit count.
     *
     * Empty referrer strings are excluded. Results are ordered descending by
     * count.
     *
     * @param int $limit Maximum number of referrers to return (default 10).
     *
     * @return array<int, array{referrer: string, count: int}>
     *
     * @example
     *   $referrers = PageView::topReferrers(5);
     *   // [['referrer' => 'https://instagram.com', 'count' => 87], ...]
     */
    public static function topReferrers(int $limit = 10): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT referrer, COUNT(*) AS count
             FROM page_views
             WHERE referrer != \'\'
             GROUP BY referrer
             ORDER BY count DESC
             LIMIT :lim'
        );

        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row): array => [
                'referrer' => (string) $row['referrer'],
                'count'    => (int)    $row['count'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Returns UTM campaign conversion funnel data from the ad_sessions table.
     *
     * Aggregates visit counts and per-type conversion counts for every unique
     * (campaign, source) pair that has at least one UTM-tagged visit. Rows are
     * ordered by visits descending.
     *
     * @return array<int, array{campaign: string, source: string, visits: int, signups: int, orders: int, quotes: int}>
     *
     * @example
     *   $funnel = PageView::campaignFunnel();
     *   // [['campaign' => 'spring_sale', 'source' => 'facebook', 'visits' => 120, 'signups' => 4, ...], ...]
     */
    public static function campaignFunnel(): array
    {
        $stmt = Database::ro()->query(
            "SELECT
                utm_campaign AS campaign,
                utm_source   AS source,
                COUNT(*)     AS visits,
                SUM(CASE WHEN conversion_type = 'signup' THEN 1 ELSE 0 END) AS signups,
                SUM(CASE WHEN conversion_type = 'order'  THEN 1 ELSE 0 END) AS orders,
                SUM(CASE WHEN conversion_type = 'quote'  THEN 1 ELSE 0 END) AS quotes
             FROM ad_sessions
             WHERE utm_campaign != '' OR utm_source != ''
             GROUP BY utm_campaign, utm_source
             ORDER BY visits DESC"
        );

        return array_map(
            static fn (array $row): array => [
                'campaign' => (string) $row['campaign'],
                'source'   => (string) $row['source'],
                'visits'   => (int)    $row['visits'],
                'signups'  => (int)    $row['signups'],
                'orders'   => (int)    $row['orders'],
                'quotes'   => (int)    $row['quotes'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Returns the top N pages by total view count.
     *
     * Results are ordered descending by count.
     *
     * @param int $limit Maximum number of pages to return (default 10).
     *
     * @return array<int, array{page_url: string, count: int}>
     *
     * @example
     *   $pages = PageView::topPages(5);
     *   // [['page_url' => '/products', 'count' => 312], ...]
     */
    public static function topPages(int $limit = 10): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT page_url, COUNT(*) AS count
             FROM page_views
             GROUP BY page_url
             ORDER BY count DESC
             LIMIT :lim'
        );

        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row): array => [
                'page_url' => (string) $row['page_url'],
                'count'    => (int)    $row['count'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }
}
