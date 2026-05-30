-- Fix: deposit_pct was in Quote::create() INSERT but never in the schema
ALTER TABLE quotes
    ADD COLUMN deposit_pct  TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER subtotal,
    ADD COLUMN tax_rate     DECIMAL(5,4) NULL AFTER deposit_pct,
    ADD COLUMN tax_amount   DECIMAL(10,2) NULL AFTER tax_rate;

ALTER TABLE orders
    ADD COLUMN sales_tax DECIMAL(8,2) NULL AFTER delivery_fee;
