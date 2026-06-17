-- =============================================================================
-- Migration: 009_shop_orders.sql
-- Extends the orders table to support shop-cart checkout orders paid via
-- Stripe. Adds payment tracking columns, a JSON item snapshot, card message,
-- venue context, and pricing breakdown alongside the existing quote-request
-- columns. Run once only: ALTER TABLE will error if columns already exist.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE orders
    ADD COLUMN items_json                   JSON         NULL          AFTER addons,
    ADD COLUMN card_message                 TEXT         NULL          AFTER items_json,
    ADD COLUMN delivery_venue_name          VARCHAR(255) NULL          AFTER card_message,
    ADD COLUMN occasion_type                VARCHAR(80)  NULL          AFTER delivery_venue_name,
    ADD COLUMN subtotal                     DECIMAL(10,2) NULL         AFTER occasion_type,
    ADD COLUMN tax_amount                   DECIMAL(10,2) NULL         AFTER subtotal,
    ADD COLUMN total                        DECIMAL(10,2) NULL         AFTER tax_amount,
    ADD COLUMN payment_status               VARCHAR(32)  NOT NULL DEFAULT 'unpaid' AFTER total,
    ADD COLUMN stripe_checkout_session_id   VARCHAR(255) NULL          AFTER payment_status,
    ADD COLUMN stripe_payment_intent_id     VARCHAR(255) NULL          AFTER stripe_checkout_session_id;

ALTER TABLE orders
    ADD INDEX idx_stripe_checkout_session_id (stripe_checkout_session_id);
