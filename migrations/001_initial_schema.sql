-- =============================================================================
-- Migration: 001_initial_schema.sql
-- Project:   Perla's Flowers
-- Database:  acedeath_flowers @ vdb6.pit.pair.com
-- User:      acedeath_5
-- Created:   2026-05-29
--
-- Creates the complete initial schema for Perla's Flowers, including all
-- tables, indexes, foreign keys, and seed data required for the application
-- to function from first deployment.
--
-- Safe to re-run: all CREATE TABLE statements use IF NOT EXISTS and all
-- INSERT statements use INSERT IGNORE.
-- =============================================================================

-- Declare UTF-8 client charset before any DML so accented characters in seed
-- data are not misinterpreted as Latin-1 by the mysql CLI (which defaults to
-- Latin-1), which would cause double-encoding in utf8mb4 columns.
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ---------------------------------------------------------------------------
-- Table: admin_users
-- Owner logins for the admin panel. Passwords are stored as bcrypt hashes
-- (cost 12) and never in plaintext.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name          VARCHAR(255)    NOT NULL,
    email         VARCHAR(255)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    last_login    TIMESTAMP       NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: product_categories
-- Fully managed through the admin panel. No categories are hardcoded in
-- application code — all category logic drives from this table.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_categories (
    id         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    name_en    VARCHAR(255)   NOT NULL,
    name_es    VARCHAR(255)   NULL,
    slug       VARCHAR(100)   NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    active     TINYINT(1)     NOT NULL DEFAULT 1,
    created_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: products
-- Flower products displayed on the public-facing site. Each product belongs
-- to exactly one category. Price is stored as a range (price_from / price_to)
-- to support arrangements with variable sizing.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name_en         VARCHAR(255)    NOT NULL,
    name_es         VARCHAR(255)    NULL,
    description_en  TEXT            NULL,
    description_es  TEXT            NULL,
    price_from      DECIMAL(10,2)   NULL,
    price_to        DECIMAL(10,2)   NULL,
    category_id     INT UNSIGNED    NOT NULL,
    image_path      VARCHAR(500)    NULL,
    featured        TINYINT(1)      NOT NULL DEFAULT 0,
    active          TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_category          (category_id),
    INDEX idx_featured_active   (featured, active),

    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id)
        REFERENCES product_categories (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: customers
-- Unified customer record regardless of acquisition channel. Upserted on
-- every interaction so each real person has one row. lifetime_spend is
-- updated whenever a quote moves to deposit_confirmed or completed.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name            VARCHAR(255)    NULL,
    email           VARCHAR(255)    NULL,
    phone           VARCHAR(50)     NULL,
    source          ENUM(
                        'website_chat',
                        'order_form',
                        'promotion_signup',
                        'facebook',
                        'instagram',
                        'doordash',
                        'manual',
                        'other'
                    )               NOT NULL DEFAULT 'other',
    opted_in_email  TINYINT(1)      NOT NULL DEFAULT 0,
    opted_in_sms    TINYINT(1)      NOT NULL DEFAULT 0,
    notes           TEXT            NULL,
    lifetime_spend  DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_email         (email),
    INDEX idx_phone         (phone),
    INDEX idx_source        (source),
    INDEX idx_lifetime_spend (lifetime_spend)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: quotes
-- Shareable quote links generated by the admin and sent to customers via
-- any channel (WhatsApp, email, DM, etc.). Each quote has a unique token
-- that the customer uses to view and accept the quote.
-- items_json holds the line items as a JSON array:
--   [{"description": "...", "qty": 2, "unit_price": 45.00}, ...]
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quotes (
    id                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    customer_id          INT UNSIGNED    NULL,
    token                VARCHAR(64)     NOT NULL,
    event_date           DATE            NULL,
    items_json           JSON            NOT NULL,
    subtotal             DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    deposit_amount       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    status               ENUM(
                             'draft',
                             'sent',
                             'accepted',
                             'deposit_confirmed',
                             'completed',
                             'cancelled'
                         )               NOT NULL DEFAULT 'draft',
    notes                TEXT            NULL,
    valid_until          DATE            NULL,
    created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at              TIMESTAMP       NULL,
    accepted_at          TIMESTAMP       NULL,
    deposit_confirmed_at TIMESTAMP       NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    INDEX idx_token     (token),
    INDEX idx_status    (status),
    INDEX idx_customer  (customer_id),

    CONSTRAINT fk_quotes_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: orders
-- Custom bouquet order requests submitted through the website order form.
-- May be linked to a quote after the admin creates one in response to the
-- request. Also linked to a customer record (upserted on submission).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    quote_id            INT UNSIGNED    NULL,
    customer_id         INT UNSIGNED    NULL,
    status              ENUM(
                            'pending',
                            'in_progress',
                            'ready',
                            'delivered',
                            'completed',
                            'cancelled'
                        )               NOT NULL DEFAULT 'pending',
    event_date          DATE            NULL,
    delivery_type       ENUM('delivery', 'pickup') NOT NULL DEFAULT 'pickup',
    delivery_address    TEXT            NULL,
    occasion            VARCHAR(255)    NULL,
    arrangement_style   VARCHAR(255)    NULL,
    color_preferences   VARCHAR(255)    NULL,
    budget_range        VARCHAR(100)    NULL,
    notes               TEXT            NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_status        (status),
    INDEX idx_customer      (customer_id),
    INDEX idx_event_date    (event_date),

    CONSTRAINT fk_orders_quote
        FOREIGN KEY (quote_id)
        REFERENCES quotes (id)
        ON DELETE SET NULL,

    CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: promotion_signups
-- Records each submission of the website's promotion signup form. Always
-- linked to a customer record (upserted on signup) so marketing lists can
-- be built from either table.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS promotion_signups (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    customer_id    INT UNSIGNED    NULL,
    email          VARCHAR(255)    NULL,
    phone          VARCHAR(50)     NULL,
    opted_in_email TINYINT(1)      NOT NULL DEFAULT 1,
    opted_in_sms   TINYINT(1)      NOT NULL DEFAULT 1,
    source_page    VARCHAR(255)    NULL,
    created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_email  (email),
    INDEX idx_phone  (phone),

    CONSTRAINT fk_promotion_signups_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: ad_campaigns
-- Facebook and Instagram ad campaigns managed through the admin panel via
-- the Meta Marketing API. Supports A/B pairs (variant + ab_group_id) and
-- stores both planned spend (budget) and actual spend pulled from the API.
-- fb_campaign_id / fb_adset_id / fb_ad_id are populated after the campaign
-- is pushed to Meta and contain the corresponding Meta object IDs.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ad_campaigns (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name           VARCHAR(255)    NOT NULL,
    platform       ENUM('facebook', 'instagram', 'both', 'other') NOT NULL DEFAULT 'both',
    variant        CHAR(1)         NULL,
    ab_group_id    VARCHAR(64)     NULL,
    start_date     DATE            NULL,
    end_date       DATE            NULL,
    budget         DECIMAL(10,2)   NULL,
    spend          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    impressions    INT UNSIGNED    NOT NULL DEFAULT 0,
    clicks         INT UNSIGNED    NOT NULL DEFAULT 0,
    revenue        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    status         ENUM('draft', 'active', 'paused', 'completed') NOT NULL DEFAULT 'draft',
    ad_headline    VARCHAR(255)    NULL,
    ad_copy        TEXT            NULL,
    ad_image_path  VARCHAR(500)    NULL,
    ad_link        VARCHAR(500)    NULL,
    objective      ENUM(
                       'TRAFFIC',
                       'LEAD_GENERATION',
                       'REACH',
                       'CONVERSIONS'
                   )               NOT NULL DEFAULT 'TRAFFIC',
    fb_campaign_id VARCHAR(64)     NULL,
    fb_adset_id    VARCHAR(64)     NULL,
    fb_ad_id       VARCHAR(64)     NULL,
    notes          TEXT            NULL,
    created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_status    (status),
    INDEX idx_ab_group  (ab_group_id),
    INDEX idx_platform  (platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: site_settings
-- Key-value store for all configurable UI strings, labels, and feature
-- toggles. Managed exclusively through the admin "Site Settings" panel so
-- nothing is hardcoded in templates or application code.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `key`      VARCHAR(100)    NOT NULL,
    value      TEXT            NULL,
    updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: page_views
-- First-party page view tracking used by the analytics dashboard. Sessions
-- are identified by a random token set in a cookie. ip_hash is a one-way
-- hash of the visitor IP for privacy compliance.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS page_views (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    session_token VARCHAR(64)     NOT NULL,
    page_url      VARCHAR(1000)   NOT NULL,
    referrer      VARCHAR(1000)   NULL,
    utm_source    VARCHAR(255)    NULL,
    utm_medium    VARCHAR(255)    NULL,
    utm_campaign  VARCHAR(255)    NULL,
    utm_content   VARCHAR(255)    NULL,
    utm_term      VARCHAR(255)    NULL,
    ip_hash       VARCHAR(64)     NULL,
    customer_id   INT UNSIGNED    NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_session       (session_token),
    INDEX idx_utm_campaign  (utm_campaign),
    INDEX idx_created       (created_at),

    CONSTRAINT fk_page_views_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Table: ad_sessions
-- Ad attribution tracking. One row per unique UTM-tagged visit session.
-- Links the initial landing session (identified by session_token) to a
-- downstream conversion (signup, order, or quote) so campaign ROI can be
-- calculated in the analytics dashboard.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ad_sessions (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    session_token   VARCHAR(64)     NOT NULL,
    utm_source      VARCHAR(255)    NULL,
    utm_medium      VARCHAR(255)    NULL,
    utm_campaign    VARCHAR(255)    NULL,
    utm_content     VARCHAR(255)    NULL,
    utm_term        VARCHAR(255)    NULL,
    ip_hash         VARCHAR(64)     NULL,
    landed_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    converted_at    TIMESTAMP       NULL,
    conversion_type ENUM('signup', 'order', 'quote', 'none') NOT NULL DEFAULT 'none',

    PRIMARY KEY (id),
    INDEX idx_session       (session_token),
    INDEX idx_utm_campaign  (utm_campaign),
    INDEX idx_conversion    (conversion_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- SEED DATA
-- All inserts use INSERT IGNORE so this migration is safe to re-run.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Default admin user
-- IMPORTANT: The password_hash below is a placeholder only.
-- The real bcrypt hash must be set before the admin panel is used.
-- Either run the provided setup script (bin/set-admin-password.php) or
-- update this row manually after first deployment:
--   UPDATE admin_users SET password_hash = '<real_hash>' WHERE email = 'admin@flowers.local';
-- The admin should change their password immediately on first login.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO admin_users (name, email, password_hash)
VALUES (
    'Admin',
    'admin@flowers.local',
    '$2y$12$PLACEHOLDER_CHANGE_ON_FIRST_LOGIN'
);

-- ---------------------------------------------------------------------------
-- Default product categories
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO product_categories (name_en, name_es, slug, sort_order) VALUES
    ('Fresh Flowers',          'Flores Frescas',       'fresh',   1),
    ('Eternal Flowers',        'Flores Eternas',       'eternal', 2),
    ('Events & Arrangements',  'Eventos y Arreglos',   'events',  3);

-- ---------------------------------------------------------------------------
-- Default site settings
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO site_settings (`key`, value) VALUES
    ('order_button_text_en',    'Request a Custom Bouquet'),
    ('order_button_text_es',    'Solicitar un Ramo Personalizado'),
    ('hero_headline_en',        'Handcrafted with Love'),
    ('hero_headline_es',        'Hecho con Amor'),
    ('hero_subtext_en',         'Fresh & eternal flower arrangements for every occasion'),
    ('hero_subtext_es',         'Arreglos de flores frescas y eternas para toda ocasión'),
    ('promo_strip_text_en',     'Sign up for exclusive offers and seasonal promotions'),
    ('promo_strip_text_es',     'Regístrate para ofertas exclusivas y promociones de temporada'),
    ('doordash_button_label_en','Order Now on DoorDash'),
    ('doordash_button_label_es','Pedir Ahora en DoorDash'),
    ('show_doordash_button',    '1'),
    ('show_whatsapp_button',    '1'),
    ('vip_spend_threshold',     '200'),
    ('products_page_title_en',  'Our Flowers'),
    ('products_page_title_es',  'Nuestras Flores'),
    ('order_page_title_en',     'Request a Custom Bouquet'),
    ('order_page_title_es',     'Solicitar un Ramo Personalizado'),
    ('about_page_title_en',     'Our Story'),
    ('about_page_title_es',     'Nuestra Historia'),
    ('contact_page_title_en',   'Get in Touch'),
    ('contact_page_title_es',   'Contáctanos'),
    ('signup_title_en',         'Join Our Flower Family'),
    ('signup_title_es',         'Únete a Nuestra Familia Floral');
