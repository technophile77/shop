<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `flower_types` table.
 *
 * Flower types (e.g. Roses, Carnations, Tulips) describe the kind of flower
 * used in an arrangement. Each type can be linked to products via product_flower_types
 * and to available colors via flower_type_colors.
 *
 * Read methods use the read-only PDO connection; write methods use the
 * read-write connection. All queries use prepared statements.
 *
 * @see \App\Models\FlowerTypeColor    Matrix of available colors per flower type.
 * @see \App\Models\ProductFlowerType  Join-table linking products to flower types.
 * @see \App\Core\Database             Supplies the PDO connections.
 */
final class FlowerType
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Read methods (use Database::ro())
    // -------------------------------------------------------------------------

    /**
     * Return all flower types ordered by sort_order ASC, then id ASC.
     *
     * Includes both active and inactive rows. Use {@see allActive()} for
     * contexts where inactive types must be hidden.
     *
     * @return array<int, array> Each row is an assoc array of flower_types columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $types = FlowerType::all();
     */
    public static function all(): array
    {
        $stmt = Database::ro()->query(
            'SELECT * FROM flower_types ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Return active flower types (active = 1) ordered by sort_order ASC, then id ASC.
     *
     * Suitable for customer-facing selectors and product edit forms where only
     * currently offered flower types should appear.
     *
     * @return array<int, array> Each row is an assoc array of flower_types columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $types = FlowerType::allActive();
     */
    public static function allActive(): array
    {
        $stmt = Database::ro()->query(
            'SELECT * FROM flower_types WHERE active = 1 ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a single flower type by its primary key.
     *
     * Returns null when no record with that ID exists so callers can emit a 404
     * without catching an exception.
     *
     * @param int $id The flower type's primary key.
     *
     * @return array|null The flower_types row, or null when not found.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $type = FlowerType::find(1);
     *   if ($type === null) { return Response::notFound(); }
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM flower_types WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    // -------------------------------------------------------------------------
    // Write methods (use Database::rw())
    // -------------------------------------------------------------------------

    /**
     * Insert a new flower type and return the new auto-increment ID.
     *
     * @param array $data Fields to insert; recognised keys: name_en (required),
     *                    name_es, active, sort_order.
     *
     * @return int The new flower type's primary key.
     *
     * @throws \PDOException When the INSERT fails.
     *
     * @example
     *   $id = FlowerType::create(['name_en' => 'Peonies', 'name_es' => 'Peonías']);
     */
    public static function create(array $data): int
    {
        $db   = Database::rw();
        $stmt = $db->prepare(
            'INSERT INTO flower_types (name_en, name_es, active, sort_order)
             VALUES (:name_en, :name_es, :active, :sort_order)'
        );

        $stmt->execute([
            ':name_en'    => $data['name_en']    ?? '',
            ':name_es'    => isset($data['name_es']) && $data['name_es'] !== '' ? $data['name_es'] : null,
            ':active'     => (int) ($data['active']     ?? 1),
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Update an existing flower type by primary key.
     *
     * Only columns present in $data are updated. Returns true when at least one
     * row was matched.
     *
     * @param int   $id   The flower type's primary key.
     * @param array $data Map of column => value to update. Recognised keys:
     *                    name_en, name_es, active, sort_order.
     *
     * @return bool True when the UPDATE matched a row, false when the ID was not found.
     *
     * @throws \PDOException When the UPDATE fails.
     *
     * @example
     *   FlowerType::update(1, ['sort_order' => 5, 'active' => 0]);
     */
    public static function update(int $id, array $data): bool
    {
        $allowed    = ['name_en', 'name_es', 'active', 'sort_order'];
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
        $sql           = 'UPDATE flower_types SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

        $stmt = Database::rw()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Permanently delete a flower type by primary key.
     *
     * Associated product_flower_types and flower_type_colors rows are removed
     * automatically by the ON DELETE CASCADE foreign key constraints.
     *
     * @param int $id The flower type's primary key.
     *
     * @return bool True when a row was deleted, false when the ID was not found.
     *
     * @throws \PDOException When the DELETE fails.
     *
     * @example
     *   FlowerType::delete(2);
     */
    public static function delete(int $id): bool
    {
        $stmt = Database::rw()->prepare('DELETE FROM flower_types WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
