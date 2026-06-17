-- =============================================================================
-- Migration: 008_addon_pricing.sql
-- Extends the add-ons table with a per-unit price and a flag marking add-ons
-- that accept a customer-supplied text line (e.g. ribbon message).
-- Safe to re-run: CREATE TABLE (from 005) uses IF NOT EXISTS; these ALTER TABLE
-- statements run once only and will error if columns already exist.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Column: addons.price
-- Per-unit USD price for this add-on (e.g. 5.00 for a ribbon).
-- 0.00 = included at no extra charge.
-- ---------------------------------------------------------------------------
ALTER TABLE addons
    ADD COLUMN price DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER sort_order;

-- ---------------------------------------------------------------------------
-- Column: addons.has_custom_text
-- 1 = this add-on accepts a customer text line (e.g. ribbon message).
-- 0 = no custom text field shown.
-- ---------------------------------------------------------------------------
ALTER TABLE addons
    ADD COLUMN has_custom_text TINYINT(1) NOT NULL DEFAULT 0 AFTER price;
