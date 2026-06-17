<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `addons` table.
 *
 * Add-ons are admin-managed global extras (e.g. ribbon, vase, greeting card)
 * that appear as image+checkbox pairs on the public "Request a Custom Bouquet"
 * form.  Read operations use the read-only PDO connection; writes use the
 * read-write connection.  All queries use prepared statements.
 *
 * Expected schema: see migrations/005_add_addons.sql and
 * migrations/008_addon_pricing.sql (adds price and has_custom_text).
 *
 * @see \App\Core\Database
 */
final class Addon
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Return all active add-ons ordered for display on the public order form.
     *
     * @return array<int, array<string, mixed>>
     *
     * @example
     *   $addons = Addon::allActive();
     *   // [['id'=>1,'name_en'=>'Ribbon','name_es'=>'Lazo','image_path'=>'…'], …]
     */
    public static function allActive(): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM addons WHERE active = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Return all add-ons (active and inactive) for the admin list view.
     *
     * Active add-ons sort first; within each group, ordered by sort_order ASC
     * then id DESC so newest appear at the top.
     *
     * @return array<int, array<string, mixed>>
     *
     * @example
     *   $addons = Addon::all();
     */
    public static function all(): array
    {
        $stmt = Database::ro()->query(
            'SELECT * FROM addons ORDER BY active DESC, sort_order ASC, id DESC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Find a single add-on by primary key, regardless of active status.
     *
     * @param int $id Primary key.
     * @return array<string, mixed>|null The row, or null when not found.
     *
     * @example
     *   $addon = Addon::find(3);
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM addons WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Insert a new add-on and return its auto-increment ID.
     *
     * @param array<string, mixed> $data Recognised keys: name_en (required),
     *        name_es, image_path, active, sort_order, price, has_custom_text.
     * @return int Newly created primary key.
     *
     * @example
     *   $id = Addon::create(['name_en' => 'Ribbon', 'name_es' => 'Lazo', 'price' => 5.00, 'has_custom_text' => 1]);
     */
    public static function create(array $data): int
    {
        $db   = Database::rw();
        $stmt = $db->prepare(
            'INSERT INTO addons (name_en, name_es, image_path, active, sort_order, price, has_custom_text, has_quantity)
             VALUES (:name_en, :name_es, :image_path, :active, :sort_order, :price, :has_custom_text, :has_quantity)'
        );
        $stmt->execute([
            ':name_en'         => $data['name_en']         ?? '',
            ':name_es'         => $data['name_es']         ?? null,
            ':image_path'      => $data['image_path']      ?? null,
            ':active'          => (int) ($data['active']          ?? 1),
            ':sort_order'      => (int) ($data['sort_order']      ?? 0),
            ':price'           => (float) ($data['price']         ?? 0),
            ':has_custom_text' => (int) ($data['has_custom_text'] ?? 0),
            ':has_quantity'    => (int) ($data['has_quantity']    ?? 0),
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Update an existing add-on by primary key.
     *
     * Only columns present in $data are updated.  Recognised keys: name_en,
     * name_es, image_path, active, sort_order, price, has_custom_text.
     *
     * @param int                  $id   Primary key.
     * @param array<string, mixed> $data Column => value pairs to update.
     * @return bool True when at least one row was matched.
     *
     * @example
     *   Addon::update(3, ['sort_order' => 2, 'active' => 0, 'price' => 5.00, 'has_custom_text' => 1]);
     */
    public static function update(int $id, array $data): bool
    {
        $allowed    = ['name_en', 'name_es', 'image_path', 'active', 'sort_order', 'price', 'has_custom_text', 'has_quantity'];
        $setClauses = [];
        $params     = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $setClauses[]      = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }

        if ($setClauses === []) {
            return false;
        }

        $params[':id'] = $id;
        $stmt = Database::rw()->prepare(
            'UPDATE addons SET ' . implode(', ', $setClauses) . ' WHERE id = :id'
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Soft-delete an add-on by setting its active flag to 0.
     *
     * The record is retained so orders that reference it by snapshot still
     * have a consistent history.
     *
     * @param int $id Primary key.
     * @return bool True when a row was matched.
     *
     * @example
     *   Addon::delete(3);
     */
    public static function delete(int $id): bool
    {
        $stmt = Database::rw()->prepare(
            'UPDATE addons SET active = 0 WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
