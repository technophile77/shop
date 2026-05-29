<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Represents a customer contact record in the `customers` table.
 *
 * Customers are upserted on every interaction — if a matching email or phone
 * already exists the row is updated (merging non-empty fields only); otherwise
 * a new row is inserted.  All reads use the read-only PDO connection; all
 * writes use the read-write connection.
 *
 * @see \App\Core\Database
 */
final class Customer
{
    /**
     * Finds a customer by email address.
     *
     * @param string $email The email to look up.
     *
     * @return array<string, mixed>|null The customer row, or null when not found.
     *
     * @example
     *   $row = Customer::findByEmail('rosa@example.com');
     */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM customers WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Finds a customer by phone number.
     *
     * @param string $phone The phone number to look up.
     *
     * @return array<string, mixed>|null The customer row, or null when not found.
     *
     * @example
     *   $row = Customer::findByPhone('5125550199');
     */
    public static function findByPhone(string $phone): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM customers WHERE phone = ? LIMIT 1'
        );
        $stmt->execute([$phone]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Upserts a customer record.
     *
     * If a row matching the supplied email (checked first) or phone (checked
     * second) is found, only non-empty fields in $data overwrite the stored
     * values — empty strings never blank out existing data.  If no match is
     * found a new row is inserted.  Returns the customer ID in both cases.
     *
     * @param array<string, mixed> $data Recognised keys: name, email, phone,
     *        source, opted_in_email, opted_in_sms, notes.
     *
     * @return int The ID of the upserted customer row.
     *
     * @example
     *   $id = Customer::upsert([
     *       'name'           => 'Rosa García',
     *       'email'          => 'rosa@example.com',
     *       'source'         => 'order_form',
     *       'opted_in_email' => 0,
     *       'opted_in_sms'   => 0,
     *   ]);
     */
    public static function upsert(array $data): int
    {
        $existing = null;

        // Check email first, then phone.
        if (!empty($data['email'])) {
            $existing = self::findByEmail($data['email']);
        }

        if ($existing === null && !empty($data['phone'])) {
            $existing = self::findByPhone($data['phone']);
        }

        if ($existing !== null) {
            self::mergeUpdate((int) $existing['id'], $data, $existing);
            return (int) $existing['id'];
        }

        return self::insert($data);
    }

    /**
     * Recalculates and persists the `lifetime_spend` for a customer.
     *
     * Sums the `subtotal` of all quotes in `deposit_confirmed` or `completed`
     * status that belong to the customer.  Call this after a quote transitions
     * to either of those statuses.
     *
     * @param int $customerId The customer whose spend should be refreshed.
     *
     * @return void
     *
     * @example
     *   Customer::refreshLifetimeSpend($customerId);
     */
    public static function refreshLifetimeSpend(int $customerId): void
    {
        $stmt = Database::ro()->prepare(
            "SELECT COALESCE(SUM(subtotal), 0) AS total
             FROM quotes
             WHERE customer_id = ?
               AND status IN ('deposit_confirmed', 'completed')"
        );
        $stmt->execute([$customerId]);
        $total = (float) ($stmt->fetchColumn() ?: 0);

        $upd = Database::rw()->prepare(
            'UPDATE customers SET lifetime_spend = ? WHERE id = ?'
        );
        $upd->execute([$total, $customerId]);
    }

    /**
     * Returns all customers ordered by lifetime spend descending.
     *
     * Supports optional filters: source (string), opted_in_sms (0/1),
     * min_spend (float).
     *
     * @param array<string, mixed> $filters Recognised keys: source,
     *        opted_in_sms, min_spend.
     *
     * @return array<int, array<string, mixed>> All matching customer rows.
     *
     * @example
     *   $vipSms = Customer::all(['opted_in_sms' => 1, 'min_spend' => 200]);
     */
    public static function all(array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['source'])) {
            $where[]  = 'source = ?';
            $params[] = $filters['source'];
        }

        if (isset($filters['opted_in_sms'])) {
            $where[]  = 'opted_in_sms = ?';
            $params[] = (int) $filters['opted_in_sms'];
        }

        if (isset($filters['min_spend'])) {
            $where[]  = 'lifetime_spend >= ?';
            $params[] = (float) $filters['min_spend'];
        }

        $sql = 'SELECT * FROM customers';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY lifetime_spend DESC';

        $stmt = Database::ro()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Returns customers whose lifetime_spend meets the VIP threshold.
     *
     * Reads the threshold from the `vip_spend_threshold` site setting,
     * defaulting to 200 when the setting is absent.
     *
     * @return array<int, array<string, mixed>> VIP customer rows.
     *
     * @example
     *   $vips = Customer::vip();
     */
    public static function vip(): array
    {
        $threshold = (float) (\App\Core\Settings::get('vip_spend_threshold', '200'));

        return self::all(['min_spend' => $threshold]);
    }

    /**
     * Returns a single customer by primary key.
     *
     * @param int $id The customer ID.
     *
     * @return array<string, mixed>|null The customer row, or null when not found.
     *
     * @example
     *   $customer = Customer::find(42);
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM customers WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Replaces the notes field for a customer.
     *
     * @param int    $id    The customer ID.
     * @param string $notes The new notes text.
     *
     * @return void
     *
     * @example
     *   Customer::updateNotes(42, 'Prefers pink roses.');
     */
    public static function updateNotes(int $id, string $notes): void
    {
        $stmt = Database::rw()->prepare(
            'UPDATE customers SET notes = ? WHERE id = ?'
        );
        $stmt->execute([$notes, $id]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Inserts a new customer row and returns its auto-increment ID.
     *
     * @param array<string, mixed> $data Field values for the new row.
     *
     * @return int The newly created customer ID.
     */
    private static function insert(array $data): int
    {
        $stmt = Database::rw()->prepare(
            'INSERT INTO customers
                (name, email, phone, source, opted_in_email, opted_in_sms, notes)
             VALUES
                (:name, :email, :phone, :source, :opted_in_email, :opted_in_sms, :notes)'
        );

        $stmt->execute([
            ':name'           => $data['name']           ?? null,
            ':email'          => $data['email']          ?? null,
            ':phone'          => $data['phone']          ?? null,
            ':source'         => $data['source']         ?? 'other',
            ':opted_in_email' => (int) ($data['opted_in_email'] ?? 0),
            ':opted_in_sms'   => (int) ($data['opted_in_sms']   ?? 0),
            ':notes'          => $data['notes']          ?? null,
        ]);

        return (int) Database::rw()->lastInsertId();
    }

    /**
     * Updates only non-empty fields from $data onto the existing customer row.
     *
     * Fields that are empty strings in $data are skipped so a partial form
     * submission cannot overwrite fields that a previous interaction populated.
     *
     * @param int                  $id       Customer primary key.
     * @param array<string, mixed> $data     Incoming data (may be partial).
     * @param array<string, mixed> $existing Current database row.
     *
     * @return void
     */
    private static function mergeUpdate(int $id, array $data, array $existing): void
    {
        $sets   = [];
        $params = [];

        foreach (['name', 'email', 'phone', 'source'] as $field) {
            if (!empty($data[$field]) && empty($existing[$field])) {
                $sets[]           = "{$field} = ?";
                $params[]         = $data[$field];
            }
        }

        // opted_in flags: update if the incoming value is 1 and existing is 0.
        foreach (['opted_in_email', 'opted_in_sms'] as $field) {
            if (isset($data[$field]) && (int) $data[$field] === 1 && (int) ($existing[$field] ?? 0) === 0) {
                $sets[]   = "{$field} = 1";
            }
        }

        // Notes: append if both old and new are non-empty; otherwise fill blank.
        if (!empty($data['notes'])) {
            if (!empty($existing['notes'])) {
                $sets[]   = 'notes = ?';
                $params[] = $existing['notes'] . "\n" . $data['notes'];
            } else {
                $sets[]   = 'notes = ?';
                $params[] = $data['notes'];
            }
        }

        if ($sets === []) {
            return;
        }

        $params[] = $id;
        $sql = 'UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?';
        Database::rw()->prepare($sql)->execute($params);
    }
}
