-- =============================================================================
-- Migration 011: product_flower_type_colors
--
-- Records the colors shown in a product's photo PER flower type, so an
-- arrangement can be e.g. "red + white roses" (multiple colors of the same
-- flower type). Supersedes the single products.pictured_flower_color_id, which
-- is left in place as a legacy column (no longer read or written).
--
-- Run with the FULL database user (DDL access required).
-- Safe to re-run: CREATE TABLE uses IF NOT EXISTS.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS product_flower_type_colors (
    product_id      INT UNSIGNED NOT NULL,
    flower_type_id  INT UNSIGNED NOT NULL,
    flower_color_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, flower_type_id, flower_color_id),
    INDEX idx_pftc_product (product_id),
    CONSTRAINT fk_pftc_product FOREIGN KEY (product_id)      REFERENCES products(id)      ON DELETE CASCADE,
    CONSTRAINT fk_pftc_type    FOREIGN KEY (flower_type_id)  REFERENCES flower_types(id)  ON DELETE CASCADE,
    CONSTRAINT fk_pftc_color   FOREIGN KEY (flower_color_id) REFERENCES flower_colors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
