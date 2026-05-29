<?php
/**
 * views/admin/customers/detail.php — Admin customer detail page.
 *
 * Rendered inside the 'admin' layout. Shows the customer info card, lifetime
 * spend, opt-in status, inline notes editing, and three history tables:
 * quotes, orders, and promotion signups.
 *
 * Variables available (injected by CustomersController::show()):
 *   array  $customer      Full customer row from the DB.
 *   array  $quotes        Quote rows for this customer.
 *   array  $orders        Order rows for this customer.
 *   array  $signups       Promotion signup rows for this customer.
 *   float  $vipThreshold  Minimum spend to qualify as a VIP.
 *   string $csrfToken     CSRF token for the notes form.
 *
 * @see \App\Controllers\Admin\CustomersController::show()
 */

$isVip = (float) ($customer['lifetime_spend'] ?? 0) >= $vipThreshold;

/** @var array<string,string> Source → badge style. */
$sourceBadgeStyle = [
    'order_form'    => 'background:#e3f2fd; color:#1565c0',
    'quote_form'    => 'background:#f3e5f5; color:#7b1fa2',
    'signup_widget' => 'background:#e8f5e9; color:#1b5e20',
    'manual'        => 'background:#f0f0f0; color:#555',
    'other'         => 'background:#fff8e1; color:#f57f17',
];
$srcStyle = $sourceBadgeStyle[$customer['source'] ?? 'other'] ?? $sourceBadgeStyle['other'];
?>

<!-- =========================================================
     BACK LINK + PAGE HEADER
     ========================================================= -->
<div style="margin-bottom:1.25rem">
    <a href="/admin/customers" style="font-size:0.85rem; color:#888; text-decoration:none">
        &larr; Back to Customers
    </a>
</div>

<!-- =========================================================
     FLASH MESSAGE
     ========================================================= -->
<?php if (!empty($_SESSION['flash'])): ?>
<div style="margin-bottom:1.5rem; padding:0.875rem 1.125rem; border-radius:6px;
    <?= $_SESSION['flash']['type'] === 'success'
        ? 'background:#e6f4ea; color:#2e7d32; border:1px solid #a8d5b1'
        : 'background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb' ?>">
    <?= htmlspecialchars($_SESSION['flash']['message']) ?>
</div>
<?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- =========================================================
     CUSTOMER INFO CARD
     ========================================================= -->
