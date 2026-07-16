-- =============================================================================
-- Migration: 016_customer_source_attribution.sql
-- Extends customer/order source tracking to cover the newer acquisition paths.
-- customers.source is currently an 8-member ENUM; several code paths already
-- write 'shop_checkout', 'quote_accept', and 'admin_quote', which the old ENUM
-- silently coerces to '' since none of those values are members. 'google_ads'
-- is added as the new paid-Google attribution value. The four new members are
-- appended after the existing eight rather than inserted alphabetically or
-- interleaved, because MySQL stores ENUM values by their positional index;
-- appending keeps every already-stored index (and therefore every existing
-- row's value) stable, while re-ordering would silently remap old rows to the
-- wrong label. orders.session_token links an order to the page_views/
-- ad_sessions tracking session that produced it: it is set at order creation
-- and later read by the Stripe webhook to mark ad conversions. Run once only:
-- ALTER TABLE will error if the column already exists.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE customers
    MODIFY COLUMN source ENUM(
        'website_chat','order_form','promotion_signup','facebook','instagram',
        'doordash','manual','other',
        'shop_checkout','quote_accept','admin_quote','google_ads'
    ) NOT NULL DEFAULT 'other';

ALTER TABLE orders
    ADD COLUMN session_token VARCHAR(64) NULL AFTER customer_id,
    ADD INDEX idx_orders_session_token (session_token);
