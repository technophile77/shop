<?php
/**
 * views/admin/orders/detail.php — Admin shop-order detail page.
 *
 * Variables injected by OrdersController::show():
 *   array<string,mixed> $order     Full order row joined with customer columns.
 *   array<int,array>    $items     Decoded items_json snapshot (see ShopOrderSnapshot).
 *   string              $csrfToken CSRF token for the status-change form.
 *   string              $pageTitle Page title string.
 *
 * @see \App\Controllers\Admin\OrdersController::show()
 * @see \App\Controllers\Admin\OrdersController::updateStatus()
 * @see \App\Models\Order::STATUSES
 */

/** @var array<string, mixed> $order */
/** @var array<int, array<string, mixed>> $items */
/** @var string $csrfToken */

$id            = (int) $order['id'];
$status        = (string) ($order['status'] ?? 'pending');
$paymentStatus = (string) ($order['payment_status'] ?? 'unpaid');
$isPickup      = ($order['delivery_type'] ?? 'delivery') === 'pickup';

$subtotal  = (float) ($order['subtotal'] ?? 0);
$taxAmount = (float) ($order['tax_amount'] ?? 0);
$deliveryFee = $order['delivery_fee'] !== null ? (float) $order['delivery_fee'] : 0.0;
$total     = (float) ($order['total'] ?? 0);

$allStatuses = \App\Models\Order::STATUSES;

/**
 * Map a fulfillment status to its badge CSS suffix.
 *
 * @param string $s Order status.
 * @return string Badge class suffix.
 */
$badgeClass = static function (string $s): string {
    return match ($s) {
        'pending'     => 'draft',
        'in_progress' => 'info',
        'ready'       => 'warning',
        'delivered'   => 'success',
        'completed'   => 'active',
        'cancelled'   => 'inactive',
        default       => 'draft',
    };
};

/**
 * Human-readable label for a status slug.
 *
 * @param string $s Status slug.
 * @return string Display label.
 */
$statusLabel = static function (string $s): string {
    return ucwords(str_replace('_', ' ', $s));
};

/**
 * Format the requested fulfillment date/time stored as 'Y-m-d H:i:s'.
 *
 * @param mixed $raw The fulfill_at column value.
 * @return string A friendly label, or '' when unparseable/absent.
 */
$formatFulfillAt = static function ($raw): string {
    $raw = (string) $raw;
    if ($raw === '') {
        return '';
    }
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
    return $dt !== false ? $dt->format('D M j, Y \a\t g:i A') : $raw;
};

$fulfillAt = $formatFulfillAt($order['fulfill_at'] ?? '');
?>

<?php if (!empty($_SESSION['flash'])): ?>
<div style="margin-bottom:1.5rem; padding:0.875rem 1.125rem; border-radius:6px;
    <?= $_SESSION['flash']['type'] === 'success'
        ? 'background:#e6f4ea; color:#2e7d32; border:1px solid #a8d5b1'
        : 'background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb' ?>">
    <?= htmlspecialchars((string) $_SESSION['flash']['message']) ?>
</div>
<?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- Back link -->
<div style="margin-bottom:1.25rem">
    <a href="/admin/orders" style="color:var(--color-muted); font-size:0.875rem; text-decoration:none">
        &larr; All Orders
    </a>
</div>

<!-- ============================================================
     Order header
     ============================================================ -->
<div class="admin-card" style="margin-bottom:1.5rem">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem">
        <div>
            <h2 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; margin:0 0 0.5rem">
                Order #<?= $id ?>
                <span class="badge badge-<?= $badgeClass($status) ?>" style="font-size:0.75rem; vertical-align:middle; margin-left:0.5rem">
                    <?= htmlspecialchars($statusLabel($status)) ?>
                </span>
                <span class="badge badge-<?= $paymentStatus === 'paid' ? 'success' : 'warning' ?>" style="font-size:0.75rem; vertical-align:middle; margin-left:0.25rem">
                    <?= htmlspecialchars(ucfirst($paymentStatus)) ?>
                </span>
            </h2>
            <p style="color:var(--color-muted); margin:0; font-size:0.9rem">
                Customer:
                <strong style="color:var(--color-text-dark)">
                    <?= htmlspecialchars($order['customer_name'] ?? '(no customer)') ?>
                </strong>
                &nbsp;&bull;&nbsp;
                Placed: <?= htmlspecialchars(date('F j, Y \a\t g:i a', strtotime((string) $order['created_at']))) ?>
            </p>
        </div>
        <div style="text-align:right">
            <div style="font-size:1.5rem; font-weight:700; color:var(--color-text-dark)">
                $<?= number_format($total, 2) ?>
            </div>
            <div style="font-size:0.875rem; color:var(--color-muted)">
                <?= $isPickup ? 'Pickup' : 'Delivery' ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     Two-column layout: items + sidebar
     ============================================================ -->
