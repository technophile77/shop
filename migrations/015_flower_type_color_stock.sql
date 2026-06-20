-- =============================================================================
-- Migration: 015_flower_type_color_stock.sql
-- Adds a per-cell stock count (stems on hand) to the flower_type_colors matrix
-- table, so each flower-type/flower-color combination can record how many stems
-- are available. NULL = untracked (treated as unlimited), so the checkout stock
-- warning does not fire everywhere on day one; a non-null integer is the
-- available stem count. Run once only: ALTER TABLE will error if the column
-- already exists.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE flower_type_colors
    ADD COLUMN stock_count INT NULL DEFAULT NULL AFTER flower_color_id;
