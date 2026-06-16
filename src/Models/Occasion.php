<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `occasions` table.
 *
 * Occasions are named event contexts (e.g. Birthday, Sympathy) that can be
 * tagged onto products so the storefront can filter or present products by
 * occasion. Read methods use the read-only PDO connection; write methods use
 * the read-write connection. All queries use prepared statements.
 *
 * @see \App\Models\ProductOccasion  Join-table model linking products to occasions.
 * @see \App\Core\Database           Supplies the PDO connections.
 */
final class Occasion
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Read methods (use Database::ro())
    // -------------------------------------------------------------------------

    /**
     * Return all occasions ordered by sort_order ASC, then id ASC.
     *
     * Includes both active and inactive rows. Use {@see allActive()} for
     * customer-facing contexts where inactive items must be hidden.
     *
     * @return array<int, array> Each row is an assoc array of occasion columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $all = Occasion::all();
     */
    public static function all(): array
    {
        $stmt = Database::ro()->query(
            'SELECT * FROM occasions ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Return active occasions (active = 1) ordered by sort_order ASC, then id ASC.
     *
     * Suitable for customer-facing dropdowns, filter chips, and product forms
     * where only choosable occasions should appear.
     *
     * @return array<int, array> Each row is an assoc array of occasion columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $occasions = Occasion::allActive();
     */
    public static function allActive(): array
    {
        $stmt = Database::ro()->query(
            'SELECT * FROM occasions WHERE active = 1 ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a single occasion by its primary key.
     *
     * Returns null when no occasion with that ID exists, so callers can emit
     * a 404 without catching an exception.
     *
     * @param int $id The occasion's primary key.
     *
     * @return array|null The occasion row, or null when not found.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $occasion = Occasion::find(3);
     *   if ($occasion === null) { return Response::notFound(); }
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM occasions WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Find a single occasion by its URL slug.
     *
     * Slugs are unique (enforced by the database UNIQUE KEY). Returns null when
     * no matching occasion is found.
     *
     * @param string $slug The occasion's URL slug, e.g. 'birthday'.
     *
     * @return array|null The occasion row, or null when not found.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $occasion = Occasion::findBySlug('birthday');
     */
    public static function findBySlug(string $slug): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM occasions WHERE slug = ? LIMIT 1'
        );
        $stmt->execute([$slug]);

        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    // -------------------------------------------------------------------------
    // Write methods (use Database::rw())
    // -------------------------------------------------------------------------

    /**
     * Insert a new occasion row and return the new auto-increment ID.
     *
     * The caller is responsible for validating the slug before calling create().
     * A duplicate slug will throw a PDOException due to the UNIQUE KEY constraint.
     *
     * @param array $data Fields to insert; recognised keys: slug (required),
     *                    name_en (required), name_es, active, sort_order.
     *
     * @return int The new occasion's primary key.
     *
     * @throws \PDOException When the INSERT fails (e.g. a duplicate slug).
     *
     * @example
     *   $id = Occasion::create(['slug' => 'wedding', 'name_en' => 'Wedding', 'name_es' => 'Boda']);
     */
    public static function create(array $data): int
    {
        $db   = Database::rw();
        $stmt = $db->prepare(
            'INSERT INTO occasions (slug, name_en, name_es, active, sort_order)
             VALUES (:slug, :name_en, :name_es, :active, :sort_order)'
        );

        $stmt->execute([
            ':slug'       => $data['slug']       ?? '',
            ':name_en'    => $data['name_en']    ?? '',
            ':name_es'    => isset($data['name_es']) && $data['name_es'] !== '' ? $data['name_es'] : null,
            ':active'     => (int) ($data['active']     ?? 1),
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Update an existing occasion by primary key.
     *
     * Only columns present in $data are updated; omitted columns retain their
     * current values. Returns true when at least one row was matched.
     *
     * @param int   $id   The occasion's primary key.
     * @param array $data Map of column => value to update. Recognised keys:
     *                    slug, name_en, name_es, active, sort_order.
     *
     * @return bool True when the UPDATE matched a row, false when the ID was not found.
     *
     * @throws \PDOException When the UPDATE fails (e.g. a duplicate slug).
     *
     * @example
     *   Occasion::update(3, ['name_en' => 'Get Well Soon', 'active' => 1]);
     */
    public static function update(int $id, array $data): bool
    {
        $allowed    = ['slug', 'name_en', 'name_es', 'active', 'sort_order'];
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
        $sql           = 'UPDATE occasions SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

        $stmt = Database::rw()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Permanently delete an occasion by primary key.
     *
     * Products linked via product_occasions are unlinked automatically by the
     * ON DELETE CASCADE foreign key constraint — no manual cleanup is needed.
     *
     * @param int $id The occasion's primary key.
     *
     * @return bool True when a row was deleted, false when the ID was not found.
     *
     * @throws \PDOException When the DELETE fails.
     *
     * @example
     *   Occasion::delete(3);
     */
    public static function delete(int $id): bool
    {
        $stmt = Database::rw()->prepare('DELETE FROM occasions WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
