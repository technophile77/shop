<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `paper_colors` table.
 *
 * Paper colors (e.g. Kraft, White, Pink) represent the wrap/paper color options
 * available for bouquet packaging. Each color may carry an optional hex value
 * for display as a swatch in the admin UI and the customer customizer.
 *
 * Read methods use the read-only PDO connection; write methods use the
 * read-write connection. All queries use prepared statements.
 *
 * @see \App\Models\Product   Products reference paper_colors via pictured_paper_color_id.
 * @see \App\Core\Database    Supplies the PDO connections.
 */
final class PaperColor
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Read methods (use Database::ro())
    // -------------------------------------------------------------------------

    /**
     * Return all paper colors ordered by sort_order ASC, then id ASC.
     *
     * Includes both active and inactive rows.
     *
     * @return array<int, array> Each row is an assoc array of paper_colors columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $colors = PaperColor::all();
     */
    public static function all(): array
    {
        $stmt = Database::ro()->query(
            'SELECT * FROM paper_colors ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Return active paper colors (active = 1) ordered by sort_order ASC, then id ASC.
     *
     * Suitable for customer-facing selectors and product forms where only
     * currently offered wrap colors should appear.
     *
     * @return array<int, array> Each row is an assoc array of paper_colors columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $colors = PaperColor::allActive();
     */
    public static function allActive(): array
    {
        $stmt = Database::ro()->query(
            'SELECT * FROM paper_colors WHERE active = 1 ORDER BY sort_order ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Find a single paper color by its primary key.
     *
     * Returns null when no record with that ID exists.
     *
     * @param int $id The paper color's primary key.
     *
     * @return array|null The paper_colors row, or null when not found.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $color = PaperColor::find(1); // ['id' => 1, 'name_en' => 'Kraft', 'hex' => '#C8A06A', ...]
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM paper_colors WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    // -------------------------------------------------------------------------
    // Write methods (use Database::rw())
    // -------------------------------------------------------------------------

    /**
     * Insert a new paper color and return the new auto-increment ID.
     *
     * The hex value is optional. When provided it should match /^#[0-9A-Fa-f]{6}$/.
     *
     * @param array $data Fields to insert; recognised keys: name_en (required),
     *                    name_es, hex, active, sort_order.
     *
     * @return int The new paper color's primary key.
     *
     * @throws \PDOException When the INSERT fails.
     *
     * @example
     *   $id = PaperColor::create(['name_en' => 'Navy', 'name_es' => 'Azul Marino', 'hex' => '#1A237E']);
     */
    public static function create(array $data): int
    {
        $db   = Database::rw();
        $stmt = $db->prepare(
            'INSERT INTO paper_colors (name_en, name_es, hex, active, sort_order)
             VALUES (:name_en, :name_es, :hex, :active, :sort_order)'
        );

        $hexRaw = trim((string) ($data['hex'] ?? ''));

        $stmt->execute([
            ':name_en'    => $data['name_en']    ?? '',
            ':name_es'    => isset($data['name_es']) && $data['name_es'] !== '' ? $data['name_es'] : null,
            ':hex'        => $hexRaw !== '' ? $hexRaw : null,
            ':active'     => (int) ($data['active']     ?? 1),
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Update an existing paper color by primary key.
     *
     * Only columns present in $data are updated. Returns true when at least one
     * row was matched.
     *
     * @param int   $id   The paper color's primary key.
     * @param array $data Map of column => value to update. Recognised keys:
     *                    name_en, name_es, hex, active, sort_order.
     *
     * @return bool True when the UPDATE matched a row, false when the ID was not found.
     *
     * @throws \PDOException When the UPDATE fails.
     *
     * @example
     *   PaperColor::update(1, ['hex' => '#BF9B6E', 'active' => 1]);
     */
    public static function update(int $id, array $data): bool
    {
        $allowed    = ['name_en', 'name_es', 'hex', 'active', 'sort_order'];
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
        $sql           = 'UPDATE paper_colors SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

        $stmt = Database::rw()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Permanently delete a paper color by primary key.
     *
     * Products whose pictured_paper_color_id references this color will be set
     * to NULL automatically by the ON DELETE SET NULL constraint on the products
     * table.
     *
     * @param int $id The paper color's primary key.
     *
     * @return bool True when a row was deleted, false when the ID was not found.
     *
     * @throws \PDOException When the DELETE fails.
     *
     * @example
     *   PaperColor::delete(2);
     */
    public static function delete(int $id): bool
    {
        $stmt = Database::rw()->prepare('DELETE FROM paper_colors WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
