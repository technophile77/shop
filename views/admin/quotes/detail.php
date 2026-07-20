<?php
/**
 * views/admin/quotes/detail.php — Admin quote detail page.
 *
 * Variables injected by QuotesController::show():
 *   array<string,mixed> $quote     Full quote row joined with customer columns.
 *   array<int,array>    $items     Decoded line items (description, qty, unit_price).
 *   string              $quoteUrl  Absolute public share URL.
 *   string              $csrfToken CSRF token for status-transition forms.
 *   string              $pageTitle Page title string.
 *
 * @see \App\Controllers\Admin\QuotesController::show()
 * @see \App\Controllers\Admin\QuotesController::updateStatus()
 * @see \App\Models\Quote::STATUSES
 */

/** @var array<string, mixed> $quote */
/** @var array<int, array<string, mixed>> $items */
/** @var string $quoteUrl */
/** @var string $csrfToken */

$id         = (int) $quote['id'];
$status     = (string) $quote['status'];
$subtotal   = (float) $quote['subtotal'];
$deposit    = (float) $quote['deposit_amount'];
$depositPct = (int)   $quote['deposit_pct'];

$taxRate   = (float) ($quote['tax_rate']   ?? 0.0);
$taxAmount = (float) ($quote['tax_amount'] ?? 0.0);
$total     = $subtotal + $taxAmount;

// Deposit breakdown: items flagged "full deposit" are charged in full; the rest
// at deposit_pct. $fullDepositTotal is the paid-in-full portion of the deposit.
$fullDepositTotal = 0.0;
foreach ($items as $itm) {
    if (!empty($itm['full_deposit'])) {
        $fullDepositTotal += (float) $itm['unit_price'] * (int) $itm['qty'];
    }
}
$depositRemainingBase = $subtotal - $fullDepositTotal;
$hasFullDepositItems  = $fullDepositTotal > 0;

$orderedStatuses = ['draft', 'sent', 'accepted', 'deposit_confirmed', 'completed'];

/**
 * Map a status string to its badge CSS suffix.
 *
 * @param string $s Quote status.
 * @return string Badge class suffix.
 */
$badgeClass = static function (string $s): string {
    return match ($s) {
        'draft'             => 'draft',
        'sent'              => 'info',
        'accepted'          => 'warning',
        'deposit_confirmed' => 'success',
        'completed'         => 'active',
        'cancelled'         => 'inactive',
        default             => 'draft',
    };
};

/**
 * Return a human-readable label for a status slug.
 *
 * @param string $s Quote status slug.
 * @return string Display label.
 */
$statusLabel = static function (string $s): string {
    return ucwords(str_replace('_', ' ', $s));
};
?>

<?php if (!empty($_SESSION['flash'])): ?>
<?php
    $flashStyle = match ($_SESSION['flash']['type']) {
        'success' => 'background:#e6f4ea; color:#2e7d32; border:1px solid #a8d5b1',
        'warning' => 'background:#fff4e5; color:#8a5300; border:1px solid #ffcc80',
        default   => 'background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb',
    };
?>
<div style="margin-bottom:1.5rem; padding:0.875rem 1.125rem; border-radius:6px; <?= $flashStyle ?>">
    <?= htmlspecialchars((string) $_SESSION['flash']['message']) ?>
</div>
<?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- Back link -->
<div style="margin-bottom:1.25rem">
    <a href="/admin/quotes" style="color:var(--color-muted); font-size:0.875rem; text-decoration:none">
        &larr; All Quotes
    </a>
</div>

<!-- ============================================================
     Quote header
     ============================================================ -->
