<?php
/**
 * views/admin/dashboard.php — Admin dashboard overview.
 *
 * Rendered inside the 'admin' layout (sidebar + topbar). Shows four KPI stat
 * cards, a recent-quotes activity table, and an upcoming-events table when
 * there are events within the next 30 days.
 *
 * Variables available:
 *   int    $openQuotes          Quotes with status draft, sent, or accepted.
 *   int    $pendingDeposits     Quotes with status deposit_confirmed.
 *   int    $customersThisMonth  New customers registered this calendar month.
 *   int    $totalCustomers      All-time customer count.
 *   float  $revenueThisMonth    Sum of subtotal for confirmed/completed quotes this month.
 *   array  $recentQuotes        Last 10 quotes: id, status, subtotal, deposit_amount, created_at, customer_name.
 *   array  $upcomingEvents      Quotes with event_date in next 30 days: id, status, subtotal, event_date, customer_name.
 *
 * @see \App\Controllers\Admin\DashboardController::index()
 */
?>

<!-- KPI stat cards -->
<div class="stat-grid">

    <div class="stat-card">
        <div class="stat-value"><?= (int) $openQuotes ?></div>
        <div class="stat-label">Open Quotes</div>
    </div>

    <div class="stat-card" style="border-left-color:var(--color-accent)">
        <div class="stat-value" style="color:var(--color-accent)"><?= (int) $pendingDeposits ?></div>
        <div class="stat-label">Pending Deposits</div>
    </div>

    <div class="stat-card" style="border-left-color:#38a169">
        <div class="stat-value" style="color:#38a169">$<?= number_format((float) $revenueThisMonth, 0) ?></div>
        <div class="stat-label">Revenue This Month</div>
    </div>

    <div class="stat-card" style="border-left-color:#3182ce">
        <div class="stat-value" style="color:#3182ce"><?= (int) $totalCustomers ?></div>
        <div class="stat-label">Total Customers</div>
    </div>

</div><!-- /.stat-grid -->

<!-- Recent quotes activity table -->
<div class="admin-card">
    <div class="admin-card-header">
        <span class="admin-card-title">Recent Quotes</span>
        <a href="/admin/quotes/new" class="admin-btn admin-btn-primary">+ New Quote</a>
    </div>

    <?php if (empty($recentQuotes)): ?>
    <p style="color:#999; font-size:0.9rem; padding:1rem 0">No quotes yet.</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>Deposit</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentQuotes as $q): ?>
                <tr>
                    <td>#<?= (int) $q['id'] ?></td>
                    <td><?= htmlspecialchars($q['customer_name'] ?? '—') ?></td>
                    <td>$<?= number_format((float) $q['subtotal'], 2) ?></td>
                    <td>$<?= number_format((float) $q['deposit_amount'], 2) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($q['status']) ?>">
                            <?= htmlspecialchars($q['status']) ?>
                        </span>
                    </td>
                    <td><?= date('M j', strtotime((string) $q['created_at'])) ?></td>
                    <td>
                        <a href="/admin/quotes/<?= (int) $q['id'] ?>" class="admin-btn admin-btn-ghost" style="font-size:0.75rem">
                            View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div><!-- /.admin-card recent quotes -->

<!-- Upcoming events — only rendered when there is at least one. -->
<?php if (!empty($upcomingEvents)): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <span class="admin-card-title">Upcoming Events (Next 30 Days)</span>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Event Date</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcomingEvents as $e): ?>
                <tr>
                    <td><?= htmlspecialchars($e['customer_name'] ?? '—') ?></td>
                    <td><?= date('M j, Y', strtotime((string) $e['event_date'])) ?></td>
                    <td>$<?= number_format((float) $e['subtotal'], 2) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($e['status']) ?>">
                            <?= htmlspecialchars($e['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div><!-- /.admin-card upcoming events -->
<?php endif; ?>
