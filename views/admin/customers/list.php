<?php
/**
 * views/admin/customers/list.php — Admin customer CRM list.
 *
 * Rendered inside the 'admin' layout. Shows a stats bar, filter controls,
 * a searchable/sortable customer table, and a collapsible quick-add form.
 *
 * Variables available (injected by CustomersController::index()):
 *   array  $customers      Customer rows matching the active filters.
 *   int    $totalCount     Total all-time customer count.
 *   int    $vipCount       Count of VIP customers (spend >= threshold).
 *   int    $smsCount       Count of SMS-opted-in customers.
 *   float  $vipThreshold   Minimum spend for VIP status.
 *   array  $sources        Distinct source values from the customers table.
 *   array  $filters        Active filter values: source, opted_in_sms, min_spend, segment.
 *   string $csrfToken      CSRF token for the quick-add form.
 *
 * @see \App\Controllers\Admin\CustomersController::index()
 */

$activeFilters = $filters ?? [];
$segment       = $activeFilters['segment']      ?? '';
$filterSource  = $activeFilters['source']       ?? '';
$filterSms     = $activeFilters['opted_in_sms'] ?? '';
$filterSpend   = $activeFilters['min_spend']    ?? '';

/**
 * Source badge colour map — keys cover every value in Customer::SOURCES
 * (see src/Models/Customer.php, mirrored from migrations/016_customer_source_attribution.sql,
 * the source of truth for the `source` ENUM). Unknown/legacy values fall back to 'other' below.
 */
$sourceBadgeStyle = [
    'website_chat'     => 'background:#eceff1; color:#455a64',
    'order_form'       => 'background:#e3f2fd; color:#1565c0',
    'promotion_signup' => 'background:#fff3e0; color:#e65100',
    'facebook'         => 'background:#e8eaf6; color:#3b5998',
    'instagram'        => 'background:#fce4ec; color:#c13584',
    'doordash'         => 'background:#fdece9; color:#d32f2f',
    'manual'           => 'background:#f0f0f0; color:#555',
    'other'            => 'background:#fff8e1; color:#f57f17',
    'shop_checkout'    => 'background:#e6f4ea; color:#2e7d32',
    'quote_accept'     => 'background:#e0f7fa; color:#00796b',
    'admin_quote'      => 'background:#e0f2f1; color:#00838f',
    'google_ads'       => 'background:#e8f0fe; color:#1a73e8',
];
?>

<!-- =========================================================
     STATS BAR
     ========================================================= -->
<div class="stat-grid" style="margin-bottom:1.5rem">

    <div class="stat-card">
        <div class="stat-value"><?= (int) $totalCount ?></div>
        <div class="stat-label">Total Customers</div>
    </div>

    <div class="stat-card" style="border-left-color:#d4a017">
        <div class="stat-value" style="color:#d4a017"><?= (int) $vipCount ?></div>
        <div class="stat-label">VIP Customers<br>
            <span style="font-size:0.65rem; font-weight:400; text-transform:none; letter-spacing:0">
                (spend &ge; $<?= number_format((float) $vipThreshold, 0) ?>)
            </span>
        </div>
    </div>

    <div class="stat-card" style="border-left-color:#38a169">
        <div class="stat-value" style="color:#38a169"><?= (int) $smsCount ?></div>
        <div class="stat-label">SMS Opted-In</div>
    </div>

</div><!-- /.stat-grid -->

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
     FILTER ROW
     ========================================================= -->
