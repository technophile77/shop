<?php
/**
 * views/admin/orders/list.php — Admin shop-order list with status/payment filters.
 *
 * Variables injected by OrdersController::index():
 *   array  $orders        Shop-order rows (possibly pre-filtered), each with customer_name.
 *   string $statusFilter  Active fulfillment-status filter value, or '' for All.
 *   string $paymentFilter Active payment-status filter value, or '' for All.
 *   string $pageTitle     Page title string.
 *
 * @see \App\Controllers\Admin\OrdersController::index()
 * @see \App\Support\ShopOrderSnapshot::summaryLabel()
 */

/** @var array<int, array<string, mixed>> $orders */
/** @var string $statusFilter */
/** @var string $paymentFilter */

$allStatuses    = \App\Models\Order::STATUSES;
$paymentOptions = ['paid', 'unpaid'];

/**
 * Emit a CSS badge class suffix for a given fulfillment status.
 *
 * @param string $status One of the Order::STATUSES values.
 * @return string CSS class suffix used by the badge component.
 */
$badgeClass = static function (string $status): string {
    return match ($status) {
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
 * Emit a CSS badge class suffix for a given payment status.
 *
 * @param string $payment 'paid' or 'unpaid'.
 * @return string CSS class suffix used by the badge component.
 */
$paymentBadge = static function (string $payment): string {
    return $payment === 'paid' ? 'success' : 'warning';
};
?>

<!-- Fulfillment status filter tabs -->
<div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.75rem">
    <a href="/admin/orders<?= $paymentFilter !== '' ? '?payment=' . urlencode($paymentFilter) : '' ?>"
       class="btn btn-sm <?= !$statusFilter ? 'btn-accent' : 'btn-outline' ?>">
        All
    </a>
    <?php foreach ($allStatuses as $s): ?>
    <a href="/admin/orders?status=<?= urlencode($s) ?><?= $paymentFilter !== '' ? '&payment=' . urlencode($paymentFilter) : '' ?>"
       class="btn btn-sm <?= $statusFilter === $s ? 'btn-accent' : 'btn-outline' ?>">
        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $s))) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Payment status filter tabs -->
<div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.5rem; align-items:center">
    <span style="font-size:0.8rem; color:var(--color-muted); margin-right:0.25rem">Payment:</span>
    <a href="/admin/orders<?= $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : '' ?>"
       class="btn btn-sm <?= !$paymentFilter ? 'btn-accent' : 'btn-outline' ?>">
        All
    </a>
    <?php foreach ($paymentOptions as $p): ?>
    <a href="/admin/orders?payment=<?= urlencode($p) ?><?= $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '' ?>"
       class="btn btn-sm <?= $paymentFilter === $p ? 'btn-accent' : 'btn-outline' ?>">
        <?= htmlspecialchars(ucfirst($p)) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Flash -->
<?php if (!empty($_SESSION['flash'])): ?>
<div style="margin-bottom:1rem; padding:0.75rem 1.125rem; border-radius:6px;
    <?= $_SESSION['flash']['type'] === 'success'
        ? 'background:#e6f4ea; color:#2e7d32; border:1px solid #a8d5b1'
        : 'background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb' ?>">
    <?= htmlspecialchars((string) $_SESSION['flash']['message']) ?>
</div>
<?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- Orders table -->
<div class="admin-card" style="padding:0">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="padding-left:1.5rem; width:60px">#</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th style="text-align:right">Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Placed</th>
                    <th style="padding-right:1.5rem">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <?php
                    $decoded = json_decode((string) ($o['items_json'] ?? '[]'), true);
                    $decoded = is_array($decoded) ? $decoded : [];
                ?>
                <tr>
                    <td style="padding-left:1.5rem; color:var(--color-muted); font-size:0.875rem">
                        <?= (int) $o['id'] ?>
                    </td>
                    <td>
                        <strong style="color:#1a1a1a">
                            <?= htmlspecialchars($o['customer_name'] ?? '(no customer)') ?>
                        </strong>
                    </td>
                    <td style="color:var(--color-muted); font-size:0.875rem">
                        <?= htmlspecialchars(\App\Support\ShopOrderSnapshot::summaryLabel($decoded)) ?>
                    </td>
                    <td style="text-align:right; font-weight:500">
                        $<?= number_format((float) ($o['total'] ?? 0), 2) ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $paymentBadge((string) ($o['payment_status'] ?? 'unpaid')) ?>">
                            <?= htmlspecialchars(ucfirst((string) ($o['payment_status'] ?? 'unpaid'))) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-<?= $badgeClass((string) ($o['status'] ?? 'pending')) ?>">
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($o['status'] ?? 'pending')))) ?>
                        </span>
                    </td>
                    <td style="color:var(--color-muted); font-size:0.875rem">
                        <?= htmlspecialchars(date('M j, Y', strtotime((string) $o['created_at']))) ?>
                    </td>
                    <td style="padding-right:1.5rem; white-space:nowrap">
                        <a href="/admin/orders/<?= (int) $o['id'] ?>"
                           class="admin-btn admin-btn-ghost"
                           style="padding:0.4rem 0.875rem; font-size:0.7rem">
                            View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if ($orders === []): ?>
                <tr>
                    <td colspan="8"
                        style="text-align:center; padding:3rem 1.5rem; color:var(--color-muted)">
                        <?php if ($statusFilter !== '' || $paymentFilter !== ''): ?>
                            No orders match these filters.
                        <?php else: ?>
                            No online orders yet. Orders placed through the shop checkout will appear here.
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
