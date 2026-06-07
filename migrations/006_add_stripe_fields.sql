SET NAMES utf8mb4;

-- Add Stripe payment tracking to quotes
ALTER TABLE quotes
    ADD COLUMN stripe_checkout_session_id VARCHAR(255) NULL
        COMMENT 'Stripe Checkout Session ID set when Pay by Card is initiated'
        AFTER deposit_confirmed_at,
    ADD COLUMN stripe_payment_intent_id VARCHAR(255) NULL
        COMMENT 'Stripe PaymentIntent ID set after successful card payment'
        AFTER stripe_checkout_session_id,
    ADD COLUMN payment_method ENUM('zelle','cashapp','stripe_full','other') NULL
        COMMENT 'Payment method used for the deposit/full payment'
        AFTER stripe_payment_intent_id,
    ADD INDEX idx_quotes_stripe_session (stripe_checkout_session_id);

-- Fix tax_rate precision: DECIMAL(5,4) silently rounds 0.08517 to 0.0852.
-- DECIMAL(6,5) stores five decimal places correctly.
ALTER TABLE quotes
    MODIFY COLUMN tax_rate DECIMAL(6,5) NULL;