<div class="admin-card" style="padding:1rem 1.5rem; margin-bottom:1rem">
    <form method="GET" action="/admin/customers"
          style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center">

        <!-- Segment: All vs VIP -->
        <div style="display:flex; gap:0.375rem">
            <a href="/admin/customers"
               class="admin-btn <?= $segment !== 'vip' ? 'admin-btn-primary' : 'admin-btn-ghost' ?>"
               style="font-size:0.72rem; padding:0.45rem 1rem">
                All
            </a>
            <a href="/admin/customers?segment=vip"
               class="admin-btn <?= $segment === 'vip' ? 'admin-btn-primary' : 'admin-btn-ghost' ?>"
               style="font-size:0.72rem; padding:0.45rem 1rem">
                ★ VIP
            </a>
        </div>

        <!-- Source filter -->
        <select name="source"
                onchange="this.form.submit()"
                style="padding:0.45rem 2rem 0.45rem 0.75rem; border:1.5px solid #dde1e7;
                       border-radius:4px; font-size:0.8rem; color:#444; background:#fff;
                       appearance:none; cursor:pointer">
            <option value="" <?= $filterSource === '' ? 'selected' : '' ?>>All Sources</option>
            <?php foreach ($sources as $src): ?>
            <option value="<?= htmlspecialchars($src) ?>"
                <?= $filterSource === $src ? 'selected' : '' ?>>
                <?= htmlspecialchars(str_replace('_', ' ', ucfirst($src))) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <!-- SMS opted-in toggle -->
        <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.82rem; color:#555; cursor:pointer">
            <input type="checkbox" name="opted_in_sms" value="1"
                   <?= $filterSms === '1' ? 'checked' : '' ?>
                   onchange="this.form.submit()">
            SMS Opted-In
        </label>

        <!-- Min spend filter -->
        <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.82rem; color:#555">
            Min Spend $
            <input type="number" name="min_spend" value="<?= htmlspecialchars($filterSpend) ?>"
                   min="0" step="1" placeholder="0"
                   style="width:80px; padding:0.4rem 0.5rem; border:1.5px solid #dde1e7;
                          border-radius:4px; font-size:0.82rem">
        </label>

        <?php if ($filterSpend !== '' || $filterSource !== '' || $filterSms !== ''): ?>
        <a href="/admin/customers<?= $segment === 'vip' ? '?segment=vip' : '' ?>"
           class="admin-btn admin-btn-ghost" style="font-size:0.72rem; padding:0.45rem 0.875rem">
            Clear
        </a>
        <?php endif; ?>

        <!-- Spacer -->
        <span style="flex:1; min-width:0.5rem"></span>

        <!-- Client-side search -->
        <input type="text" id="customer-search" placeholder="Search name, email, phone…"
               style="padding:0.45rem 0.875rem; border:1.5px solid #dde1e7; border-radius:4px;
                      font-size:0.82rem; width:220px"
               oninput="filterCustomers(this.value)">

        <!-- Export CSV -->
        <a href="/admin/customers/export" class="admin-btn admin-btn-ghost"
           style="font-size:0.72rem; padding:0.45rem 1rem">
            &#8595; Export CSV
        </a>
        <a href="/admin/customers/export?all=1" class="admin-btn admin-btn-ghost"
           style="font-size:0.72rem; padding:0.45rem 1rem; color:#999">
            Export All
        </a>

    </form>
</div>

<!-- =========================================================
     CUSTOMER TABLE
     ========================================================= -->
<div class="admin-card" style="padding:0; margin-bottom:1.5rem">
    <div class="admin-table-wrap">
        <table class="admin-table" id="customer-table">
            <thead>
                <tr>
                    <th style="padding-left:1.5rem">Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Source</th>
                    <th>Lifetime Spend</th>
                    <th style="text-align:center">Opted-In</th>
                    <th>Joined</th>
                    <th style="padding-right:1.5rem">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <?php
                $isVip       = (float) ($c['lifetime_spend'] ?? 0) >= $vipThreshold;
                $srcStyle    = $sourceBadgeStyle[$c['source'] ?? 'other'] ?? $sourceBadgeStyle['other'];
                $rowStyle    = $isVip ? 'border-left:3px solid #d4a017; background:#fffdf0' : '';
                ?>
                <tr data-search="<?= htmlspecialchars(strtolower(
                    ($c['name'] ?? '') . ' ' . ($c['email'] ?? '') . ' ' . ($c['phone'] ?? '')
                )) ?>"
                    style="<?= $rowStyle ?>">

                    <!-- Name + VIP badge -->
                    <td style="padding-left:1.5rem">
                        <strong style="color:#1a1a1a"><?= htmlspecialchars($c['name'] ?? '—') ?></strong>
                        <?php if ($isVip): ?>
                        <span style="display:inline-block; margin-left:0.35rem; padding:0.1rem 0.5rem;
                                     background:#d4a017; color:#fff; border-radius:100px;
                                     font-size:0.65rem; font-weight:700; letter-spacing:0.04em;
                                     font-family:'Montserrat',sans-serif; text-transform:uppercase">
                            ★ VIP
                        </span>
                        <?php endif; ?>
                    </td>

                    <!-- Email -->
                    <td style="color:#555; font-size:0.875rem">
                        <?php if (!empty($c['email'])): ?>
                        <a href="mailto:<?= htmlspecialchars($c['email']) ?>"
                           style="color:#3182ce; text-decoration:underline">
                            <?= htmlspecialchars($c['email']) ?>
                        </a>
                        <?php else: ?>
                        <span style="color:#ccc">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Phone -->
                    <td style="color:#555; font-size:0.875rem">
                        <?= htmlspecialchars($c['phone'] ?? '—') ?>
                    </td>

                    <!-- Source badge -->
                    <td>
                        <span style="display:inline-block; padding:0.2rem 0.65rem; border-radius:100px;
                                     font-size:0.68rem; font-weight:600; font-family:'Montserrat',sans-serif;
                                     letter-spacing:0.04em; text-transform:uppercase; white-space:nowrap;
                                     <?= $srcStyle ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $c['source'] ?? 'other')) ?>
                        </span>
                    </td>

                    <!-- Lifetime spend — bold and accent-coloured for VIPs -->
                    <td>
                        <strong style="font-size:0.95rem; color:<?= $isVip ? '#d4a017' : '#1a1a1a' ?>">
                            $<?= number_format((float) ($c['lifetime_spend'] ?? 0), 2) ?>
                        </strong>
                    </td>

                    <!-- Opted-in: email + SMS -->
                    <td style="text-align:center; white-space:nowrap">
                        <span title="Email opt-in" style="margin-right:0.35rem;
                              color:<?= $c['opted_in_email'] ? '#2e7d32' : '#ccc' ?>">
                            <?= $c['opted_in_email'] ? '✓ Email' : '✗ Email' ?>
                        </span>
                        <br>
                        <span title="SMS opt-in"
                              style="color:<?= $c['opted_in_sms'] ? '#2e7d32' : '#ccc' ?>">
                            <?= $c['opted_in_sms'] ? '✓ SMS' : '✗ SMS' ?>
                        </span>
                    </td>

                    <!-- Joined date -->
                    <td style="color:#888; font-size:0.85rem; white-space:nowrap">
                        <?= !empty($c['created_at']) ? date('M j, Y', strtotime((string) $c['created_at'])) : '—' ?>
                    </td>

                    <!-- Actions -->
                    <td style="padding-right:1.5rem; white-space:nowrap">
                        <a href="/admin/customers/<?= (int) $c['id'] ?>"
                           class="admin-btn admin-btn-ghost"
                           style="padding:0.4rem 0.875rem; font-size:0.7rem">
                            View
                        </a>
                    </td>

                </tr>
                <?php endforeach; ?>

                <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:3rem 1.5rem; color:#999">
                        No customers match the current filters.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div><!-- /.admin-card customer table -->

