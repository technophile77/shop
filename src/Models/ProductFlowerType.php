<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `product_flower_types` join table.
 *
 * Links products to the flower types they are made with in a many-to-many
 * relationship. Provides replace-semantics (delete-then-insert in a transaction)
 * for updating the flower type set for a product atomically.
 *
 * Read methods use the read-only PDO connection; write methods use the
 * read-write connection. All queries use prepared statements.
 *
 * @see \App\Models\FlowerType  Flower type lookup table model.
 * @see \App\Models\Product     Product model.
 * @see \App\Core\Database      Supplies the PDO connections.
 */
final class ProductFlowerType
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Read methods (use Database::ro())
    // -------------------------------------------------------------------------

    /**
     * Return an array of flower type IDs (int[]) for a given product.
     *
     * Efficient when only the IDs are needed (e.g. to pre-check checkboxes in
     * the product edit form or to pass to FlowerColorResolver).
     *
     * @param int $productId The product's primary key.
     *
     * @return int[] Flower type IDs associated with the product.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $typeIds = ProductFlowerType::flowerTypeIdsForProduct(42); // [1, 2]
     */
    public static function flowerTypeIdsForProduct(int $productId): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT flower_type_id FROM product_flower_types WHERE product_id = ?'
        );
        $stmt->execute([$productId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Return full flower_types rows for all flower types associated with a product.
     *
     * Joins the flower_types table so the full type record is returned, ordered
     * by sort_order ASC then id ASC.
     *
     * @param int $productId The product's primary key.
     *
     * @return array<int, array> Each row is an assoc array of flower_types columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $types = ProductFlowerType::byProduct(42);
     *   // [['id' => 1, 'name_en' => 'Roses', 'name_es' => 'Rosas', ...], ...]
     */
    public static function byProduct(int $productId): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT ft.*
             FROM flower_types ft
             JOIN product_flower_types pft ON pft.flower_type_id = ft.id
             WHERE pft.product_id = ?
             ORDER BY ft.sort_order ASC, ft.id ASC'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Write methods (use Database::rw())
    // -------------------------------------------------------------------------

    /**
     * Replace the full set of flower types for a product atomically.
     *
     * Deletes all existing product_flower_types rows for the product, then
     * inserts the new set — all inside a single transaction. Passing an empty
     * array clears all flower type associations without inserting anything.
     *
     * @param int   $productId      The product's primary key.
     * @param int[] $flowerTypeIds  Flower type IDs to associate with the product.
     *
     * @return void
     *
     * @throws \PDOException When any database operation fails; the transaction is
     *                       rolled back automatically on exception.
     *
     * @example
     *   ProductFlowerType::setForProduct(42, [1, 3]); // Roses + Tulips
     *   ProductFlowerType::setForProduct(42, []);      // clears all flower types
     */
    public static function setForProduct(int $productId, array $flowerTypeIds): void
    {
        $db = Database::rw();
        $db->beginTransaction();

        try {
            $db->prepare('DELETE FROM product_flower_types WHERE product_id = ?')
               ->execute([$productId]);

            if ($flowerTypeIds !== []) {
                $insert = $db->prepare(
                    'INSERT INTO product_flower_types (product_id, flower_type_id) VALUES (?, ?)'
                );
                foreach ($flowerTypeIds as $typeId) {
                    $insert->execute([$productId, (int) $typeId]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