<div class="admin-card" style="margin-bottom:1.5rem">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem">

        <!-- Left: name, badges, contact -->
        <div>
            <div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.5rem">
                <h2 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem;
                           font-weight:600; color:#1a1a1a; margin:0">
                    <?= htmlspecialchars($customer['name'] ?? 'Unknown') ?>
                </h2>

                <?php if ($isVip): ?>
                <span style="display:inline-flex; align-items:center; gap:0.3rem;
                             padding:0.25rem 0.75rem; background:#d4a017; color:#fff;
                             border-radius:100px; font-size:0.7rem; font-weight:700;
                             font-family:'Montserrat',sans-serif; letter-spacing:0.05em;
                             text-transform:uppercase">
                    ★ VIP
                </span>
                <?php endif; ?>

                <span style="display:inline-block; padding:0.2rem 0.65rem; border-radius:100px;
                             font-size:0.68rem; font-weight:600; font-family:'Montserrat',sans-serif;
                             letter-spacing:0.04em; text-transform:uppercase; <?= $srcStyle ?>">
                    <?= htmlspecialchars(str_replace('_', ' ', $customer['source'] ?? 'other')) ?>
                </span>
            </div>

            <div style="font-size:0.9rem; color:#555; display:flex; flex-wrap:wrap; gap:1.25rem">
                <?php if (!empty($customer['email'])): ?>
                <span>
                    <span style="color:#999; font-size:0.78rem; font-family:'Montserrat',sans-serif;
                                 text-transform:uppercase; letter-spacing:0.07em">Email</span><br>
                    <a href="mailto:<?= htmlspecialchars($customer['email']) ?>"
                       style="color:#3182ce; text-decoration:underline">
                        <?= htmlspecialchars($customer['email']) ?>
                    </a>
                </span>
                <?php endif; ?>

                <?php if (!empty($customer['phone'])): ?>
                <span>
                    <span style="color:#999; font-size:0.78rem; font-family:'Montserrat',sans-serif;
                                 text-transform:uppercase; letter-spacing:0.07em">Phone</span><br>
                    <?= htmlspecialchars($customer['phone']) ?>
                </span>
                <?php endif; ?>

                <span>
                    <span style="color:#999; font-size:0.78rem; font-family:'Montserrat',sans-serif;
                                 text-transform:uppercase; letter-spacing:0.07em">Joined</span><br>
                    <?= !empty($customer['created_at'])
                        ? date('F j, Y', strtotime((string) $customer['created_at']))
                        : '—' ?>
                </span>
            </div>

            <!-- Opt-in status pills -->
            <div style="margin-top:1rem; display:flex; gap:0.5rem; flex-wrap:wrap">
                <span style="padding:0.2rem 0.75rem; border-radius:100px; font-size:0.72rem;
                             font-family:'Montserrat',sans-serif; font-weight:600; text-transform:uppercase;
                             letter-spacing:0.05em;
                             <?= $customer['opted_in_email']
                                 ? 'background:#e6f4ea; color:#2e7d32'
                                 : 'background:#f0f0f0; color:#aaa' ?>">
                    <?= $customer['opted_in_email'] ? '✓ Email Opt-In' : '✗ Email Opt-Out' ?>
                </span>
                <span style="padding:0.2rem 0.75rem; border-radius:100px; font-size:0.72rem;
                             font-family:'Montserrat',sans-serif; font-weight:600; text-transform:uppercase;
                             letter-spacing:0.05em;
                             <?= $customer['opted_in_sms']
                                 ? 'background:#e6f4ea; color:#2e7d32'
                                 : 'background:#f0f0f0; color:#aaa' ?>">
                    <?= $customer['opted_in_sms'] ? '✓ SMS Opt-In' : '✗ SMS Opt-Out' ?>
                </span>
            </div>
        </div>

        <!-- Right: lifetime spend (prominent) -->
        <div style="text-align:right">
            <div style="font-family:'Montserrat',sans-serif; font-size:0.68rem; text-transform:uppercase;
                        letter-spacing:0.1em; color:#999; margin-bottom:0.25rem">
                Lifetime Spend
            </div>
            <div style="font-family:'Cormorant Garamond',Georgia,serif; font-size:2.75rem;
                        font-weight:700; line-height:1; color:<?= $isVip ? '#d4a017' : 'var(--color-primary)' ?>">
                $<?= number_format((float) ($customer['lifetime_spend'] ?? 0), 2) ?>
            </div>
        </div>

    </div><!-- /flex row -->

    <!-- Divider -->
    <hr style="border:none; border-top:1px solid #f0f0f0; margin:1.5rem 0">

    <!-- Edit notes -->
    <div>
        <div style="font-family:'Montserrat',sans-serif; font-size:0.72rem; text-transform:uppercase;
                    letter-spacing:0.08em; color:#888; margin-bottom:0.5rem; font-weight:600">
            Notes
        </div>
        <form method="POST" action="/admin/customers/<?= (int) $customer['id'] ?>/notes">
            <textarea name="notes" rows="3"
                      style="width:100%; padding:0.75rem; border:1.5px solid #dde1e7; border-radius:4px;
                             font-family:'Lato',sans-serif; font-size:0.9rem; color:#333; resize:vertical"
                      placeholder="Internal notes about this customer…"><?= htmlspecialchars($customer['notes'] ?? '') ?></textarea>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
            <button type="submit" class="admin-btn admin-btn-primary" style="margin-top:0.5rem; font-size:0.72rem">
                Save Notes
            </button>
        </form>
    </div>

</div><!-- /.admin-card customer info -->

<!-- =========================================================
     QUOTES HISTORY
     ========================================================= -->
