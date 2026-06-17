<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `product_flower_type_colors` join table.
 *
 * Records the colors shown in a product's photo per flower type, so an
 * arrangement can default to multiple colors of the same flower type (e.g.
 * red + white roses). Read operations use the read-only PDO connection; writes
 * use the read-write connection. All queries use prepared statements.
 *
 * Expected schema: see migrations/011_product_flower_type_colors.sql.
 *
 * @see \App\Models\FlowerTypeColor          Which colors each flower type comes in.
 * @see \App\Models\ProductFlowerType        Which flower types a product contains.
 * @see \App\Support\FlowerColorResolver     Consumes mapForProduct() as the pictured defaults.
 */
final class ProductFlowerTypeColor
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Return the pictured colors for a product, grouped by flower type.
     *
     * @param int $productId The product's primary key.
     *
     * @return array<int, int[]> Map of flower_type_id => list of flower_color_id.
     *                           Flower types with no pictured colors are absent.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   ProductFlowerTypeColor::mapForProduct(42); // [3 => [1, 2], 5 => [4]]
     */
    public static function mapForProduct(int $productId): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT flower_type_id, flower_color_id
             FROM product_flower_type_colors
             WHERE product_id = ?
             ORDER BY flower_type_id ASC, flower_color_id ASC'
        );
        $stmt->execute([$productId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['flower_type_id']][] = (int) $row['flower_color_id'];
        }

        return $map;
    }

    /**
     * Replace the pictured colors for a product (delete-then-insert, transactional).
     *
     * Removes every existing (flower_type_id, flower_color_id) pair for the
     * product and inserts the supplied map. Duplicate pairs are ignored. The
     * whole operation runs in a single transaction so a failure leaves the
     * previous configuration intact.
     *
     * @param int               $productId The product's primary key.
     * @param array<int, int[]> $map       Map of flower_type_id => list of flower_color_id.
     *                                      Empty arrays / empty map clear all rows.
     *
     * @return void
     *
     * @throws \PDOException When the transaction fails (it is rolled back first).
     *
     * @example
     *   ProductFlowerTypeColor::setForProduct(42, [3 => [1, 2], 5 => [4]]);
     */
    public static function setForProduct(int $productId, array $map): void
    {
        $db = Database::rw();
        $db->beginTransaction();

        try {
            $del = $db->prepare('DELETE FROM product_flower_type_colors WHERE product_id = ?');
            $del->execute([$productId]);

            $ins = $db->prepare(
                'INSERT IGNORE INTO product_flower_type_colors (product_id, flower_type_id, flower_color_id)
                 VALUES (:product_id, :flower_type_id, :flower_color_id)'
            );

            foreach ($map as $flowerTypeId => $colorIds) {
                foreach ((array) $colorIds as $colorId) {
                    $ins->execute([
                        ':product_id'      => $productId,
                        ':flower_type_id'  => (int) $flowerTypeId,
                        ':flower_color_id' => (int) $colorId,
                    ]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
