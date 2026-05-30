<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Represents a custom bouquet order request submitted through the website form.
 *
 * Each order is linked to a customer record (created or upserted at submission
 * time) and may later be linked to a quote once the admin responds.  All reads
 * use the read-only PDO connection; all writes use the read-write connection.
 *
 * @see \App\Models\Customer
 * @see \App\Core\Database
 */
final class Order
{
    /**
     * Creates a new order record and returns its auto-increment ID.
     *
     * @param array<string, mixed> $data Recognised keys: customer_id,
     *        event_date, delivery_type, delivery_address, delivery_fee,
     *        occasion, arrangement_style, color_preferences, budget_range, notes.
     *
     * @return int The newly created order ID.
     *
     * @example
     *   $orderId = Order::create([
     *       'customer_id'       => 12,
     *       'event_date'        => '2026-06-14',
     *       'delivery_type'     => 'pickup',
     *       'delivery_address'  => null,
     *       'delivery_fee'      => null,
     *       'occasion'          => 'Birthday',
     *       'arrangement_style' => 'Romantic',
     *       'color_preferences' => 'Pink and white',
     *       'budget_range'      => '$100–$200',
     *       'notes'             => 'Add a ribbon.',
     *   ]);
     */
    public static function create(array $data): int
    {
        $stmt = Database::rw()->prepare(
            'INSERT INTO orders
                (customer_id, event_date, delivery_type, delivery_address, delivery_fee,
                 occasion, arrangement_style, color_preferences, budget_range, notes)
             VALUES
                (:customer_id, :event_date, :delivery_type, :delivery_address, :delivery_fee,
                 :occasion, :arrangement_style, :color_preferences, :budget_range, :notes)'
        );

        $stmt->execute([
            ':customer_id'       => $data['customer_id']       ?? null,
            ':event_date'        => $data['event_date']        ?? null,
            ':delivery_type'     => $data['delivery_type']     ?? 'pickup',
            ':delivery_address'  => $data['delivery_address']  ?? null,
            ':delivery_fee'      => $data['delivery_fee']      ?? null,
            ':occasion'          => $data['occasion']          ?? null,
            ':arrangement_style' => $data['arrangement_style'] ?? null,
            ':color_preferences' => $data['color_preferences'] ?? null,
            ':budget_range'      => $data['budget_range']      ?? null,
            ':notes'             => $data['notes']             ?? null,
        ]);

        return (int) Database::rw()->lastInsertId();
    }

    /**
     * Returns all orders with the linked customer's name, newest first.
     *
     * Performs a LEFT JOIN on the customers table so orders without a linked
     * customer are still returned (customer_name will be null in that case).
     *
     * @return array<int, array<string, mixed>> All order rows.
     *
     * @example
     *   $orders = Order::all();
     */
    public static function all(): array
    {
        $stmt = Database::ro()->query(
            'SELECT o.*, c.name AS customer_name
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             ORDER BY o.created_at DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Returns a single order with the linked customer's information.
     *
     * @param int $id The order ID.
     *
     * @return array<string, mixed>|null The order row (with customer columns
     *         prefixed by customer_), or null when not found.
     *
     * @example
     *   $order = Order::find(7);
     *   echo $order['customer_name'];
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT o.*,
                    c.name          AS customer_name,
                    c.email         AS customer_email,
                    c.phone         AS customer_phone
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Updates the status of an order.
     *
     * @param int    $id     The order ID.
     * @param string $status One of: pending, in_progress, ready, delivered,
     *                       completed, cancelled.
     *
     * @return void
     *
     * @example
     *   Order::updateStatus(7, 'in_progress');
     */
    public static function updateStatus(int $id, string $status): void
    {
        $stmt = Database::rw()->prepare(
            'UPDATE orders SET status = ? WHERE id = ?'
        );
        $stmt->execute([$status, $id]);
    }
}