<div class="admin-card" style="margin-bottom:1.5rem">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem">
        <div>
            <h2 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; margin:0 0 0.5rem">
                Quote #<?= $id ?>
                <span class="badge badge-<?= $badgeClass($status) ?>" style="font-size:0.75rem; vertical-align:middle; margin-left:0.5rem">
                    <?= $statusLabel($status) ?>
                </span>
            </h2>
            <p style="color:var(--color-muted); margin:0; font-size:0.9rem">
                Customer:
                <strong style="color:var(--color-text-dark)">
                    <?= htmlspecialchars($quote['customer_name'] ?? '(no customer)') ?>
                </strong>
                &nbsp;&bull;&nbsp;
                Created: <?= htmlspecialchars(date('F j, Y', strtotime((string) $quote['created_at']))) ?>
                <?php if (!empty($quote['event_date'])): ?>
                &nbsp;&bull;&nbsp;
                Event: <strong><?= htmlspecialchars(date('F j, Y', strtotime((string) $quote['event_date']))) ?></strong>
                <?php endif; ?>
            </p>
        </div>
        <div style="text-align:right">
            <div style="font-size:1.5rem; font-weight:700; color:var(--color-text-dark)">
                $<?= number_format($total, 2) ?>
            </div>
            <div style="font-size:0.875rem; color:var(--color-muted)">
                Deposit<?= $hasFullDepositItems ? '' : ' (' . $depositPct . '%)' ?>: <strong>$<?= number_format($deposit, 2) ?></strong>
            </div>
            <?php if (\App\Models\Quote::isEditable($status)): ?>
            <div style="margin-top:0.75rem">
                <a href="/admin/quotes/<?= $id ?>/edit" class="btn btn-sm btn-outline">Edit Quote</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================
     Share link — prominent accent card
     ============================================================ -->
<div class="admin-card" style="border-left:4px solid var(--color-accent); margin-bottom:1.5rem">
    <div class="admin-card-header">
        <span class="admin-card-title">Share This Quote</span>
        <button class="btn btn-sm btn-accent"
                onclick="navigator.clipboard.writeText('<?= htmlspecialchars($quoteUrl) ?>').then(function(){ this.textContent = '✓ Copied!'; }.bind(this))">
            Copy Link
        </button>
    </div>
    <p style="font-family:Montserrat,sans-serif; font-size:0.875rem; color:var(--color-muted); word-break:break-all; margin:0 0 0.75rem">
        <?= htmlspecialchars($quoteUrl) ?>
    </p>
    <p style="font-size:0.8rem; color:var(--color-muted); margin:0">
        Paste this link in WhatsApp, Facebook, Instagram, or any chat to share the quote with your customer.
    </p>
</div>

<!-- ============================================================
     Two-column layout: items + sidebar
     ============================================================ -->
