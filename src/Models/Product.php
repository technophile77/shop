<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `products` table.
 *
 * Read methods use the read-only PDO connection; write methods (create, update,
 * delete) use the read-write connection to enforce least-privilege database
 * access. All queries use prepared statements.
 *
 * Expected table schema:
 * ```sql
 * CREATE TABLE products (
 *     id          INT AUTO_INCREMENT PRIMARY KEY,
 *     category_id INT           NOT NULL,
 *     name_en     VARCHAR(200)  NOT NULL,
 *     name_es     VARCHAR(200)  NULL,
 *     description_en TEXT       NULL,
 *     description_es TEXT       NULL,
 *     image_path  VARCHAR(300)  NULL,
 *     price_from  DECIMAL(8,2)  NULL,
 *     price_to    DECIMAL(8,2)  NULL,
 *     featured    TINYINT(1)    NOT NULL DEFAULT 0,
 *     active      TINYINT(1)    NOT NULL DEFAULT 1,
 *     sort_order  INT           NOT NULL DEFAULT 0,
 *     created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
 *                               ON UPDATE CURRENT_TIMESTAMP,
 *     FOREIGN KEY (category_id) REFERENCES product_categories(id)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ```
 *
 * @see \App\Models\ProductCategory  Related category model.
 * @see \App\Core\Database           Supplies the PDO connections.
 */