<div class="admin-card" style="margin-bottom:1.5rem">
    <div class="admin-card-header">
        <span class="admin-card-title">Quotes (<?= count($quotes) ?>)</span>
    </div>

    <?php if (empty($quotes)): ?>
    <p style="color:#999; font-size:0.9rem; padding:0.5rem 0">No quotes on file.</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Status</th>
                    <th>Subtotal</th>
                    <th>Deposit</th>
                    <th>Event Date</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotes as $q): ?>
                <tr>
                    <td>#<?= (int) $q['id'] ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($q['status']) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $q['status'])) ?>
                        </span>
                    </td>
                    <td>$<?= number_format((float) ($q['subtotal'] ?? 0), 2) ?></td>
                    <td>$<?= number_format((float) ($q['deposit_amount'] ?? 0), 2) ?></td>
                    <td style="white-space:nowrap">
                        <?= !empty($q['event_date'])
                            ? date('M j, Y', strtotime((string) $q['event_date']))
                            : '—' ?>
                    </td>
                    <td style="white-space:nowrap; color:#888; font-size:0.85rem">
                        <?= !empty($q['created_at'])
                            ? date('M j, Y', strtotime((string) $q['created_at']))
                            : '—' ?>
                    </td>
                    <td>
                        <a href="/admin/quotes/<?= (int) $q['id'] ?>"
                           class="admin-btn admin-btn-ghost"
                           style="padding:0.35rem 0.75rem; font-size:0.7rem">
                            View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div><!-- /.admin-card quotes -->

<!-- =========================================================
     ORDERS HISTORY
     ========================================================= -->
<div class="admin-card" style="margin-bottom:1.5rem">
    <div class="admin-card-header">
        <span class="admin-card-title">Orders (<?= count($orders) ?>)</span>
    </div>

    <?php if (empty($orders)): ?>
    <p style="color:#999; font-size:0.9rem; padding:0.5rem 0">No orders on file.</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Status</th>
                    <th>Event Date</th>
                    <th>Occasion</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td>#<?= (int) $o['id'] ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($o['status'] ?? 'pending') ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $o['status'] ?? '')) ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap">
                        <?= !empty($o['event_date'])
                            ? date('M j, Y', strtotime((string) $o['event_date']))
                            : '—' ?>
                    </td>
                    <td><?= htmlspecialchars($o['occasion'] ?? '—') ?></td>
                    <td style="white-space:nowrap; color:#888; font-size:0.85rem">
                        <?= !empty($o['created_at'])
                            ? date('M j, Y', strtotime((string) $o['created_at']))
                            : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div><!-- /.admin-card orders -->

<!-- =========================================================
     PROMOTION SIGNUPS
     ========================================================= -->
<div class="admin-card">
    <div class="admin-card-header">
        <span class="admin-card-title">Promotion Signups (<?= count($signups) ?>)</span>
    </div>

    <?php if (empty($signups)): ?>
    <p style="color:#999; font-size:0.9rem; padding:0.5rem 0">No promotion signups on file.</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th style="text-align:center">Email Opt-In</th>
                    <th style="text-align:center">SMS Opt-In</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($signups as $s): ?>
                <tr>
                    <td style="white-space:nowrap; color:#888; font-size:0.85rem">
                        <?= !empty($s['created_at'])
                            ? date('M j, Y', strtotime((string) $s['created_at']))
                            : '—' ?>
                    </td>
                    <td style="font-size:0.875rem; color:#555">
                        <?= htmlspecialchars($s['email'] ?? '—') ?>
                    </td>
                    <td style="font-size:0.875rem; color:#555">
                        <?= htmlspecialchars($s['phone'] ?? '—') ?>
                    </td>
                    <td style="text-align:center; color:<?= $s['opted_in_email'] ? '#2e7d32' : '#ccc' ?>">
                        <?= $s['opted_in_email'] ? '✓' : '✗' ?>
                    </td>
                    <td style="text-align:center; color:<?= $s['opted_in_sms'] ? '#2e7d32' : '#ccc' ?>">
                        <?= $s['opted_in_sms'] ? '✓' : '✗' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div><!-- /.admin-card signups -->
