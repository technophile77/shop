<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `ad_campaigns` table.
 *
 * Read methods use the read-only PDO connection; write methods use the
 * read-write connection to enforce least-privilege database access.
 * All queries use prepared statements.
 *
 * Expected table schema (from migration 001_initial_schema.sql):
 * ```sql
 * CREATE TABLE IF NOT EXISTS ad_campaigns (
 *     id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
 *     name           VARCHAR(255)    NOT NULL,
 *     platform       ENUM('facebook', 'instagram', 'both', 'other') NOT NULL DEFAULT 'both',
 *     variant        CHAR(1)         NULL,
 *     ab_group_id    VARCHAR(64)     NULL,
 *     start_date     DATE            NULL,
 *     end_date       DATE            NULL,
 *     budget         DECIMAL(10,2)   NULL,
 *     spend          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
 *     impressions    INT UNSIGNED    NOT NULL DEFAULT 0,
 *     clicks         INT UNSIGNED    NOT NULL DEFAULT 0,
 *     revenue        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
 *     status         ENUM('draft', 'active', 'paused', 'completed') NOT NULL DEFAULT 'draft',
 *     ad_headline    VARCHAR(255)    NULL,
 *     ad_copy        TEXT            NULL,
 *     ad_image_path  VARCHAR(500)    NULL,
 *     ad_link        VARCHAR(500)    NULL,
 *     objective      ENUM('TRAFFIC','LEAD_GENERATION','REACH','CONVERSIONS') NOT NULL DEFAULT 'TRAFFIC',
 *     fb_campaign_id VARCHAR(64)     NULL,
 *     fb_adset_id    VARCHAR(64)     NULL,
 *     fb_ad_id       VARCHAR(64)     NULL,
 *     notes          TEXT            NULL,
 *     created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * );
 * ```
 *
 * @see \App\Services\FacebookAdsService  Uses this model to persist API-returned IDs.
 * @see \App\Controllers\Admin\CampaignsController  The controller that drives all writes.
 * @see \App\Core\Database                Supplies the PDO connections.
 */
