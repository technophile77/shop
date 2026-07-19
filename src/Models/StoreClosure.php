<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Data-access layer for the `store_closures` table.
 *
 * Store closures are admin-declared date ranges (both endpoints inclusive)
 * on which no fulfillment is available. All overlap/range/formatting logic
 * lives in {@see \App\Support\Closures} instead of here — that class is pure
 * and unit-testable, whereas this class's static Database calls are not
 * mockable, so it stays a thin, untested read/write layer. Read methods use
 * the read-only PDO connection; write methods use the read-write connection.
 * All queries use prepared statements.
 *
 * Expected schema (see migrations/017_store_closures.sql):
 *   CREATE TABLE store_closures (
 *       id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
 *       start_date DATE         NOT NULL,
 *       end_date   DATE         NOT NULL,
 *       reason     VARCHAR(255) NULL,
 *       created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *       PRIMARY KEY (id),
 *       KEY idx_closure_range (start_date, end_date),
 *       KEY idx_closure_end (end_date)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * @see \App\Support\Closures  Pure overlap/formatting/validation logic that consumes rows from this model.
 * @see \App\Core\Database     Supplies the PDO connections.
 */
final class StoreClosure
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Read methods (use Database::ro())
    // -------------------------------------------------------------------------

    /**
     * Return all closures ordered by start_date ASC, then id ASC.
     *
     * Includes past, current, and future closures. Use {@see upcoming()} for
     * enforcement contexts where past closures are irrelevant.
     *
     * @return array<int, array> Each row is an assoc array of store_closures columns.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $all = StoreClosure::all();
     */
    public static function all(): array
    {
        $stmt = Database::ro()->query(
            'SELECT * FROM store_closures ORDER BY start_date ASC, id ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Return closures that have not fully elapsed as of a reference date.
     *
     * This is the read used by every enforcement point (order form,
     * checkout, admin warnings): a closure is still relevant once its
     * end_date is on or after $today, even if it has already started.
     *
     * @param string $today Reference date, 'Y-m-d' (the caller supplies "now" —
     *                      this class never reads the clock).
     *
     * @return array<int, array> Closures with end_date >= $today, ordered by
     *                           start_date ASC, then id ASC.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $upcoming = StoreClosure::upcoming('2026-07-01');
     */
    public static function upcoming(string $today): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM store_closures WHERE end_date >= ? ORDER BY start_date ASC, id ASC'
        );
        $stmt->execute([$today]);

        return $stmt->fetchAll();
    }

    /**
     * Return closures whose range intersects a given window.
     *
     * Uses the standard interval-overlap predicate
     * (start_date <= window_end AND end_date >= window_start) — note that
     * $from and $to are therefore passed to the query in SWAPPED order
     * ([$to, $from]) to match the placeholder order in the SQL.
     *
     * @param string $from Inclusive window start, 'Y-m-d'.
     * @param string $to   Inclusive window end, 'Y-m-d'.
     *
     * @return array<int, array> Closures intersecting [$from, $to], ordered by
     *                           start_date ASC, then id ASC.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $inWindow = StoreClosure::betweenDates('2026-07-01', '2026-07-31');
     */
    public static function betweenDates(string $from, string $to): array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM store_closures WHERE start_date <= ? AND end_date >= ? ORDER BY start_date ASC, id ASC'
        );
        // Placeholder order matches the SQL, not the argument order: the
        // window's END bounds start_date, and the window's START bounds end_date.
        $stmt->execute([$to, $from]);

        return $stmt->fetchAll();
    }

    /**
     * Find a single closure by its primary key.
     *
     * Returns null when no closure with that ID exists, so callers can emit
     * a 404 without catching an exception.
     *
     * @param int $id The closure's primary key.
     *
     * @return array|null The closure row, or null when not found.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $closure = StoreClosure::find(3);
     *   if ($closure === null) { return Response::notFound(); }
     */
    public static function find(int $id): ?array
    {
        $stmt = Database::ro()->prepare(
            'SELECT * FROM store_closures WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Return closures whose range overlaps a proposed [start, end] range.
     *
     * Used by the admin UI to block/flag overlapping ranges before save.
     * Pass $excludeId when editing an existing closure so it doesn't flag
     * itself as an overlap.
     *
     * @param string   $start     Proposed inclusive start date, 'Y-m-d'.
     * @param string   $end       Proposed inclusive end date, 'Y-m-d'.
     * @param int|null $excludeId Closure ID to exclude from the check (the row being edited), or null.
     *
     * @return array<int, array> Overlapping closure rows.
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   StoreClosure::overlapping('2026-07-04', '2026-07-08'); // rows overlapping that range
     *   StoreClosure::overlapping('2026-07-04', '2026-07-08', 5); // same, excluding closure #5
     */
    public static function overlapping(string $start, string $end, ?int $excludeId = null): array
    {
        $sql    = 'SELECT * FROM store_closures WHERE start_date <= ? AND end_date >= ?';
        $params = [$end, $start];

        if ($excludeId !== null) {
            $sql      .= ' AND id <> ?';
            $params[]  = $excludeId;
        }

        $sql .= ' ORDER BY start_date ASC, id ASC';

        $stmt = Database::ro()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Count existing orders that fall inside a proposed/saved closure range.
     *
     * Used to warn the admin before closing dates that already have orders
     * on the books. The `orders` table has two independent date columns —
     * `event_date` (DATE, from the /order request form) and `fulfill_at`
     * (DATETIME, from /checkout) — so an order counts if EITHER falls inside
     * the range. The fulfill_at check uses a half-open upper bound
     * (`< DATE_ADD(?, INTERVAL 1 DAY)`) rather than a plain BETWEEN: BETWEEN
     * would only match fulfill_at values at exactly midnight on the end date
     * and silently miss every order timed later that day.
     *
     * @param string $start Inclusive range start, 'Y-m-d'.
     * @param string $end   Inclusive range end, 'Y-m-d'.
     *
     * @return int Number of orders with event_date or fulfill_at inside [$start, $end].
     *
     * @throws \PDOException When the database query fails.
     *
     * @example
     *   $affected = StoreClosure::countOrdersInRange('2026-07-04', '2026-07-08');
     *   // $affected > 0 means an admin warning should be shown before saving.
     */
    public static function countOrdersInRange(string $start, string $end): int
    {
        $stmt = Database::ro()->prepare(
            'SELECT COUNT(*) FROM orders
             WHERE (event_date BETWEEN ? AND ?)
                OR (fulfill_at >= ? AND fulfill_at < DATE_ADD(?, INTERVAL 1 DAY))'
        );
        $stmt->execute([$start, $end, $start, $end]);

        return (int) $stmt->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Write methods (use Database::rw())
    // -------------------------------------------------------------------------

    /**
     * Insert a new closure row and return the new auto-increment ID.
     *
     * The caller is responsible for range validation (ordering, span,
     * overlap) before calling create() — see
     * {@see \App\Support\Closures::validateRange()}.
     *
     * @param array $data Fields to insert; recognised keys: start_date (required),
     *                    end_date (required), reason (optional; an empty
     *                    string is stored as NULL).
     *
     * @return int The new closure's primary key.
     *
     * @throws \PDOException When the INSERT fails.
     *
     * @example
     *   $id = StoreClosure::create(['start_date' => '2026-07-04', 'end_date' => '2026-07-08', 'reason' => 'Independence Day week']);
     */
    public static function create(array $data): int
    {
        $db   = Database::rw();
        $stmt = $db->prepare(
            'INSERT INTO store_closures (start_date, end_date, reason)
             VALUES (:start_date, :end_date, :reason)'
        );

        $nullable = static fn (string $k): ?string =>
            isset($data[$k]) && $data[$k] !== '' ? (string) $data[$k] : null;

        $stmt->execute([
            ':start_date' => $data['start_date'] ?? '',
            ':end_date'   => $data['end_date']   ?? '',
            ':reason'     => $nullable('reason'),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Update an existing closure by primary key.
     *
     * Only columns present in $data are updated; omitted columns retain
     * their current values. Returns true when at least one row was matched.
     *
     * @param int   $id   The closure's primary key.
     * @param array $data Map of column => value to update. Recognised keys:
     *                    start_date, end_date, reason.
     *
     * @return bool True when the UPDATE matched a row, false when the ID was not found.
     *
     * @throws \PDOException When the UPDATE fails.
     *
     * @example
     *   StoreClosure::update(3, ['end_date' => '2026-07-09']);
     */
    public static function update(int $id, array $data): bool
    {
        $allowed    = ['start_date', 'end_date', 'reason'];
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
        $sql           = 'UPDATE store_closures SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

        $stmt = Database::rw()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Permanently delete a closure by primary key.
     *
     * @param int $id The closure's primary key.
     *
     * @return bool True when a row was deleted, false when the ID was not found.
     *
     * @throws \PDOException When the DELETE fails.
     *
     * @example
     *   StoreClosure::delete(3);
     */
    public static function delete(int $id): bool
    {
        $stmt = Database::rw()->prepare('DELETE FROM store_closures WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
