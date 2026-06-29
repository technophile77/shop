<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Config;
use App\Services\QuoteService;

/**
 * Represents a custom-quote record in the `quotes` table.
 *
 * Quotes move through a defined status lifecycle: draft → sent → accepted →
 * deposit_confirmed → completed, with a `cancelled` escape hatch from most
 * states. Each status transition writes a matching timestamp column so the
 * admin can audit the full history.
 *
 * All reads use {@see Database::ro()}; all writes use {@see Database::rw()}.
 *
 * @see \App\Core\Config
 *
 * @see \App\Models\Customer::refreshLifetimeSpend()
 */
final class Quote
{
    /**
     * Ordered list of valid quote statuses.
     *
     * Only the transitions defined in {@see transition()} are allowed;
     * skipping steps raises a RuntimeException.
     */
    public const STATUSES = [
        'draft',
        'sent',
        'accepted',
        'deposit_confirmed',
        'completed',
        'cancelled',
    ];

    /**
     * Statuses whose content (items, customer, deposit, notes, validity) may
     * still be edited.
     *
     * Once a quote reaches `deposit_confirmed` or `completed`, money and the
     * customer's lifetime spend are derived from the stored numbers, so editing
     * would desync financial records; `cancelled` is terminal. Those are
     * therefore excluded.
     */
    public const EDITABLE_STATUSES = ['draft', 'sent', 'accepted'];

    /**
     * Whether a quote in the given status may be edited.
     *
     * Pure helper (no DB) used by both the controller guard and the admin view
     * to decide whether to expose the Edit action.
     *
     * @param string $status A quote status; need not be a valid status.
     *
     * @return bool True only for statuses in {@see self::EDITABLE_STATUSES}.
     *
     * @example
     *   Quote::isEditable('sent');       // true
     *   Quote::isEditable('completed');  // false
     */
    public static function isEditable(string $status): bool
    {
        return in_array($status, self::EDITABLE_STATUSES, true);
    }

    /**
     * Allowed forward transitions: status → next allowed status.
     *
     * 'cancelled' is handled separately (any non-completed quote may cancel).
     *
     * @var array<string, string>
     */
    private const TRANSITIONS = [
        'draft'             => 'sent',
        'sent'              => 'accepted',
        'accepted'          => 'deposit_confirmed',
        'deposit_confirmed' => 'completed',
    ];

    /**
     * Status → timestamp column written on transition.
     *
     * Columns not listed here get no automatic timestamp update.
     *
     * @var array<string, string>
     */
    private const STATUS_TIMESTAMPS = [
        'sent'              => 'sent_at',
        'accepted'          => 'accepted_at',
        'deposit_confirmed' => 'deposit_confirmed_at',
    ];

