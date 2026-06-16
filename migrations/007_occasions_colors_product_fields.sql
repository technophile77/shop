-- =============================================================================
-- Migration 007: Occasions, Flower Types, Flower Colors, Paper Colors,
--                and new product metadata fields.
--
-- Creates lookup tables for occasion tagging, flower types, flower colors, and
-- paper/wrap colors, along with join tables connecting products to occasions
-- and flower types, and a flower-type-to-color matrix table.
-- Also adds flower_count, pictured_flower_color_id, and pictured_paper_color_id
-- to the products table.
--
-- Run with the FULL database user (DDL access required).
-- Safe to run more than once: all CREATE TABLE statements use IF NOT EXISTS,
-- and seed rows use INSERT ... ON DUPLICATE KEY UPDATE or INSERT IGNORE.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- occasions
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS occasions (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    slug       VARCHAR(80)       NOT NULL,
    name_en    VARCHAR(120)      NOT NULL,
    name_es    VARCHAR(120)      NULL,
    active     TINYINT(1)        NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug),
    INDEX idx_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO occasions (slug, name_en, name_es, sort_order) VALUES
    ('get-well',  'Get Well',  'Que te mejores', 10),
    ('new-baby',  'New Baby',  'Nuevo Bebé',     20),
    ('sympathy',  'Sympathy',  'Condolencias',   30),
    ('birthday',  'Birthday',  'Cumpleaños',     40)
ON DUPLICATE KEY UPDATE
    name_en = VALUES(name_en),
    name_es = VALUES(name_es);

-- -----------------------------------------------------------------------------
-- product_occasions
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_occasions (
    product_id  INT UNSIGNED NOT NULL,
    occasion_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, occasion_id),
    INDEX idx_occasion (occasion_id),
    CONSTRAINT fk_po_product  FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE CASCADE,
    CONSTRAINT fk_po_occasion FOREIGN KEY (occasion_id) REFERENCES occasions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- flower_types
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS flower_types (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    name_en    VARCHAR(120)      NOT NULL,
    name_es    VARCHAR(120)      NULL,
    active     TINYINT(1)        NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO flower_types (name_en, name_es, sort_order) VALUES
    ('Roses',      'Rosas',      10),
    ('Carnations', 'Claveles',   20),
    ('Tulips',     'Tulipanes',  30),
    ('Lilies',     'Lirios',     40),
    ('Sunflowers', 'Girasoles',  50);

-- -----------------------------------------------------------------------------
-- flower_colors
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS flower_colors (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    name_en    VARCHAR(120)      NOT NULL,
    name_es    VARCHAR(120)      NULL,
    hex        VARCHAR(7)        NULL,
    active     TINYINT(1)        NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO flower_colors (name_en, name_es, hex, sort_order) VALUES
    ('Red',      'Rojo',    '#D32F2F', 10),
    ('Pink',     'Rosa',    '#EC407A', 20),
    ('White',    'Blanco',  '#FFFFFF', 30),
    ('Yellow',   'Amarillo','#FBC02D', 40),
    ('Orange',   'Naranja', '#FB8C00', 50),
    ('Purple',   'Morado',  '#8E24AA', 60),
    ('Lavender', 'Lavanda', '#B39DDB', 70),
    ('Peach',    'Durazno', '#FFCC80', 80);

-- -----------------------------------------------------------------------------
-- paper_colors
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS paper_colors (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    name_en    VARCHAR(120)      NOT NULL,
    name_es    VARCHAR(120)      NULL,
    hex        VARCHAR(7)        NULL,
    active     TINYINT(1)        NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_active_sort (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO paper_colors (name_en, name_es, hex, sort_order) VALUES
    ('Kraft',    'Kraft',   '#C8A06A', 10),
    ('White',    'Blanco',  '#FFFFFF', 20),
    ('Black',    'Negro',   '#222222', 30),
    ('Pink',     'Rosa',    '#F8BBD0', 40),
    ('Burgundy', 'Vino',    '#6D1A36', 50);

-- -----------------------------------------------------------------------------
-- flower_type_colors (matrix: which colors each flower type comes in)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS flower_type_colors (
    flower_type_id  INT UNSIGNED NOT NULL,
    flower_color_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (flower_type_id, flower_color_id),
    CONSTRAINT fk_ftc_type  FOREIGN KEY (flower_type_id)  REFERENCES flower_types(id)  ON DELETE CASCADE,
    CONSTRAINT fk_ftc_color FOREIGN KEY (flower_color_id) REFERENCES flower_colors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roses → Red, Pink, White, Yellow, Peach
INSERT IGNORE INTO flower_type_colors (flower_type_id, flower_color_id)
    SELECT ft.id, fc.id
    FROM flower_types ft, flower_colors fc
    WHERE ft.name_en = 'Roses'
      AND fc.name_en IN ('Red', 'Pink', 'White', 'Yellow', 'Peach');

-- Carnations → Red, Pink, White, Purple
INSERT IGNORE INTO flower_type_colors (flower_type_id, flower_color_id)
    SELECT ft.id, fc.id
    FROM flower_types ft, flower_colors fc
    WHERE ft.name_en = 'Carnations'
      AND fc.name_en IN ('Red', 'Pink', 'White', 'Purple');

-- Tulips → Red, Pink, White, Yellow, Purple
INSERT IGNORE INTO flower_type_colors (flower_type_id, flower_color_id)
    SELECT ft.id, fc.id
    FROM flower_types ft, flower_colors fc
    WHERE ft.name_en = 'Tulips'
      AND fc.name_en IN ('Red', 'Pink', 'White', 'Yellow', 'Purple');

-- Lilies → White, Pink, Orange
INSERT IGNORE INTO flower_type_colors (flower_type_id, flower_color_id)
    SELECT ft.id, fc.id
    FROM flower_types ft, flower_colors fc
    WHERE ft.name_en = 'Lilies'
      AND fc.name_en IN ('White', 'Pink', 'Orange');

-- Sunflowers → Yellow, Orange
INSERT IGNORE INTO flower_type_colors (flower_type_id, flower_color_id)
    SELECT ft.id, fc.id
    FROM flower_types ft, flower_colors fc
    WHERE ft.name_en = 'Sunflowers'
      AND fc.name_en IN ('Yellow', 'Orange');

-- -----------------------------------------------------------------------------
-- product_flower_types
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_flower_types (
    product_id     INT UNSIGNED NOT NULL,
    flower_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, flower_type_id),
    INDEX idx_flower_type (flower_type_id),
    CONSTRAINT fk_pft_product     FOREIGN KEY (product_id)     REFERENCES products(id)      ON DELETE CASCADE,
    CONSTRAINT fk_pft_flower_type FOREIGN KEY (flower_type_id) REFERENCES flower_types(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Alter products: add flower metadata columns
-- -----------------------------------------------------------------------------
ALTER TABLE products
    ADD COLUMN flower_count             SMALLINT UNSIGNED NULL,
    ADD COLUMN pictured_flower_color_id INT UNSIGNED      NULL,
    ADD COLUMN pictured_paper_color_id  INT UNSIGNED      NULL;

ALTER TABLE products
    ADD CONSTRAINT fk_products_flower_color FOREIGN KEY (pictured_flower_color_id) REFERENCES flower_colors(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_products_paper_color  FOREIGN KEY (pictured_paper_color_id)  REFERENCES paper_colors(id)  ON DELETE SET NULL;