<div style="display:grid; grid-template-columns:1fr 320px; gap:1.5rem; align-items:start">

    <!-- Left column: items -->
    <div>

        <!-- Line items -->
        <div class="admin-card" style="padding:0; margin-bottom:1.5rem">
            <div class="admin-card-header" style="padding:1rem 1.5rem">
                <span class="admin-card-title">Items</span>
            </div>
            <table class="admin-table" style="margin-bottom:0">
                <thead>
                    <tr>
                        <th style="padding-left:1.5rem">Product</th>
                        <th style="text-align:center; width:70px">Qty</th>
                        <th style="text-align:right; width:120px">Unit Price</th>
                        <th style="text-align:right; width:120px; padding-right:1.5rem">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td style="padding-left:1.5rem">
                            <strong><?= htmlspecialchars((string) ($item['name'] ?? $item['name_en'] ?? 'Item')) ?></strong>

                            <?php
                            // Flower-type / color selections.
                            $colorLines = [];
                            foreach ((array) ($item['colors'] ?? []) as $sel) {
                                $typeName = $sel['flower_type_name'] ?? null;
                                $names    = array_values(array_filter(
                                    (array) ($sel['color_names'] ?? []),
                                    static fn ($n): bool => $n !== null && $n !== ''
                                ));
                                $piece = $typeName !== null ? (string) $typeName : 'Flowers';
                                if (!empty($sel['mixed'])) {
                                    $piece .= ' (mixed)';
                                } elseif ($names !== []) {
                                    $piece .= ': ' . implode(', ', array_map('strval', $names));
                                }
                                $colorLines[] = $piece;
                            }
                            ?>
                            <?php foreach ($colorLines as $line): ?>
                            <div style="font-size:0.8rem; color:var(--color-muted)"><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>

                            <?php if (!empty($item['paper_color_name'])): ?>
                            <div style="font-size:0.8rem; color:var(--color-muted)">Paper: <?= htmlspecialchars((string) $item['paper_color_name']) ?></div>
                            <?php endif; ?>

                            <?php foreach ((array) ($item['addons'] ?? []) as $addon): ?>
                            <div style="font-size:0.8rem; color:var(--color-muted)">
                                + <?= htmlspecialchars((string) ($addon['name'] ?? $addon['name_en'] ?? 'Add-on')) ?>
                                <?php if ((int) ($addon['quantity'] ?? 1) > 1): ?>&times;<?= (int) $addon['quantity'] ?><?php endif; ?>
                                <?php if (!empty($addon['custom_text'])): ?>
                                — &ldquo;<?= htmlspecialchars((string) $addon['custom_text']) ?>&rdquo;
                                <?php endif; ?>
                                <?php if ((float) ($addon['price'] ?? 0) > 0): ?>
                                <span style="color:var(--color-muted)">($<?= number_format((float) $addon['price'], 2) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </td>
                        <td style="text-align:center">
                            <?= (int) ($item['qty'] ?? 1) ?>
                        </td>
                        <td style="text-align:right">
                            $<?= number_format((float) ($item['unit_price'] ?? 0), 2) ?>
                        </td>
                        <td style="text-align:right; padding-right:1.5rem; font-weight:500">
                            $<?= number_format((float) ($item['line_total'] ?? 0), 2) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if ($items === []): ?>
                    <tr>
                        <td colspan="4" style="text-align:center; padding:2rem 1.5rem; color:var(--color-muted)">
                            No item snapshot recorded for this order.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:right; padding:0.75rem 1rem; border-top:1px solid var(--color-border); color:var(--color-muted)">
                            Subtotal
                        </td>
                        <td style="text-align:right; padding:0.75rem 1.5rem; border-top:1px solid var(--color-border); font-weight:600">
                            $<?= number_format($subtotal, 2) ?>
                        </td>
                    </tr>
                    <?php if ($deliveryFee > 0): ?>
                    <tr>
                        <td colspan="3" style="text-align:right; padding:0.5rem 1rem; color:var(--color-muted)">
                            Delivery fee
                        </td>
                        <td style="text-align:right; padding:0.5rem 1.5rem; font-weight:500">
                            $<?= number_format($deliveryFee, 2) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($taxAmount > 0): ?>
                    <tr>
                        <td colspan="3" style="text-align:right; padding:0.5rem 1rem; color:var(--color-muted)">
                            Tax
                        </td>
                        <td style="text-align:right; padding:0.5rem 1.5rem; font-weight:500">
                            $<?= number_format($taxAmount, 2) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td colspan="3" style="text-align:right; padding:0.5rem 1rem; font-weight:700">
                            Total
                        </td>
                        <td style="text-align:right; padding:0.5rem 1.5rem; font-weight:700; font-size:1.05rem">
                            $<?= number_format($total, 2) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Card message -->
        <?php if (!empty($order['card_message'])): ?>
        <div class="admin-card" style="margin-bottom:1.5rem">
            <div class="admin-card-header">
                <span class="admin-card-title">Gift Card Message</span>
            </div>
            <p style="margin:0; white-space:pre-wrap; color:var(--color-text-dark); font-size:0.9375rem; line-height:1.6; font-style:italic">
                &ldquo;<?= htmlspecialchars((string) $order['card_message']) ?>&rdquo;
            </p>
        </div>
        <?php endif; ?>

    </div><!-- /left column -->

    <!-- Right column: fulfillment, payment, status, customer -->
    <div>

        <!-- Fulfillment -->
        <div class="admin-card" style="margin-bottom:1.5rem">
            <div class="admin-card-header">
                <span class="admin-card-title"><?= $isPickup ? 'Pickup' : 'Delivery' ?></span>
            </div>
            <div style="display:flex; flex-direction:column; gap:0.5rem; font-size:0.9rem">
                <?php if ($fulfillAt !== ''): ?>
                <div>
                    <span style="color:var(--color-muted)"><?= $isPickup ? 'Pickup time' : 'Delivery time' ?>:</span><br>
                    <strong><?= htmlspecialchars($fulfillAt) ?></strong>
                </div>
                <?php endif; ?>
                <?php if (!$isPickup && !empty($order['delivery_venue_name'])): ?>
                <div>
                    <span style="color:var(--color-muted)">Venue:</span>
                    <?= htmlspecialchars((string) $order['delivery_venue_name']) ?>
                </div>
                <?php endif; ?>
                <?php if (!$isPickup && !empty($order['delivery_address'])): ?>
                <div>
                    <span style="color:var(--color-muted)">Address:</span><br>
                    <?= nl2br(htmlspecialchars((string) $order['delivery_address'])) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($order['occasion_type']) || !empty($order['occasion'])): ?>
                <div>
                    <span style="color:var(--color-muted)">Occasion:</span>
                    <?= htmlspecialchars((string) ($order['occasion_type'] ?? $order['occasion'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Update status -->
        <div class="admin-card" style="margin-bottom:1.5rem">
            <div class="admin-card-header">
                <span class="admin-card-title">Update Status</span>
            </div>
            <form method="POST" action="/admin/orders/<?= $id ?>/status">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="admin-form-group" style="margin-bottom:0.75rem">
                    <select name="new_status" style="width:100%">
                        <?php foreach ($allStatuses as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $s === $status ? 'selected' : '' ?>>
                            <?= htmlspecialchars($statusLabel($s)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-accent" style="width:100%">
                    Save Status
                </button>
            </form>
        </div>

        <!-- Payment -->
        <div class="admin-card" style="margin-bottom:1.5rem">
            <div class="admin-card-header">
                <span class="admin-card-title">Payment</span>
                <span class="badge badge-<?= $paymentStatus === 'paid' ? 'success' : 'warning' ?>">
                    <?= htmlspecialchars(ucfirst($paymentStatus)) ?>
                </span>
            </div>
            <div style="display:flex; flex-direction:column; gap:0.5rem; font-size:0.8125rem; color:var(--color-muted); word-break:break-all">
                <?php if (!empty($order['stripe_payment_intent_id'])): ?>
                <div>
                    Payment Intent:<br>
                    <code style="font-family:Montserrat,monospace"><?= htmlspecialchars((string) $order['stripe_payment_intent_id']) ?></code>
                </div>
                <?php endif; ?>
                <?php if (!empty($order['stripe_checkout_session_id'])): ?>
                <div>
                    Checkout Session:<br>
                    <code style="font-family:Montserrat,monospace"><?= htmlspecialchars((string) $order['stripe_checkout_session_id']) ?></code>
                </div>
                <?php endif; ?>
                <?php if (empty($order['stripe_payment_intent_id']) && empty($order['stripe_checkout_session_id'])): ?>
                <div>No Stripe references recorded.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Customer -->
        <?php
        $customerId    = (int) ($order['customer_id'] ?? 0);
        $customerName  = (string) ($order['customer_name']  ?? '');
        $customerEmail = (string) ($order['customer_email'] ?? '');
        $customerPhone = (string) ($order['customer_phone'] ?? '');
        ?>
        <div class="admin-card">
            <div class="admin-card-header">
                <span class="admin-card-title">Customer</span>
                <?php if ($customerId > 0): ?>
                <a href="/admin/customers/<?= $customerId ?>"
                   class="btn btn-sm btn-outline" style="font-size:0.75rem">
                    View Profile
                </a>
                <?php endif; ?>
            </div>

            <?php if ($customerId > 0 || $customerName !== ''): ?>
            <div style="display:flex; flex-direction:column; gap:0.5rem; font-size:0.9rem">
                <?php if ($customerName !== ''): ?>
                <div><strong><?= htmlspecialchars($customerName) ?></strong></div>
                <?php endif; ?>
                <?php if ($customerEmail !== ''): ?>
                <div style="color:var(--color-muted)">
                    <a href="mailto:<?= htmlspecialchars($customerEmail) ?>" style="color:var(--color-primary)">
                        <?= htmlspecialchars($customerEmail) ?>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($customerPhone !== ''): ?>
                <div style="color:var(--color-muted)">
                    <a href="tel:<?= htmlspecialchars($customerPhone) ?>" style="color:var(--color-primary)">
                        <?= htmlspecialchars($customerPhone) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <p style="color:var(--color-muted); margin:0; font-size:0.875rem">No customer linked to this order.</p>
            <?php endif; ?>
        </div>

    </div><!-- /right column -->

</div><!-- /grid -->
