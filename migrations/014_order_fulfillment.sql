-- =============================================================================
-- Migration: 014_order_fulfillment.sql
-- Adds a requested fulfillment datetime to the orders table so the shop-cart
-- checkout can capture a specific delivery/pickup date + exact time. The
-- delivery_type column (enum delivery/pickup) already exists from the initial
-- schema; this only adds the scheduled time. Run once only: ALTER TABLE will
-- error if the column already exists.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE orders
    ADD COLUMN fulfill_at DATETIME NULL AFTER delivery_fee;
