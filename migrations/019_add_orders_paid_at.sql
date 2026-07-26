-- =============================================================================
-- Migration: 019_add_orders_paid_at.sql
-- Adds a dedicated paid_at timestamp to orders so the weekly sales report can
-- date revenue by the moment payment was actually recorded.
--
-- Why not updated_at: it moves whenever the owner edits the order's
-- fulfilment status (see 014_order_fulfillment.sql), so a report built on it
-- would silently re-date historical sales — last month's totals would change
-- every time someone touches an old order's status. That makes the report
-- non-reproducible, which is unacceptable for a financial report.
-- App\Models\Order::markPaid() now sets paid_at = NOW() at the moment
-- payment_status flips to 'paid', so it only ever reflects the payment event.
--
-- paid_at is nullable because unpaid orders have no payment time to record.
-- App\Support\WeeklySalesAggregator reads COALESCE(paid_at, created_at), so
-- the report works correctly both before and after this migration is applied
-- (falling back to created_at until paid_at is backfilled/populated).
--
-- The one-time backfill below sets paid_at = updated_at for the two existing
-- paid rows. This is safe specifically for those two rows: their updated_at
-- is within ~20 seconds of created_at (the Stripe checkout redirect that
-- marked them paid), well before either order was ever edited for
-- fulfilment. It is not a general substitute for paid_at going forward.
--
-- idx_orders_paid_at exists because the report filters/sorts on paid_at.
--
-- Run once only: ALTER TABLE will error if the column already exists.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE orders
    ADD COLUMN paid_at TIMESTAMP NULL DEFAULT NULL AFTER payment_status;

CREATE INDEX idx_orders_paid_at ON orders (paid_at);

UPDATE orders
   SET paid_at = updated_at
 WHERE payment_status = 'paid'
   AND paid_at IS NULL;
