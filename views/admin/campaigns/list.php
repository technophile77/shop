<?php
/**
 * views/admin/campaigns/list.php — Ad campaign list.
 *
 * Rendered inside the 'admin' layout. Shows a configuration notice when
 * the Facebook Marketing API is not set up, a highlighted A/B pairs section
 * when any paired campaigns exist, then a full table of all campaigns.
 *
 * Variables available:
 *   array  $campaigns    All ad_campaigns rows, newest first.
 *   array  $abPairs      A/B pairs keyed by ab_group_id: ['a' => row, 'b' => row].
 *   bool   $fbConfigured True when META_* env vars are all present.
 *   string $csrfToken    CSRF token (not used in the list but available for inline forms).
 *
 * @see \App\Controllers\Admin\CampaignsController::index()
 */
?>
<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Campaigns</h1>
        <p style="color:#666; font-size:0.9rem"><?= count($campaigns) ?> campaign<?= count($campaigns) !== 1 ? 's' : '' ?></p>
    </div>
    <a href="/admin/campaigns/new" class="admin-btn admin-btn-primary">+ New Campaign</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div style="margin-bottom:1.5rem; padding:0.875rem 1.125rem; border-radius:6px;
    <?= $_SESSION['flash']['type'] === 'success'
        ? 'background:#e6f4ea; color:#2e7d32; border:1px solid #a8d5b1'
        : 'background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb' ?>">
    <?= htmlspecialchars($_SESSION['flash']['message']) ?>
</div>
<?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (!$fbConfigured): ?>
<div style="background:#fffbeb; border:1px solid #fbbf24; border-radius:8px; padding:1.5rem; margin-bottom:1.5rem">
    <strong style="color:#92400e">Facebook Ads API Not Configured</strong>
    <p style="margin:0.5rem 0 0; color:#78350f; font-size:0.9rem">
        Add META_AD_ACCOUNT_ID, META_ADS_ACCESS_TOKEN, and META_APP_ID to your .env to create and manage ads directly from this dashboard.
        <a href="https://developers.facebook.com/docs/marketing-api/get-started" target="_blank" rel="noopener" style="color:#92400e">Setup guide &rarr;</a>
    </p>
</div>
<?php endif; ?>

<?php if (!empty($abPairs)): ?>
<div style="margin-bottom:2rem">
    <h2 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.25rem; font-weight:600; color:#1a1a1a; margin-bottom:1rem">A/B Tests</h2>
    <?php foreach ($abPairs as $groupId => $pair): ?>
    <div class="admin-card" style="border:2px solid var(--color-primary); margin-bottom:1.5rem">
        <div class="admin-card-header">
            <span class="admin-card-title">A/B Test</span>
            <span class="badge badge-active">Comparing</span>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem">
            <!-- Variant A -->
            <div style="padding:1rem; background:var(--color-bg-light); border-radius:6px">
                <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-muted); margin-bottom:0.25rem">Variant A</div>
                <strong><?= htmlspecialchars($pair['a']['name']) ?></strong>
                <div style="margin-top:0.5rem; display:flex; gap:1rem; font-size:0.875rem; color:var(--color-muted)">
                    <span>&#128065; <?= number_format((int) ($pair['a']['impressions'] ?? 0)) ?></span>
                    <span>&#128432; <?= number_format((int) ($pair['a']['clicks']      ?? 0)) ?></span>
                    <span>&#128176; $<?= number_format((float) ($pair['a']['spend'] ?? 0), 2) ?></span>
                </div>
            </div>
            <!-- Variant B -->
            <div style="padding:1rem; background:var(--color-bg-light); border-radius:6px">
                <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-muted); margin-bottom:0.25rem">Variant B</div>
                <strong><?= htmlspecialchars($pair['b']['name'] ?? '&mdash;') ?></strong>
                <div style="margin-top:0.5rem; display:flex; gap:1rem; font-size:0.875rem; color:var(--color-muted)">
                    <span>&#128065; <?= number_format((int) ($pair['b']['impressions'] ?? 0)) ?></span>
                    <span>&#128432; <?= number_format((int) ($pair['b']['clicks']      ?? 0)) ?></span>
                    <span>&#128176; $<?= number_format((float) ($pair['b']['spend'] ?? 0), 2) ?></span>
                </div>
            </div>
        </div>
        <div style="margin-top:1rem; display:flex; gap:0.5rem">
            <a href="/admin/campaigns/<?= (int) $pair['a']['id'] ?>" class="admin-btn admin-btn-ghost" style="font-size:0.75rem">View A</a>
            <?php if (isset($pair['b'])): ?>
            <a href="/admin/campaigns/<?= (int) $pair['b']['id'] ?>" class="admin-btn admin-btn-ghost" style="font-size:0.75rem">View B</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="admin-card" style="padding:0">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Platform</th>
                    <th>Variant</th>
                    <th style="text-align:right">Impressions</th>
                    <th style="text-align:right">Clicks</th>
                    <th style="text-align:right">CTR</th>
                    <th style="text-align:right">Spend</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($campaigns as $c): ?>
                <?php
                    $impressions = (int)   ($c['impressions'] ?? 0);
                    $clicks      = (int)   ($c['clicks']      ?? 0);
                    $spend       = (float) ($c['spend']       ?? 0.0);
                    $ctr         = $impressions > 0 ? number_format($clicks / $impressions * 100, 2) . '%' : '&mdash;';
                ?>
                <tr>
                    <td>
                        <strong style="color:#1a1a1a"><?= htmlspecialchars($c['name']) ?></strong>
                        <?php if (!empty($c['objective'])): ?>
                        <br><small style="color:var(--color-muted); font-size:0.75rem"><?= htmlspecialchars($c['objective']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge" style="<?= match($c['platform'] ?? 'both') {
                            'facebook'  => 'background:#1877f2; color:#fff',
                            'instagram' => 'background:#c13584; color:#fff',
                            default     => 'background:#eee; color:#333',
                        } ?>">
                            <?= htmlspecialchars(ucfirst($c['platform'] ?? 'both')) ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($c['variant'])): ?>
                        <span class="badge" style="background:var(--color-bg-light); color:var(--color-primary); border:1px solid var(--color-primary)">
                            Variant <?= htmlspecialchars($c['variant']) ?>
                        </span>
                        <?php else: ?>
                        <span style="color:var(--color-muted); font-size:0.85rem">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; font-variant-numeric:tabular-nums"><?= number_format($impressions) ?></td>
                    <td style="text-align:right; font-variant-numeric:tabular-nums"><?= number_format($clicks) ?></td>
                    <td style="text-align:right; font-variant-numeric:tabular-nums"><?= $ctr ?></td>
                    <td style="text-align:right; font-variant-numeric:tabular-nums">$<?= number_format($spend, 2) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($c['status'] ?? 'draft') ?>">
                            <?= htmlspecialchars(ucfirst($c['status'] ?? 'draft')) ?>
                        </span>
                    </td>
                    <td>
                        <a href="/admin/campaigns/<?= (int) $c['id'] ?>"
                           class="admin-btn admin-btn-ghost"
                           style="padding:0.4rem 0.875rem; font-size:0.7rem">
                            View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($campaigns)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:3rem 1.5rem; color:var(--color-muted)">
                        No campaigns yet.
                        <a href="/admin/campaigns/new" style="color:var(--color-primary); text-decoration:underline">
                            Create your first campaign.
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /.admin-content -->
