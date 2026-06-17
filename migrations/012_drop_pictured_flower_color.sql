-- =============================================================================
-- Migration 012: drop the legacy products.pictured_flower_color_id
--
-- The single pictured flower color was superseded by the per-flower-type
-- product_flower_type_colors join (migration 011). The column is no longer
-- read or written, so drop it and its foreign key.
--
-- Run with the FULL database user (DDL access required). Runs once only.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE products DROP FOREIGN KEY fk_products_flower_color;
ALTER TABLE products DROP COLUMN pictured_flower_color_id;