final class Product
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -----------------------------------------------------------------------
    // Shared SQL fragment
    // -----------------------------------------------------------------------

    /**
     * Base SELECT/JOIN used by all public listing queries.
     *
     * Joins the product_categories table so each row includes localised
     * category names and the category slug for client-side filtering.
     *
     * @return string SQL fragment (without WHERE, ORDER BY, or LIMIT).
     */
    private static function baseSelect(): string
    {
        return <<<SQL
            SELECT p.*,
                   c.name_en AS category_name_en,
                   c.name_es AS category_name_es,
                   c.slug    AS category_slug
            FROM products p
            JOIN product_categories c ON c.id = p.category_id
            SQL;
    }

    // -----------------------------------------------------------------------
    // Read methods (use Database::ro())
    // -----------------------------------------------------------------------

    /**
     * Returns all active products joined with their category, ordered by
     * sort_order ASC then id ASC.
     *
     * Only products with active = 1 are returned; inactive products are
     * reserved for the admin interface via {@see find()}.
     *
     * @return array<int, array> Each row contains all product columns plus
     *                           category_name_en, category_name_es, category_slug.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $products = Product::allActive();
     */
    public static function allActive(): array
    {
        $sql = self::baseSelect() . <<<SQL

            WHERE p.active = 1
            ORDER BY p.sort_order ASC, p.id ASC
            SQL;

        $stmt = Database::ro()->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Returns featured active products joined with their category, limited to
     * $limit rows, ordered by sort_order ASC then id ASC.
     *
     * Suitable for homepage carousels or promotional sections where a fixed
     * maximum number of highlighted products is required.
     *
     * @param int $limit Maximum number of products to return. Defaults to 6.
     *
     * @return array<int, array> Each row contains all product columns plus
     *                           category_name_en, category_name_es, category_slug.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $featured = Product::featured(3); // at most 3 featured products
     */
    public static function featured(int $limit = 6): array
    {
        $sql = self::baseSelect() . <<<SQL

            WHERE p.active = 1
              AND p.featured = 1
            ORDER BY p.sort_order ASC, p.id ASC
            LIMIT ?
            SQL;

        $stmt = Database::ro()->prepare($sql);
        $stmt->execute([$limit]);

        return $stmt->fetchAll();
    }

    /**
     * Returns active products belonging to a given category, ordered by
     * sort_order ASC then id ASC.
     *
     * @param int $categoryId The category's primary key.
     *
     * @return array<int, array> Each row contains all product columns plus
     *                           category_name_en, category_name_es, category_slug.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $roses = Product::byCategory(2);
     */
    public static function byCategory(int $categoryId): array
    {
        $sql = self::baseSelect() . <<<SQL

            WHERE p.active = 1
              AND p.category_id = ?
            ORDER BY p.sort_order ASC, p.id ASC
            SQL;

        $stmt = Database::ro()->prepare($sql);
        $stmt->execute([$categoryId]);

        return $stmt->fetchAll();
    }

    /**
     * Finds a single product by primary key — active or inactive.
     *
     * Intended for the admin interface where editors need to view and edit
     * products regardless of their active status. The public site should use
     * {@see allActive()} or {@see byCategory()} instead.
     *
     * @param int $id The product's primary key.
     *
     * @return array|null The product row, or null when no record with that ID exists.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $product = Product::find(42);
     *   if ($product === null) { return Response::notFound(); }
     */
    public static function find(int $id): ?array
    {
        $sql = <<<SQL
            SELECT *
            FROM products
            WHERE id = ?
            LIMIT 1
            SQL;

        $stmt = Database::ro()->prepare($sql);
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    // -----------------------------------------------------------------------
    // Write methods (use Database::rw())
    // -----------------------------------------------------------------------

    /**
     * Inserts a new product row and returns its auto-increment ID.
     *
     * All column values are supplied by the caller via $data. Only keys that
     * correspond to actual product table columns should be included; extra keys
     * are silently ignored by the SQL.
     *
     * @param array $data Validated product fields, e.g.:
     *                    ['category_id' => 1, 'name_en' => 'Garden Bouquet',
     *                     'price_from' => 45.00, 'featured' => 0, 'active' => 1,
     *                     'sort_order' => 10, 'image_path' => 'abc.jpg'].
     *
     * @return int The new product's primary key.
     *
     * @throws \PDOException When the INSERT fails (e.g. a constraint violation).
     *
     * @example
     *   $id = Product::create(['category_id' => 1, 'name_en' => 'Garden Bouquet', …]);
     */
    public static function create(array $data): int
    {
        $sql = <<<SQL
            INSERT INTO products
                (category_id, name_en, name_es, description_en, description_es,
                 image_path, price_from, price_to, featured, active, sort_order)
            VALUES
                (:category_id, :name_en, :name_es, :description_en, :description_es,
                 :image_path, :price_from, :price_to, :featured, :active, :sort_order)
            SQL;

        $db   = Database::rw();
        $stmt = $db->prepare($sql);

        $stmt->execute([
            ':category_id'    => $data['category_id']    ?? null,
            ':name_en'        => $data['name_en']        ?? '',
            ':name_es'        => $data['name_es']        ?? null,
            ':description_en' => $data['description_en'] ?? null,
            ':description_es' => $data['description_es'] ?? null,
            ':image_path'     => $data['image_path']     ?? null,
            ':price_from'     => $data['price_from']     ?? null,
            ':price_to'       => $data['price_to']       ?? null,
            ':featured'       => (int) ($data['featured'] ?? 0),
            ':active'         => (int) ($data['active']   ?? 1),
            ':sort_order'     => (int) ($data['sort_order'] ?? 0),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Updates an existing product by primary key.
     *
     * Only the columns present in $data are updated; omitted columns retain
     * their current database values. Returns true when at least one row was
     * matched (even if no values changed).
     *
     * @param int   $id   The product's primary key.
     * @param array $data Associative array of column => value pairs to update.
     *                    Recognises: category_id, name_en, name_es, description_en,
     *                    description_es, image_path, price_from, price_to,
     *                    featured, active, sort_order.
     *
     * @return bool True when the UPDATE touched at least one row, false otherwise.
     *
     * @throws \PDOException When the UPDATE fails due to a database error.
     *
     * @example
     *   $ok = Product::update(42, ['price_from' => 55.00, 'featured' => 1]);
     */
    public static function update(int $id, array $data): bool
    {
        $allowed = [
            'category_id', 'name_en', 'name_es', 'description_en', 'description_es',
            'image_path', 'price_from', 'price_to', 'featured', 'active', 'sort_order',
        ];

        $setClauses = [];
        $params     = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $setClauses[] = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }

        if ($setClauses === []) {
            return false;
        }

        $params[':id'] = $id;
        $sql           = 'UPDATE products SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

        $stmt = Database::rw()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Soft-deletes a product by setting its active flag to 0.
     *
     * The row is preserved in the database so order history and admin audit
     * trails remain intact. The public site excludes soft-deleted products
     * automatically because all public queries filter on active = 1.
     *
     * @param int $id The product's primary key.
     *
     * @return bool True when the UPDATE matched a row, false when the ID was not found.
     *
     * @throws \PDOException When the UPDATE fails due to a database error.
     *
     * @example
     *   Product::delete(42); // sets active = 0
     */
    public static function delete(int $id): bool
    {
        $stmt = Database::rw()->prepare('UPDATE products SET active = 0 WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