    /**
     * Creates a new quote and returns its auto-increment ID.
     *
     * Generates a 64-character hex token for the public share URL. Computes
     * `subtotal` from the item list and derives `deposit_amount` via
     * {@see QuoteService::calculateDeposit()} (items flagged `full_deposit` are
     * charged in full, the rest at `deposit_pct`). Stores items as a JSON blob.
     * Sets `valid_until` to today + `valid_days` days.
     *
     * @param array<string, mixed> $data Recognised keys:
     *        - customer_id (int|null): linked customer; may be null for drafts.
     *        - event_date (string|null): ISO date of the event, e.g. '2026-09-01'.
     *        - items (array): each element has keys description, qty, unit_price,
     *          and an optional bool full_deposit (true ⇒ this line is due in full).
     *        - deposit_pct (int): percentage of subtotal required as deposit; default 50.
     *        - tax_rate (float): sales tax rate applied; read from BUSINESS_SALES_TAX_RATE config.
     *        - tax_amount (float): computed tax on the subtotal; stored for display.
     *        - notes (string|null): freeform note shown on the quote.
     *        - valid_days (int): days until the quote expires; default 14.
     *
     * @return int The newly created quote ID.
     *
     * @example
     *   $id = Quote::create([
     *       'customer_id' => 7,
     *       'event_date'  => '2026-09-01',
     *       'items'       => [
     *           ['description' => 'Bouquet de rosas', 'qty' => 2, 'unit_price' => 75.00],
     *       ],
     *       'deposit_pct' => 50,
     *       'notes'       => 'Pink and white only.',
     *       'valid_days'  => 14,
     *   ]);
     */
    public static function create(array $data): int
    {
        $token      = bin2hex(random_bytes(32));
        $depositPct = (int) ($data['deposit_pct'] ?? 50);
        $validDays  = (int) ($data['valid_days']  ?? 14);
        $items      = $data['items'] ?? [];

        $subtotal = array_reduce(
            $items,
            static fn (float $carry, array $item): float =>
                $carry + ((float) $item['unit_price'] * (int) $item['qty']),
            0.0
        );

        $depositAmount = QuoteService::calculateDeposit($items, $depositPct);
        $validUntil    = date('Y-m-d', strtotime("+{$validDays} days"));

        $taxRate   = (float) Config::get('BUSINESS_SALES_TAX_RATE', 0.0);
        $taxAmount = round($subtotal * $taxRate, 2);

        $stmt = Database::rw()->prepare(
            'INSERT INTO quotes
                (token, customer_id, event_date, items_json, subtotal,
                 deposit_pct, tax_rate, tax_amount, deposit_amount,
                 notes, valid_until, status)
             VALUES
                (:token, :customer_id, :event_date, :items_json, :subtotal,
                 :deposit_pct, :tax_rate, :tax_amount, :deposit_amount,
                 :notes, :valid_until, :status)'
        );

        $stmt->execute([
            ':token'          => $token,
            ':customer_id'    => $data['customer_id'] ?? null,
            ':event_date'     => $data['event_date']  ?? null,
            ':items_json'     => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':subtotal'       => $subtotal,
            ':deposit_pct'    => $depositPct,
            ':tax_rate'       => $taxRate,
            ':tax_amount'     => $taxAmount,
            ':deposit_amount' => $depositAmount,
            ':notes'          => $data['notes'] ?? null,
            ':valid_until'    => $validUntil,
            ':status'         => 'draft',
        ]);

        return (int) Database::rw()->lastInsertId();
    }

    /**
     * Updates an existing quote's editable content and recomputes its totals.
     *
     * Recalculates `subtotal`, `deposit_amount`, and `tax_amount` from the
     * supplied items and `deposit_pct` exactly as {@see create()} does, and
     * refreshes `valid_until` to today + `valid_days` (so an edit re-arms the
     * quote's validity window). Does **not** change `status`, `token`, or any
     * lifecycle timestamp — callers should restrict this to editable statuses
     * via {@see isEditable()}.
     *
     * @param int                  $id   The quote ID to update.
     * @param array<string, mixed> $data Recognised keys match {@see create()}:
     *        customer_id (int|null), event_date (string|null), items (array),
     *        deposit_pct (int), notes (string|null), valid_days (int).
     *
     * @return void
     *
     * @example
     *   Quote::update(12, [
     *       'customer_id' => 7,
     *       'event_date'  => '2026-09-01',
     *       'items'       => [['description' => 'Bouquet', 'qty' => 3, 'unit_price' => 60.0]],
     *       'deposit_pct' => 50,
     *       'notes'       => 'Updated colour scheme.',
     *       'valid_days'  => 14,
     *   ]);
     */
    public static function update(int $id, array $data): void
    {
        $depositPct = (int) ($data['deposit_pct'] ?? 50);
        $validDays  = (int) ($data['valid_days']  ?? 14);
        $items      = $data['items'] ?? [];

        $subtotal = array_reduce(
            $items,
            static fn (float $carry, array $item): float =>
                $carry + ((float) $item['unit_price'] * (int) $item['qty']),
            0.0
        );

        $depositAmount = QuoteService::calculateDeposit($items, $depositPct);
        $validUntil    = date('Y-m-d', strtotime("+{$validDays} days"));

        $taxRate   = (float) Config::get('BUSINESS_SALES_TAX_RATE', 0.0);
        $taxAmount = round($subtotal * $taxRate, 2);

        $stmt = Database::rw()->prepare(
            'UPDATE quotes SET
                customer_id    = :customer_id,
                event_date     = :event_date,
                items_json     = :items_json,
                subtotal       = :subtotal,
                deposit_pct    = :deposit_pct,
                tax_rate       = :tax_rate,
                tax_amount     = :tax_amount,
                deposit_amount = :deposit_amount,
                notes          = :notes,
                valid_until    = :valid_until
             WHERE id = :id'
        );

        $stmt->execute([
            ':customer_id'    => $data['customer_id'] ?? null,
            ':event_date'     => $data['event_date']  ?? null,
            ':items_json'     => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':subtotal'       => $subtotal,
            ':deposit_pct'    => $depositPct,
            ':tax_rate'       => $taxRate,
            ':tax_amount'     => $taxAmount,
            ':deposit_amount' => $depositAmount,
            ':notes'          => $data['notes'] ?? null,
            ':valid_until'    => $validUntil,
            ':id'             => $id,
        ]);
    }

