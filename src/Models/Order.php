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
     *        occasion, arrangement_style, color_preferences, budget_range,
     *        notes, addons (JSON-encoded string or null).
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
                 occasion, arrangement_style, color_preferences, budget_range, notes, addons)
             VALUES
                (:customer_id, :event_date, :delivery_type, :delivery_address, :delivery_fee,
                 :occasion, :arrangement_style, :color_preferences, :budget_range, :notes, :addons)'
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
            ':addons'            => $data['addons']            ?? null,
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

    /**
     * Creates a new shop-cart checkout order and returns its auto-increment ID.
     *
     * Populates both the existing quote-request columns and the new Phase-4
     * columns added by migration 009_shop_orders.sql. The payment_status is
     * always set to 'unpaid' at creation; call {@see markPaid()} after Stripe
     * confirms payment.
     *
     * @param array<string, mixed> $data Recognised keys:
     *        customer_id (int), delivery_address (string|null),
     *        delivery_fee (float|null), items_json (string — JSON),
     *        card_message (string|null), delivery_venue_name (string|null),
     *        occasion_type (string|null), subtotal (float),
     *        tax_amount (float), total (float),
     *        delivery_type ('delivery'|'pickup', default 'delivery'),
     *        fulfill_at (string|null — requested 'Y-m-d H:i:s' delivery/pickup time).
     *
     * @return int The newly created order ID.
     *
     * @example
     *   $orderId = Order::createShopOrder([
     *       'customer_id'        => 12,
     *       'delivery_type'      => 'delivery',
     *       'delivery_address'   => '123 Main St, Tulsa, OK',
     *       'delivery_fee'       => 10.00,
     *       'fulfill_at'         => '2026-06-20 14:00:00',
     *       'items_json'         => json_encode($snapshot),
     *       'card_message'       => 'Happy Birthday!',
     *       'delivery_venue_name'=> null,
     *       'occasion_type'      => 'Birthday',
     *       'subtotal'           => 45.00,
     *       'tax_amount'         => 3.83,
     *       'total'              => 58.83,
     *   ]);
     */
    public static function createShopOrder(array $data): int
    {
        $deliveryType = ($data['delivery_type'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';

        $stmt = Database::rw()->prepare(
            'INSERT INTO orders
                (customer_id, delivery_type, delivery_address, delivery_fee, fulfill_at,
                 occasion, items_json, card_message, delivery_venue_name,
                 occasion_type, subtotal, tax_amount, total, payment_status)
             VALUES
                (:customer_id, :delivery_type, :delivery_address, :delivery_fee, :fulfill_at,
                 :occasion, :items_json, :card_message, :delivery_venue_name,
                 :occasion_type, :subtotal, :tax_amount, :total, :payment_status)'
        );

        $stmt->execute([
            ':customer_id'         => $data['customer_id']         ?? null,
            ':delivery_type'       => $deliveryType,
            ':delivery_address'    => $data['delivery_address']    ?? null,
            ':delivery_fee'        => $data['delivery_fee']        ?? null,
            ':fulfill_at'          => $data['fulfill_at']          ?? null,
            ':occasion'            => $data['occasion_type']       ?? null,
            ':items_json'          => $data['items_json']          ?? null,
            ':card_message'        => $data['card_message']        ?? null,
            ':delivery_venue_name' => $data['delivery_venue_name'] ?? null,
            ':occasion_type'       => $data['occasion_type']       ?? null,
            ':subtotal'            => $data['subtotal']            ?? 0.00,
            ':tax_amount'          => $data['tax_amount']          ?? 0.00,
            ':total'               => $data['total']               ?? 0.00,
            ':payment_status'      => 'unpaid',
        ]);

        return (int) Database::rw()->lastInsertId();
    }

    /**
     * Stores the Stripe Checkout Session ID on a shop order.
     *
     * Called immediately after the session is created so the webhook handler
     * can correlate incoming events with the order row.
     *
     * @param int    $id        The order ID.
     * @param string $sessionId The Stripe Checkout Session ID (cs_…).
     *
     * @return void
     *
     * @example
     *   Order::setStripeCheckoutSession($orderId, $session['id']);
     */
    public static function setStripeCheckoutSession(int $id, string $sessionId): void
    {
        $stmt = Database::rw()->prepare(
            'UPDATE orders SET stripe_checkout_session_id = ? WHERE id = ?'
        );
        $stmt->execute([$sessionId, $id]);
    }

    /**
     * Looks up a shop order by its Stripe Checkout Session ID.
     *
     * Used by the webhook handler and the success-redirect handler to map a
     * Stripe session back to an order row without trusting URL parameters.
     *
     * @param string $sessionId The Stripe Checkout Session ID (cs_…).
     *
     * @return array<string, mixed>|null The order row, or null when not found.
     *
     * @example
     *   $order = Order::findByStripeSessionId('cs_test_abc123');
     */
    public static function findByStripeSessionId(string $sessionId): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT o.*,
                    c.name  AS customer_name,
                    c.email AS customer_email,
                    c.phone AS customer_phone
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.stripe_checkout_session_id = ?
             LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Marks a shop order as paid and records the Stripe Payment Intent ID.
     *
     * Idempotent: if the order is already marked paid this call is a no-op
     * (the WHERE clause filters it out so no duplicate update occurs).
     *
     * @param int    $id              The order ID.
     * @param string $paymentIntentId The Stripe Payment Intent ID (pi_…).
     *
     * @return void
     *
     * @example
     *   Order::markPaid($orderId, 'pi_3abc123');
     */
    public static function markPaid(int $id, string $paymentIntentId): void
    {
        $stmt = Database::rw()->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 stripe_payment_intent_id = ?
             WHERE id = ?
               AND payment_status != 'paid'"
        );
        $stmt->execute([$paymentIntentId, $id]);
    }
}
