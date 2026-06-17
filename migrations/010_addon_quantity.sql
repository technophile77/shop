-- =============================================================================
-- Migration: 010_addon_quantity.sql
-- Adds a per-add-on "quantity" capability: add-ons flagged has_quantity = 1
-- (e.g. chocolates) let the customer choose an amount, priced per unit.
-- Safe to re-run: this ALTER TABLE runs once only and will error if the column
-- already exists (run once only).
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Column: addons.has_quantity
-- 1 = customer chooses a quantity for this add-on; line price = price × qty.
-- 0 = single add-on (checkbox only).
-- ---------------------------------------------------------------------------
ALTER TABLE addons
    ADD COLUMN has_quantity TINYINT(1) NOT NULL DEFAULT 0 AFTER has_custom_text;