    /**
     * Finds a quote by its public share token.
     *
     * Joins to the customers table so the caller has access to the linked
     * customer's name, email, and phone without a second query. Returns null
     * when no matching token exists or when the quote is cancelled.
     *
     * @param string $token The 64-character hex token from the share URL.
     *
     * @return array<string, mixed>|null The quote row (with customer columns),
     *         or null when not found or cancelled.
     *
     * @example
     *   $quote = Quote::findByToken($params['token']);
     *   if ($quote === null) { return Response::notFound(); }
     */
    public static function findByToken(string $token): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT q.*,
                    c.name  AS customer_name,
                    c.email AS customer_email,
                    c.phone AS customer_phone
             FROM quotes q
             LEFT JOIN customers c ON c.id = q.customer_id
             WHERE q.token = ?
               AND q.status != \'cancelled\'
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Returns all quotes with the linked customer's name, newest first.
     *
     * Quotes without a linked customer are still included (customer_name is null).
     *
     * @return array<int, array<string, mixed>> All quote rows.
     *
     * @example
     *   $quotes = Quote::all();
     *   foreach ($quotes as $q) { echo $q['customer_name']; }
     */
    public static function all(): array
    {
        $stmt = Database::ro()->query(
            'SELECT q.*,
                    c.name AS customer_name
             FROM quotes q
             LEFT JOIN customers c ON c.id = q.customer_id
             ORDER BY q.created_at DESC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Returns a single quote by primary key with the linked customer's name.
     *
     * @param int $id The quote ID.
     *
     * @return array<string, mixed>|null The quote row (with customer columns),
     *         or null when not found.
     *
     * @example
     *   $quote = Quote::find(12);
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT q.*,
                    c.name  AS customer_name,
                    c.email AS customer_email,
                    c.phone AS customer_phone
             FROM quotes q
             LEFT JOIN customers c ON c.id = q.customer_id
             WHERE q.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Transitions a quote to a new status and records the event timestamp.
     *
     * Allowed forward transitions:
     *   draft → sent
     *   sent → accepted
     *   accepted → deposit_confirmed
     *   deposit_confirmed → completed
     *
     * Any non-completed quote may also transition to `cancelled`.
     *
     * After a successful transition to `deposit_confirmed` or `completed`,
     * the linked customer's lifetime spend is recalculated via
     * {@see Customer::refreshLifetimeSpend()}.
     *
     * @param int    $id        The quote ID.
     * @param string $newStatus One of {@see self::STATUSES}.
     *
     * @return void
     *
     * @throws \RuntimeException When the transition is not allowed.
     *
     * @example
     *   Quote::transition(12, 'sent');
     *   Quote::transition(12, 'accepted');
     */
    public static function transition(int $id, string $newStatus): void
    {
        $quote = self::find($id);
        if ($quote === null) {
            throw new \RuntimeException("Quote #{$id} not found.");
        }

        $current = (string) $quote['status'];

        self::assertTransitionAllowed($current, $newStatus);

        // Build the SET clause — always update status; add a timestamp if mapped.
        $sets   = ['status = ?'];
        $params = [$newStatus];

        if (isset(self::STATUS_TIMESTAMPS[$newStatus])) {
            $col    = self::STATUS_TIMESTAMPS[$newStatus];
            $sets[] = "{$col} = NOW()";
        }

        $params[] = $id;
        $sql = 'UPDATE quotes SET ' . implode(', ', $sets) . ' WHERE id = ?';
        Database::rw()->prepare($sql)->execute($params);

        // Refresh customer lifetime spend after money-relevant transitions.
        if (
            in_array($newStatus, ['deposit_confirmed', 'completed'], true)
            && !empty($quote['customer_id'])
        ) {
            Customer::refreshLifetimeSpend((int) $quote['customer_id']);
        }
    }

    /**
     * Updates the `sent_at` timestamp and sets status to `sent`.
     *
     * Convenience wrapper used when the admin sends a quote link to a customer.
     * Equivalent to calling {@see transition()} with `'sent'`.
     *
     * @param int $id The quote ID.
     *
     * @return void
     *
     * @throws \RuntimeException When the transition from the current status is
     *         not allowed (i.e., the quote is not in `draft` status).
     *
     * @example
     *   Quote::markSent(12);
     */
    public static function markSent(int $id): void
    {
        self::transition($id, 'sent');
    }

    /**
     * Stores the Stripe Checkout Session ID on the quote when Pay by Card is clicked.
     *
     * @param int    $id        Quote primary key.
     * @param string $sessionId Stripe Checkout Session ID (cs_live_…).
     */
    public static function setStripeCheckoutSession(int $id, string $sessionId): void
    {
        $stmt = Database::rw()->prepare(
            'UPDATE quotes SET stripe_checkout_session_id = :session_id WHERE id = :id'
        );
        $stmt->execute([':session_id' => $sessionId, ':id' => $id]);
    }

    /**
     * Records Stripe payment identifiers and the payment method after a successful charge.
     *
     * @param int    $id              Quote primary key.
     * @param string $sessionId       Stripe Checkout Session ID.
     * @param string $paymentIntentId Stripe PaymentIntent ID.
     * @param string $paymentMethod   One of 'zelle', 'cashapp', 'stripe_full', 'other'.
     */
    public static function updateStripePayment(
        int    $id,
        string $sessionId,
        string $paymentIntentId,
        string $paymentMethod,
    ): void {
        $stmt = Database::rw()->prepare(
            'UPDATE quotes
                SET stripe_checkout_session_id = :session_id,
                    stripe_payment_intent_id   = :intent_id,
                    payment_method             = :method
              WHERE id = :id'
        );
        $stmt->execute([
            ':session_id' => $sessionId,
            ':intent_id'  => $paymentIntentId,
            ':method'     => $paymentMethod,
            ':id'         => $id,
        ]);
    }

    /**
     * Finds a quote by its Stripe Checkout Session ID.
     *
     * @param string $sessionId Stripe Checkout Session ID (cs_live_…).
     *
     * @return array<string, mixed>|null Quote row with customer columns, or null.
     */
    public static function findByStripeSession(string $sessionId): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT q.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone
               FROM quotes q
               LEFT JOIN customers c ON c.id = q.customer_id
              WHERE q.stripe_checkout_session_id = :session_id
              LIMIT 1'
        );
        $stmt->execute([':session_id' => $sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validates that moving from $current to $newStatus is permitted.
     *
     * Throws a RuntimeException with a human-readable message when the
     * transition is illegal, so callers do not need to repeat this logic.
     *
     * @param string $current   The quote's present status.
     * @param string $newStatus The desired target status.
     *
     * @return void
     *
     * @throws \RuntimeException On an illegal transition.
     */
    private static function assertTransitionAllowed(string $current, string $newStatus): void
    {
        // Cancellation from any status except completed is always valid.
        if ($newStatus === 'cancelled') {
            if ($current === 'completed') {
                throw new \RuntimeException(
                    "Cannot cancel a completed quote (current: {$current})."
                );
            }
            return;
        }

        $allowed = self::TRANSITIONS[$current] ?? null;
        if ($allowed !== $newStatus) {
            throw new \RuntimeException(
                "Invalid status transition: '{$current}' → '{$newStatus}'."
            );
        }
    }
}
