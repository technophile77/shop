-- =============================================================================
-- Migration: 017_store_closures.sql
-- Adds the store_closures table so the shop can declare date ranges on which
-- no fulfillment (order-form event dates or shop-cart delivery/pickup) is
-- available (e.g. holidays, vacations, supplier gaps).
--
-- Both start_date and end_date are INCLUSIVE. A single-day closure is
-- represented by start_date = end_date. Overlap between closures is NOT
-- enforced by the database — expressing "no two ranges overlap" is not
-- possible with a plain CHECK/UNIQUE constraint in MySQL — so it is rejected
-- in application code by App\Support\Closures::validateRange() before an
-- INSERT/UPDATE is issued.
--
-- No CHECK (end_date >= start_date) constraint is added: CHECK constraints
-- are silently IGNORED (parsed but not enforced) on MySQL versions older
-- than 8.0.16, and the exact server version on the target host is
-- unverified, so relying on one here would give a false sense of safety.
-- The same ordering rule is enforced in PHP instead.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS store_closures (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    start_date DATE         NOT NULL,
    end_date   DATE         NOT NULL,
    reason     VARCHAR(255) NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_closure_range (start_date, end_date),
    KEY idx_closure_end (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
