<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `product_categories` table.
 *
 * All methods are static; this class is never instantiated. All reads use the
 * read-only PDO connection to enforce least-privilege database access.
 *
 * Expected table schema:
 * ```sql
 * CREATE TABLE product_categories (
 *     id         INT AUTO_INCREMENT PRIMARY KEY,
 *     name_en    VARCHAR(120) NOT NULL,
 *     name_es    VARCHAR(120) NULL,
 *     slug       VARCHAR(120) NOT NULL,
 *     sort_order INT          NOT NULL DEFAULT 0,
 *     active     TINYINT(1)   NOT NULL DEFAULT 1,
 *     UNIQUE KEY uq_slug (slug)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ```
 *
 * @see \App\Core\Database  Supplies the read-only PDO connection.
 */
final class ProductCategory
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Returns all active categories ordered by sort_order ascending.
     *
     * Inactive categories (active = 0) are excluded so the public site only
     * ever displays categories that the admin has enabled.
     *
     * @return array<int, array{id: int, name_en: string, name_es: ?string, slug: string, sort_order: int}>
     *         Each row is a PDO::FETCH_ASSOC associative array.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $categories = ProductCategory::allActive();
     *   // [['id' => 1, 'name_en' => 'Bouquets', 'name_es' => 'Ramos', 'slug' => 'bouquets', 'sort_order' => 1], …]
     */
    public static function allActive(): array
    {
        $sql = <<<SQL
            SELECT id, name_en, name_es, slug, sort_order
            FROM product_categories
            WHERE active = 1
            ORDER BY sort_order ASC, id ASC
            SQL;

        $stmt = Database::ro()->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Finds a single active category by its URL slug.
     *
     * Returns null when the slug does not match any active category, so callers
     * can issue a 404 response without throwing exceptions.
     *
     * @param string $slug The URL slug to look up, e.g. 'bouquets'.
     *
     * @return array{id: int, name_en: string, name_es: ?string, slug: string, sort_order: int}|null
     *         The matching category row, or null when not found.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $cat = ProductCategory::findBySlug('bouquets');
     *   if ($cat === null) { return Response::notFound(); }
     */
    public static function findBySlug(string $slug): ?array
    {
        $sql = <<<SQL
            SELECT id, name_en, name_es, slug, sort_order
            FROM product_categories
            WHERE slug = ?
              AND active = 1
            LIMIT 1
            SQL;

        $stmt = Database::ro()->prepare($sql);
        $stmt->execute([$slug]);

        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }
}
