<?php
/**
 * views/admin/quotes/list.php — Admin quote list with status filter tabs.
 *
 * Variables injected by QuotesController::index():
 *   array  $quotes        All quote rows (possibly pre-filtered), each with customer_name.
 *   string $statusFilter  Active status filter value, or '' for All.
 *   string $pageTitle     Page title string.
 *
 * @see \App\Controllers\Admin\QuotesController::index()
 */

/** @var array<int, array<string, mixed>> $quotes */
/** @var string $statusFilter */

$allStatuses = \App\Models\Quote::STATUSES;
$appUrl      = \App\Core\Config::get('APP_URL', '');

/**
 * Emit a CSS badge class appropriate for a given quote status.
 *
 * @param string $status One of the Quote::STATUSES values.
 * @return string CSS class suffix used by the badge component.
 */
$badgeClass = static function (string $status): string {
    return match ($status) {
        'draft'             => 'draft',
        'sent'              => 'info',
        'accepted'          => 'warning',
        'deposit_confirmed' => 'success',
        'completed'         => 'active',
        'cancelled'         => 'inactive',
        default             => 'draft',
    };
};
?>

<!-- Status filter tabs -->
<div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.5rem">
    <a href="/admin/quotes"
       class="btn btn-sm <?= !$statusFilter ? 'btn-accent' : 'btn-outline' ?>">
        All
    </a>
    <?php foreach ($allStatuses as $s): ?>
    <a href="/admin/quotes?status=<?= urlencode($s) ?>"
       class="btn btn-sm <?= $statusFilter === $s ? 'btn-accent' : 'btn-outline' ?>">
        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $s))) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Toolbar: flash + new-quote button -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem">
    <div>
        <?php if (!empty($_SESSION['flash'])): ?>
        <div style="padding:0.75rem 1.125rem; border-radius:6px;
            <?= $_SESSION['flash']['type'] === 'success'
                ? 'background:#e6f4ea; color:#2e7d32; border:1px solid #a8d5b1'
                : 'background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb' ?>">
            <?= htmlspecialchars((string) $_SESSION['flash']['message']) ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>
    </div>
    <a href="/admin/quotes/new" class="btn btn-accent">+ New Quote</a>
</div>

<!-- Quotes table -->
<div class="admin-card" style="padding:0">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="padding-left:1.5rem; width:60px">#</th>
                    <th>Customer</th>
                    <th style="text-align:right">Amount</th>
                    <th style="text-align:right">Deposit</th>
                    <th>Status</th>
                    <th>Event Date</th>
                    <th>Created</th>
                    <th style="padding-right:1.5rem">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotes as $q): ?>
                <tr>
                    <td style="padding-left:1.5rem; color:var(--color-muted); font-size:0.875rem">
                        <?= (int) $q['id'] ?>
                    </td>
                    <td>
                        <strong style="color:#1a1a1a">
                            <?= htmlspecialchars($q['customer_name'] ?? '(no customer)') ?>
                        </strong>
                    </td>
                    <td style="text-align:right; font-weight:500">
                        $<?= number_format((float) $q['subtotal'], 2) ?>
                    </td>
                    <td style="text-align:right; color:var(--color-muted)">
                        $<?= number_format((float) $q['deposit_amount'], 2) ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $badgeClass((string) $q['status']) ?>">
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $q['status']))) ?>
                        </span>
                    </td>
                    <td style="color:var(--color-muted); font-size:0.875rem">
                        <?= $q['event_date'] ? htmlspecialchars((string) $q['event_date']) : '—' ?>
                    </td>
                    <td style="color:var(--color-muted); font-size:0.875rem">
                        <?= htmlspecialchars(date('M j, Y', strtotime((string) $q['created_at']))) ?>
                    </td>
                    <td style="padding-right:1.5rem; white-space:nowrap">
                        <a href="/admin/quotes/<?= (int) $q['id'] ?>"
                           class="admin-btn admin-btn-ghost"
                           style="padding:0.4rem 0.875rem; font-size:0.7rem; margin-right:0.375rem">
                            View
                        </a>
                        <button class="btn btn-sm btn-outline"
                                onclick="navigator.clipboard.writeText('<?= htmlspecialchars($appUrl . '/quote/' . $q['token']) ?>').then(function(){ this.textContent = 'Copied!'; }.bind(this))">
                            Copy Link
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if ($quotes === []): ?>
                <tr>
                    <td colspan="8"
                        style="text-align:center; padding:3rem 1.5rem; color:var(--color-muted)">
                        <?php if ($statusFilter !== ''): ?>
                            No quotes with status "<?= htmlspecialchars(ucwords(str_replace('_', ' ', $statusFilter))) ?>".
                        <?php else: ?>
                            No quotes yet.
                            <a href="/admin/quotes/new"
                               style="color:var(--color-primary); text-decoration:underline">
                                Create your first quote.
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
