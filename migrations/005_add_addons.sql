-- =============================================================================
-- Migration: 005_add_addons.sql
-- Adds the add-ons feature: a global add-ons table for admin-managed extras
-- that appear as image+checkbox pairs on the public order form, and a JSON
-- column on orders to store selected add-on snapshots per submission.
-- Safe to re-run: CREATE TABLE uses IF NOT EXISTS; ALTER TABLE will error if
-- the column already exists (run once only).
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Table: addons
-- Admin-managed extras (e.g. ribbon, vase, greeting card) shown as
-- image+checkbox pairs on the public "Request a Custom Bouquet" form.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS addons (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    name_en    VARCHAR(255)      NOT NULL,
    name_es    VARCHAR(255)      NULL,
    image_path VARCHAR(500)      NULL,
    active     TINYINT(1)        NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Column: orders.addons
-- JSON snapshot of selected add-ons per order submission.
-- NULL  = pre-feature order (form did not show add-ons section).
-- '[]'  = form was shown, customer selected nothing.
-- '[…]' = array of {id, name_en, name_es} snapshots.
-- Snapshots preserve names even after an add-on record is soft-deleted.
-- ---------------------------------------------------------------------------
ALTER TABLE orders
    ADD COLUMN addons JSON NULL AFTER notes;
