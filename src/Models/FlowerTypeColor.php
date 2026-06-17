<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `flower_type_colors` matrix table.
 *
 * Tracks which flower colors are available for each flower type. For example,
 * Roses may come in Red, Pink, White, Yellow, and Peach, while Lilies only
 * come in White, Pink, and Orange.
 *
 * Provides replace-semantics (delete-then-insert in a transaction) and a bulk
 * map() method to retrieve all type-to-color associations in a single query,
 * avoiding N+1 queries when rendering admin lists.
 *
 * Read methods use the read-only PDO connection; write methods use the
 * read-write connection. All queries use prepared statements.
 *
 * @see \App\Models\FlowerType   Flower type lookup table.
 * @see \App\Models\FlowerColor  Flower color lookup table.
 * @see \App\Core\Database       Supplies the PDO connections.
 */
final class FlowerTypeColor
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Read methods (use Database::ro())
    // -------------------------------------------------------------------------

    /**
     * Return an array of color IDs (int[]) available for a given flower type.
     *
     * Efficient when only the IDs are needed (e.g. to pre-check checkboxes).
     *
     * @param int $flowerTypeId The flower type's primary key.
     *
     * @return int[] Color IDs associated with the flower type.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $colorIds = FlowerTypeColor::colorIdsByType(1); // [1, 2, 3, 4, 8]
     */
    public static function colorIdsByType(int $flowerTypeId): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT flower_color_id FROM flower_type_colors WHERE flower_type_id = ?'
        );
        $stmt->execute([$flowerTypeId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Return full flower_colors rows for all colors available for a flower type.
     *
     * Joins flower_colors so the full color record is returned, ordered by
     * sort_order ASC then id ASC.
     *
     * @param int $flowerTypeId The flower type's primary key.
     *
     * @return array<int, array> Each row is an assoc array of flower_colors columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $colors = FlowerTypeColor::colorsByType(1);
     *   // [['id' => 1, 'name_en' => 'Red', 'hex' => '#D32F2F', ...], ...]
     */
    public static function colorsByType(int $flowerTypeId): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT fc.*
             FROM flower_colors fc
             JOIN flower_type_colors ftc ON ftc.flower_color_id = fc.id
             WHERE ftc.flower_type_id = ?
             ORDER BY fc.sort_order ASC, fc.id ASC'
        );
        $stmt->execute([$flowerTypeId]);

        return $stmt->fetchAll();
    }

    /**
     * Return a map of flower_type_id => int[] color_ids for all flower types.
     *
     * Fetches all rows from flower_type_colors in a single query and groups
     * them by flower_type_id. Used by admin list pages and the FlowerColorResolver
     * to avoid N+1 queries when rendering per-type color matrices.
     *
     * @return array<int, int[]> Map of flower_type_id => array of flower_color_ids.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $map = FlowerTypeColor::map();
     *   // [1 => [1, 2, 3, 4, 8], 2 => [1, 3, 6], ...]
     */
    public static function map(): array
    {
        $stmt = Database::ro()->query(
            'SELECT flower_type_id, flower_color_id FROM flower_type_colors'
        );

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $typeId               = (int) $row['flower_type_id'];
            $colorId              = (int) $row['flower_color_id'];
            $map[$typeId][]       = $colorId;
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Write methods (use Database::rw())
    // -------------------------------------------------------------------------

    /**
     * Replace the full set of colors for a flower type atomically.
     *
     * Deletes all existing flower_type_colors rows for the type, then inserts
     * the new set — all inside a single transaction. Passing an empty array
     * clears all color associations without inserting anything.
     *
     * @param int   $flowerTypeId The flower type's primary key.
     * @param int[] $colorIds     Color IDs to associate with the flower type.
     *
     * @return void
     *
     * @throws \PDOException When any database operation fails; the transaction is
     *                       rolled back automatically on exception.
     *
     * @example
     *   FlowerTypeColor::setColorsForType(1, [1, 2, 3, 4, 8]); // Roses: Red, Pink, White, Yellow, Peach
     *   FlowerTypeColor::setColorsForType(1, []);                // clears all colors for Roses
     */
    public static function setColorsForType(int $flowerTypeId, array $colorIds): void
    {
        $db = Database::rw();
        $db->beginTransaction();

        try {
            $db->prepare('DELETE FROM flower_type_colors WHERE flower_type_id = ?')
               ->execute([$flowerTypeId]);

            if ($colorIds !== []) {
                $insert = $db->prepare(
                    'INSERT INTO flower_type_colors (flower_type_id, flower_color_id) VALUES (?, ?)'
                );
                foreach ($colorIds as $colorId) {
                    $insert->execute([$flowerTypeId, (int) $colorId]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