<div style="display:grid; grid-template-columns:1fr 320px; gap:1.5rem; align-items:start">

    <!-- Left column: items, notes -->
    <div>

        <!-- Line items table -->
        <div class="admin-card" style="padding:0; margin-bottom:1.5rem">
            <div class="admin-card-header" style="padding:1rem 1.5rem">
                <span class="admin-card-title">Line Items</span>
            </div>
            <div class="admin-table-wrap">
            <table class="admin-table" style="margin-bottom:0">
                <thead>
                    <tr>
                        <th style="padding-left:1.5rem">Description</th>
                        <th style="text-align:center; width:70px">Qty</th>
                        <th style="text-align:right; width:120px">Unit Price</th>
                        <th style="text-align:right; width:120px; padding-right:1.5rem">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td style="padding-left:1.5rem">
                            <?= htmlspecialchars((string) $item['description']) ?>
                            <?php if (!empty($item['full_deposit'])): ?>
                            <span style="display:inline-block; margin-left:0.5rem; padding:0.1rem 0.5rem; font-size:0.7rem; font-weight:600; color:var(--color-accent); background:rgba(181,90,160,0.12); border-radius:999px; vertical-align:middle">Paid in full</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center">
                            <?= (int) $item['qty'] ?>
                        </td>
                        <td style="text-align:right">
                            $<?= number_format((float) $item['unit_price'], 2) ?>
                        </td>
                        <td style="text-align:right; padding-right:1.5rem; font-weight:500">
                            $<?= number_format((float) $item['unit_price'] * (int) $item['qty'], 2) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align:right; padding:0.75rem 1rem; border-top:1px solid var(--color-border); color:var(--color-muted)">
                            Subtotal
                        </td>
                        <td style="text-align:right; padding:0.75rem 1.5rem; border-top:1px solid var(--color-border); font-weight:700; font-size:1rem">
                            $<?= number_format($subtotal, 2) ?>
                        </td>
                    </tr>
                    <?php if ($taxAmount > 0): ?>
                    <tr>
                        <td colspan="3" style="text-align:right; padding:0.5rem 1rem; color:var(--color-muted)">
                            Tax (<?= number_format($taxRate * 100, 3) ?>%)
                        </td>
                        <td style="text-align:right; padding:0.5rem 1.5rem; font-weight:600">
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
                    <tr>
                        <td colspan="3" style="text-align:right; padding:0.5rem 1rem; color:var(--color-muted)">
                            Deposit due
                            <?php if ($hasFullDepositItems): ?>
                            <br><span style="font-size:0.78rem">$<?= number_format($fullDepositTotal, 2) ?> paid in full + <?= $depositPct ?>% of $<?= number_format($depositRemainingBase, 2) ?></span>
                            <?php else: ?>
                            (<?= $depositPct ?>%)
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; padding:0.5rem 1.5rem; font-weight:600; color:var(--color-accent)">
                            $<?= number_format($deposit, 2) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
            </div><!-- /.admin-table-wrap -->
        </div>

        <!-- Notes -->
        <?php if (!empty($quote['notes'])): ?>
        <div class="admin-card" style="margin-bottom:1.5rem">
            <div class="admin-card-header">
                <span class="admin-card-title">Notes</span>
            </div>
            <p style="margin:0; white-space:pre-wrap; color:var(--color-text-dark); font-size:0.9375rem; line-height:1.6">
                <?= htmlspecialchars((string) $quote['notes']) ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Status timeline -->
        <div class="admin-card">
            <div class="admin-card-header">
                <span class="admin-card-title">Status Timeline</span>
            </div>

            <?php if ($status === 'cancelled'): ?>
            <p style="color:#b71c1c; font-weight:500; margin:0">This quote has been cancelled.</p>
            <?php else: ?>
            <div style="display:flex; align-items:center; flex-wrap:wrap; gap:0; margin-bottom:1rem">
                <?php foreach ($orderedStatuses as $stepIndex => $step): ?>
                <?php
                    $stepPos    = array_search($step, $orderedStatuses, true);
                    $currentPos = array_search($status, $orderedStatuses, true);
                    $isDone     = $currentPos !== false && $stepPos <= $currentPos;
                    $isCurrent  = $step === $status;
                ?>
                <div style="display:flex; align-items:center; flex:0 0 auto">
                    <!-- Step circle -->
                    <div style="
                        width:30px; height:30px; border-radius:50%;
                        display:flex; align-items:center; justify-content:center;
                        font-size:0.75rem; font-weight:700;
                        background:<?= $isDone ? 'var(--color-accent)' : 'var(--color-border)' ?>;
                        color:<?= $isDone ? '#fff' : 'var(--color-muted)' ?>;
                        border:<?= $isCurrent ? '3px solid var(--color-primary)' : '2px solid transparent' ?>;
                        box-sizing:border-box;
                    ">
                        <?= $isDone ? '&#10003;' : ($stepIndex + 1) ?>
                    </div>
                    <!-- Step label below -->
                    <div style="margin-left:0.375rem; margin-right:0.5rem">
                        <div style="font-size:0.7rem; font-weight:<?= $isCurrent ? '700' : '500' ?>; color:<?= $isCurrent ? 'var(--color-accent)' : ($isDone ? 'var(--color-text-dark)' : 'var(--color-muted)') ?>;
                            white-space:nowrap">
                            <?= $statusLabel($step) ?>
                        </div>
                    </div>
                    <!-- Connector line (not after last) -->
                    <?php if ($stepIndex < count($orderedStatuses) - 1): ?>
                    <div style="
                        width:24px; height:2px;
                        background:<?= $stepPos < $currentPos ? 'var(--color-accent)' : 'var(--color-border)' ?>;
                        margin-right:0.25rem; flex-shrink:0;
                    "></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Timestamp trail -->
            <div style="font-size:0.8125rem; color:var(--color-muted); display:flex; flex-direction:column; gap:0.25rem">
                <?php if (!empty($quote['created_at'])): ?>
                <div>Created: <?= htmlspecialchars(date('M j, Y g:i a', strtotime((string) $quote['created_at']))) ?></div>
                <?php endif; ?>
                <?php if (!empty($quote['sent_at'])): ?>
                <div>Sent: <?= htmlspecialchars(date('M j, Y g:i a', strtotime((string) $quote['sent_at']))) ?></div>
                <?php endif; ?>
                <?php if (!empty($quote['accepted_at'])): ?>
                <div>Accepted: <?= htmlspecialchars(date('M j, Y g:i a', strtotime((string) $quote['accepted_at']))) ?></div>
                <?php endif; ?>
                <?php if (!empty($quote['deposit_confirmed_at'])): ?>
                <div>Deposit confirmed: <?= htmlspecialchars(date('M j, Y g:i a', strtotime((string) $quote['deposit_confirmed_at']))) ?></div>
                <?php endif; ?>
                <?php if (!empty($quote['valid_until'])): ?>
                <div style="margin-top:0.25rem">
                    Valid until: <?= htmlspecialchars(date('M j, Y', strtotime((string) $quote['valid_until']))) ?>
                    <?php if (strtotime((string) $quote['valid_until']) < time() && !in_array($status, ['completed', 'cancelled'], true)): ?>
                    <span style="color:#b71c1c; font-weight:600"> (expired)</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /left column -->

    <!-- Right column: actions + customer info -->
    <div>

        <!-- Status transition buttons -->
        <?php if ($status !== 'completed' && $status !== 'cancelled'): ?>
        <div class="admin-card" style="margin-bottom:1.5rem">
            <div class="admin-card-header">
                <span class="admin-card-title">Actions</span>
            </div>

            <div style="display:flex; flex-direction:column; gap:0.75rem">

                <?php if ($status === 'draft'): ?>
                <form method="POST" action="/admin/quotes/<?= $id ?>/status">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="new_status" value="sent">
                    <button type="submit" class="btn btn-accent" style="width:100%">
                        Mark as Sent
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($status === 'sent'): ?>
                <form method="POST" action="/admin/quotes/<?= $id ?>/status">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="new_status" value="accepted">
                    <button type="submit" class="btn btn-accent" style="width:100%">
                        Mark as Accepted
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($status === 'accepted'): ?>
                <form method="POST" action="/admin/quotes/<?= $id ?>/status">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="new_status" value="deposit_confirmed">
                    <button type="submit" class="btn btn-accent" style="width:100%">
                        Confirm Deposit Received
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($status === 'deposit_confirmed'): ?>
                <form method="POST" action="/admin/quotes/<?= $id ?>/status">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="new_status" value="completed">
                    <button type="submit" class="btn btn-accent" style="width:100%">
                        Mark Completed
                    </button>
                </form>
                <?php endif; ?>

                <!-- Cancel — always available for non-completed quotes -->
                <form method="POST" action="/admin/quotes/<?= $id ?>/status"
                      data-confirm="Cancel this quote? This cannot be undone.">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="new_status" value="cancelled">
                    <button type="submit"
                            style="width:100%; padding:0.6rem 1rem; border-radius:6px;
                                   background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;
                                   cursor:pointer; font-size:0.875rem; font-weight:500">
                        Cancel Quote
                    </button>
                </form>

            </div>
        </div>
        <?php endif; ?>

        <!-- Customer info card -->
        <?php
        $customerId   = (int) ($quote['customer_id'] ?? 0);
        $customerName = (string) ($quote['customer_name']  ?? '');
        $customerEmail = (string) ($quote['customer_email'] ?? '');
        $customerPhone = (string) ($quote['customer_phone'] ?? '');
        $lifetimeSpend = $customerId > 0
            ? (float) (\App\Models\Customer::find($customerId)['lifetime_spend'] ?? 0)
            : 0.0;
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
                <div>
                    <strong><?= htmlspecialchars($customerName) ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($customerEmail !== ''): ?>
                <div style="color:var(--color-muted)">
                    <a href="mailto:<?= htmlspecialchars($customerEmail) ?>"
                       style="color:var(--color-primary)">
                        <?= htmlspecialchars($customerEmail) ?>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($customerPhone !== ''): ?>
                <div style="color:var(--color-muted)">
                    <a href="tel:<?= htmlspecialchars($customerPhone) ?>"
                       style="color:var(--color-primary)">
                        <?= htmlspecialchars($customerPhone) ?>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($lifetimeSpend > 0): ?>
                <div style="margin-top:0.5rem; padding-top:0.5rem; border-top:1px solid var(--color-border); color:var(--color-muted); font-size:0.8125rem">
                    Lifetime spend: <strong style="color:var(--color-text-dark)">$<?= number_format($lifetimeSpend, 2) ?></strong>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <p style="color:var(--color-muted); margin:0; font-size:0.875rem">No customer linked to this quote.</p>
            <?php endif; ?>
        </div>

    </div><!-- /right column -->

</div><!-- /grid -->