<!-- =========================================================
     QUICK-ADD CUSTOMER FORM (Alpine.js toggle)
     ========================================================= -->
<div x-data="{ open: false }">

    <button @click="open = !open" class="admin-btn admin-btn-ghost admin-btn-sm"
            style="margin-bottom:0.5rem">
        + Add Contact
    </button>

    <div x-show="open"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 transform -translate-y-2"
         x-transition:enter-end="opacity-100 transform translate-y-0"
         style="margin-top:1rem; padding:1.5rem; background:#f9f9f9;
                border-radius:8px; border:1px solid #e8e8e8">

        <div style="font-family:'Montserrat',sans-serif; font-size:0.75rem; text-transform:uppercase;
                    letter-spacing:0.08em; color:#888; margin-bottom:1.25rem; font-weight:600">
            Add Customer Manually
        </div>

        <form method="POST" action="/admin/customers">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

            <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:1rem; margin-bottom:1rem">

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="qc-name">Name</label>
                    <input type="text" id="qc-name" name="name" placeholder="Rosa García" maxlength="120">
                </div>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="qc-email">Email</label>
                    <input type="email" id="qc-email" name="email" placeholder="rosa@example.com" maxlength="180">
                </div>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="qc-phone">Phone</label>
                    <input type="tel" id="qc-phone" name="phone" placeholder="5125550199" maxlength="30">
                </div>

                <div class="admin-form-group" style="margin-bottom:0">
                    <label for="qc-source">Source</label>
                    <select id="qc-source" name="source">
                        <option value="manual" selected>Manual</option>
                        <option value="order_form">Order Form</option>
                        <option value="facebook">Facebook</option>
                        <option value="instagram">Instagram</option>
                        <option value="doordash">Doordash</option>
                        <option value="other">Other</option>
                    </select>
                </div>

            </div>

            <div class="admin-form-group">
                <label for="qc-notes">Notes</label>
                <textarea id="qc-notes" name="notes" rows="2"
                          placeholder="Prefers pink roses, anniversary client…"></textarea>
            </div>

            <button type="submit" class="admin-btn admin-btn-primary">
                Save Contact
            </button>
            <button type="button" @click="open = false"
                    class="admin-btn admin-btn-ghost" style="margin-left:0.5rem">
                Cancel
            </button>
        </form>

    </div><!-- /x-show -->
</div><!-- /x-data -->

<!-- =========================================================
     CLIENT-SIDE SEARCH
     ========================================================= -->
<script>
/**
 * Filter the customer table rows by matching the query string against
 * each row's pre-built data-search attribute (name + email + phone).
 *
 * @param {string} query - The text entered in the search field.
 */
function filterCustomers(query) {
    const q    = query.toLowerCase().trim();
    const rows = document.querySelectorAll('#customer-table tbody tr[data-search]');

    rows.forEach(function (row) {
        const haystack = row.getAttribute('data-search') || '';
        row.style.display = (q === '' || haystack.includes(q)) ? '' : 'none';
    });
}
</script>