final class AdCampaign
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Read methods (Database::ro())
    // -------------------------------------------------------------------------

    /**
     * Returns all ad_campaigns rows ordered by created_at DESC.
     *
     * @return array<int, array> All campaign rows, newest first.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $campaigns = AdCampaign::all();
     */
    public static function all(): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM ad_campaigns ORDER BY created_at DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Returns all campaigns, with A/B partner data joined for grouped display.
     *
     * Identical to all() — grouping into pairs is left to the caller or to
     * abPairs(). This method exists as a named alias that signals intent.
     *
     * @return array<int, array> All campaign rows, newest first.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $campaigns = AdCampaign::allWithPairs();
     */
    public static function allWithPairs(): array
    {
        return self::all();
    }

    /**
     * Returns a single ad_campaigns row by primary key.
     *
     * @param int $id The campaign's primary key.
     *
     * @return array|null The row, or null when no record with that ID exists.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $campaign = AdCampaign::find(7);
     *   if ($campaign === null) { return Response::notFound(); }
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM ad_campaigns WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Returns A/B test pairs: groups of exactly 2 campaigns sharing the same
     * non-null ab_group_id, keyed by that group ID.
     *
     * Campaigns are fetched ordered by variant ASC so variant 'A' always maps
     * to the 'a' key and variant 'B' to the 'b' key within each pair.
     *
     * @return array<string, array{a: array, b: array}> Pairs keyed by ab_group_id.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   foreach (AdCampaign::abPairs() as $groupId => $pair) {
     *       echo $pair['a']['name'] . ' vs ' . $pair['b']['name'];
     *   }
     */
    public static function abPairs(): array
    {
        $sql = <<<SQL
            SELECT *
            FROM ad_campaigns
            WHERE ab_group_id IS NOT NULL
            ORDER BY ab_group_id ASC, variant ASC
            SQL;

        $stmt = Database::ro()->prepare($sql);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        // Group by ab_group_id, then map variant → row.
        $groups = [];
        foreach ($rows as $row) {
            $gid     = (string) $row['ab_group_id'];
            $variant = strtolower((string) ($row['variant'] ?? 'a'));
            $groups[$gid][$variant] = $row;
        }

        // Keep only groups that have both an 'a' and a 'b' entry.
        $pairs = [];
        foreach ($groups as $gid => $variants) {
            if (isset($variants['a'], $variants['b'])) {
                $pairs[$gid] = ['a' => $variants['a'], 'b' => $variants['b']];
            }
        }

        return $pairs;
    }

    // -------------------------------------------------------------------------
    // Write methods (Database::rw())
    // -------------------------------------------------------------------------

    /**
     * Inserts a new ad_campaigns row and returns its auto-increment ID.
     *
     * @param array<string, mixed> $data Column values. Recognised keys match
     *                                   the ad_campaigns table column names.
     *
     * @return int The new campaign's primary key.
     *
     * @throws \PDOException When the INSERT fails.
     *
     * @example
     *   $id = AdCampaign::create(['name' => 'Spring Sale', 'platform' => 'both', ...]);
     */
    public static function create(array $data): int
    {
        $sql = <<<SQL
            INSERT INTO ad_campaigns
                (name, platform, variant, ab_group_id, start_date, end_date,
                 budget, status, ad_headline, ad_copy, ad_image_path, ad_link,
                 objective, fb_campaign_id, fb_adset_id, fb_ad_id, notes)
            VALUES
                (:name, :platform, :variant, :ab_group_id, :start_date, :end_date,
                 :budget, :status, :ad_headline, :ad_copy, :ad_image_path, :ad_link,
                 :objective, :fb_campaign_id, :fb_adset_id, :fb_ad_id, :notes)
            SQL;

        $db   = Database::rw();
        $stmt = $db->prepare($sql);

        $stmt->execute([
            ':name'           => $data['name']           ?? '',
            ':platform'       => $data['platform']       ?? 'both',
            ':variant'        => $data['variant']        ?? null,
            ':ab_group_id'    => $data['ab_group_id']    ?? null,
            ':start_date'     => $data['start_date']     ?? null,
            ':end_date'       => $data['end_date']       ?? null,
            ':budget'         => $data['budget']         ?? null,
            ':status'         => $data['status']         ?? 'draft',
            ':ad_headline'    => $data['ad_headline']    ?? null,
            ':ad_copy'        => $data['ad_copy']        ?? null,
            ':ad_image_path'  => $data['ad_image_path']  ?? null,
            ':ad_link'        => $data['ad_link']        ?? null,
            ':objective'      => $data['objective']      ?? 'TRAFFIC',
            ':fb_campaign_id' => $data['fb_campaign_id'] ?? null,
            ':fb_adset_id'    => $data['fb_adset_id']    ?? null,
            ':fb_ad_id'       => $data['fb_ad_id']       ?? null,
            ':notes'          => $data['notes']          ?? null,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Updates an existing ad_campaigns row by primary key.
     *
     * Only columns present in $data are updated; omitted columns keep their
     * current database values. Returns true when at least one row was matched.
     *
     * @param int                  $id   The campaign's primary key.
     * @param array<string, mixed> $data Column => value pairs to update.
     *
     * @return bool True when the UPDATE matched a row, false otherwise.
     *
     * @throws \PDOException When the UPDATE fails.
     *
     * @example
     *   AdCampaign::update(7, ['fb_ad_id' => '987654321', 'status' => 'active']);
     */
    public static function update(int $id, array $data): bool
    {
        $allowed = [
            'name', 'platform', 'variant', 'ab_group_id', 'start_date', 'end_date',
            'budget', 'spend', 'impressions', 'clicks', 'revenue', 'status',
            'ad_headline', 'ad_copy', 'ad_image_path', 'ad_link', 'objective',
            'fb_campaign_id', 'fb_adset_id', 'fb_ad_id', 'notes',
        ];

        $setClauses = [];
        $params     = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $setClauses[]     = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }

        if ($setClauses === []) {
            return false;
        }

        $params[':id'] = $id;
        $sql = 'UPDATE ad_campaigns SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

        $stmt = Database::rw()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Updates performance metrics fetched from the Insights API.
     *
     * Only overwrites the four metrics columns (impressions, clicks, spend,
     * revenue); all other columns are left untouched.
     *
     * @param int                                         $id       Campaign row ID.
     * @param array{impressions: int, clicks: int, spend: float, revenue: float} $insights
     *        Metric values. revenue defaults to 0.0 when absent.
     *
     * @return bool True when the UPDATE matched a row.
     *
     * @throws \PDOException When the UPDATE fails.
     *
     * @example
     *   AdCampaign::updateMetrics(7, ['impressions' => 15000, 'clicks' => 300, 'spend' => 42.50, 'revenue' => 0.0]);
     */
    public static function updateMetrics(int $id, array $insights): bool
    {
        $sql = <<<SQL
            UPDATE ad_campaigns
            SET impressions = :impressions,
                clicks      = :clicks,
                spend       = :spend,
                revenue     = :revenue
            WHERE id = :id
            SQL;

        $stmt = Database::rw()->prepare($sql);
        $stmt->execute([
            ':impressions' => (int)   ($insights['impressions'] ?? 0),
            ':clicks'      => (int)   ($insights['clicks']      ?? 0),
            ':spend'       => (float) ($insights['spend']       ?? 0.0),
            ':revenue'     => (float) ($insights['revenue']     ?? 0.0),
            ':id'          => $id,
        ]);

        return $stmt->rowCount() > 0;
    }
}
