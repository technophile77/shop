<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `product_occasions` join table.
 *
 * Links products to occasions with a many-to-many relationship. Provides
 * replace-semantics (delete-then-insert inside a transaction) for updating the
 * occasion set for a product in one atomic operation.
 *
 * Read methods use the read-only PDO connection; write methods use the
 * read-write connection. All queries use prepared statements.
 *
 * @see \App\Models\Occasion  Occasion lookup table model.
 * @see \App\Models\Product   Product model.
 * @see \App\Core\Database    Supplies the PDO connections.
 */
final class ProductOccasion
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Read methods (use Database::ro())
    // -------------------------------------------------------------------------

    /**
     * Return all occasion rows associated with a product, ordered by sort_order ASC.
     *
     * Joins the occasions table so the full occasion record is returned, not
     * just the join-table foreign keys.
     *
     * @param int $productId The product's primary key.
     *
     * @return array<int, array> Each row is an assoc array of all occasion columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $occasions = ProductOccasion::byProduct(42);
     */
    public static function byProduct(int $productId): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT o.*
             FROM occasions o
             JOIN product_occasions po ON po.occasion_id = o.id
             WHERE po.product_id = ?
             ORDER BY o.sort_order ASC, o.id ASC'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll();
    }

    /**
     * Return an array of occasion IDs (int[]) for a given product.
     *
     * More efficient than {@see byProduct()} when only the IDs are needed
     * (e.g. to pre-check checkboxes in a form).
     *
     * @param int $productId The product's primary key.
     *
     * @return int[] Occasion IDs associated with the product.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $ids = ProductOccasion::occasionIdsForProduct(42); // [1, 3]
     */
    public static function occasionIdsForProduct(int $productId): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT occasion_id FROM product_occasions WHERE product_id = ?'
        );
        $stmt->execute([$productId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // -------------------------------------------------------------------------
    // Write methods (use Database::rw())
    // -------------------------------------------------------------------------

    /**
     * Replace the full set of occasions for a product atomically.
     *
     * Deletes all existing product_occasions rows for the product, then inserts
     * the new set — all inside a single transaction. Passing an empty array
     * clears all occasion associations without inserting anything new.
     *
     * @param int   $productId   The product's primary key.
     * @param int[] $occasionIds Ordered list of occasion IDs to associate.
     *
     * @return void
     *
     * @throws \PDOException When any database operation fails; the transaction is
     *                       rolled back automatically on exception.
     *
     * @example
     *   ProductOccasion::setForProduct(42, [1, 3]); // birthday + sympathy
     *   ProductOccasion::setForProduct(42, []);      // clears all occasions
     */
    public static function setForProduct(int $productId, array $occasionIds): void
    {
        $db = Database::rw();
        $db->beginTransaction();

        try {
            $db->prepare('DELETE FROM product_occasions WHERE product_id = ?')
               ->execute([$productId]);

            if ($occasionIds !== []) {
                $insert = $db->prepare(
                    'INSERT INTO product_occasions (product_id, occasion_id) VALUES (?, ?)'
                );
                foreach ($occasionIds as $occasionId) {
                    $insert->execute([$productId, (int) $occasionId]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
