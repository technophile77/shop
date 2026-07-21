-- =============================================================================
-- Migration: 018_add_quote_delivery_fee.sql
-- Adds a dedicated delivery_fee column to quotes so delivery can be stated as
-- a separate, untaxed field — mirroring how orders already model it (see
-- 002_add_delivery_fee.sql).
--
-- Oklahoma exempts delivery charges from sales tax when they are stated
-- separately from the merchandise being sold. The shop-cart checkout path
-- already gets this right (App\Support\CheckoutPricing::compute() taxes only
-- the merchandise subtotal; App\Support\StripeLineItems::fromCart() adds the
-- Delivery line with no tax_rates). The quote path did not: delivery was a
-- hand-typed line inside items_json, so App\Models\Quote::create()/update()
-- folded it into `subtotal` and taxed it along with the merchandise.
--
-- delivery_fee is DECIMAL(8,2) to match the orders table, and NOT NULL
-- DEFAULT 0.00 so every existing quote keeps rendering exactly as it does
-- today (subtotal unchanged, delivery_fee simply reads as 0.00). Existing
-- quotes retain their delivery amount inside items_json — and therefore still
-- taxed — until the quote is manually edited and delivery is re-entered as
-- the new delivery_fee field instead of a line item.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE quotes
    ADD COLUMN delivery_fee DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER tax_amount;
