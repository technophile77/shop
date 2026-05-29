<?php
/**
 * views/admin/campaigns/detail.php — Campaign detail with live metrics and A/B comparison.
 *
 * Rendered inside the 'admin' layout. Shows the campaign header, four live
 * metric cards (impressions, clicks, CTR, spend), an A/B comparison panel
 * when a partner campaign exists, and a creative preview section.
 *
 * Variables available:
 *   array       $campaign   Current campaign row from ad_campaigns.
 *   array|null  $partner    Partner campaign row (A/B pair), or null.
 *   string      $ctr        Pre-formatted CTR string (e.g. "3.14").
 *   string      $cpc        Pre-formatted CPC string (e.g. "0.42") or "—".
 *   string      $cpm        Pre-formatted CPM string (e.g. "1.25") or "—".
 *   bool        $isWinner   True when this campaign has a higher CTR than its partner.
 *   string      $csrfToken  CSRF token for the pause form.
 *
 * @see \App\Controllers\Admin\CampaignsController::show()
 */

$impressions = (int)   ($campaign['impressions'] ?? 0);
$clicks      = (int)   ($campaign['clicks']      ?? 0);
$spend       = (float) ($campaign['spend']       ?? 0.0);
$status      = (string) ($campaign['status']     ?? 'draft');
$hasFbAd     = !empty($campaign['fb_ad_id']);
?>
<div class="admin-content">

<!-- Page header -->
<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.4rem">
            <?= htmlspecialchars($campaign['name'] ?? '—') ?>
        </h1>
        <div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap">
            <!-- Platform badge -->
            <span class="badge" style="<?= match($campaign['platform'] ?? 'both') {
                'facebook'  => 'background:#1877f2; color:#fff',
                'instagram' => 'background:#c13584; color:#fff',
                default     => 'background:#eee; color:#333',
            } ?>">
                <?= htmlspecialchars(ucfirst($campaign['platform'] ?? 'both')) ?>
            </span>
            <!-- Objective -->
            <span style="font-size:0.8rem; color:var(--color-muted)"><?= htmlspecialchars($campaign['objective'] ?? '') ?></span>
            <!-- Status badge -->
            <span class="badge badge-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
            <!-- Dates -->
            <?php if (!empty($campaign['start_date'])): ?>
            <span style="font-size:0.8rem; color:var(--color-muted)">
                <?= htmlspecialchars(date('M j, Y', strtotime((string) $campaign['start_date']))) ?>
                &ndash;
                <?= !empty($campaign['end_date']) ? htmlspecialchars(date('M j, Y', strtotime((string) $campaign['end_date']))) : 'ongoing' ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex; gap:0.5rem; align-items:center">
        <?php if ($status === 'active' && $hasFbAd): ?>
        <form method="POST" action="/admin/campaigns/<?= (int) $campaign['id'] ?>/pause">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <button type="submit" class="admin-btn" style="background:#fee2e2; color:#b91c1c; border-color:#fca5a5; font-size:0.8rem">
                Pause Campaign
            </button>
        </form>
        <?php endif; ?>
        <a href="/admin/campaigns" class="admin-btn admin-btn-ghost" style="font-size:0.75rem">&larr; All Campaigns</a>
    </div>
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

<!-- Live Metrics -->
<div class="stat-grid" style="margin-bottom:1.5rem">

    <div class="stat-card">
        <div class="stat-value"><?= number_format($impressions) ?></div>
        <div class="stat-label">Impressions</div>
    </div>

    <div class="stat-card" style="border-left-color:var(--color-accent)">
        <div class="stat-value" style="color:var(--color-accent)"><?= number_format($clicks) ?></div>
        <div class="stat-label">Clicks</div>
    </div>

    <div class="stat-card" style="border-left-color:#3182ce">
        <div class="stat-value" style="color:#3182ce"><?= $ctr ?>%</div>
        <div class="stat-label">Click-Through Rate</div>
    </div>

    <div class="stat-card" style="border-left-color:#38a169">
        <div class="stat-value" style="color:#38a169">$<?= number_format($spend, 2) ?></div>
        <div class="stat-label">Total Spend</div>
    </div>

</div><!-- /.stat-grid -->

<?php if ($clicks > 0): ?>
<div class="stat-grid" style="margin-bottom:1.5rem; grid-template-columns: repeat(2, 1fr)">
    <div class="stat-card">
        <div class="stat-value">$<?= $cpc ?></div>
        <div class="stat-label">Cost per Click</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">$<?= $cpm ?></div>
        <div class="stat-label">CPM (per 1,000 impressions)</div>
    </div>
</div>
<?php endif; ?>

<?php if (!$hasFbAd): ?>
<div style="background:#fffbeb; border:1px solid #fbbf24; border-radius:8px; padding:1rem 1.5rem; margin-bottom:1.5rem; font-size:0.875rem; color:#78350f">
    This campaign is a <strong>draft</strong> and has no Facebook ad ID. Launch it from
    <a href="https://adsmanager.facebook.com" target="_blank" rel="noopener" style="color:#92400e">Ads Manager</a>
    to start receiving live metrics.
</div>
<?php endif; ?>

<!-- A/B Comparison -->
<?php if ($partner !== null): ?>
<?php
    $pImpressions = (int)   ($partner['impressions'] ?? 0);
    $pClicks      = (int)   ($partner['clicks']      ?? 0);
    $pSpend       = (float) ($partner['spend']        ?? 0.0);
    $pCtr         = $pImpressions > 0 ? number_format($pClicks / $pImpressions * 100, 2) : '0.00';
    $partnerStatus = (string) ($partner['status'] ?? 'draft');
?>
<div class="admin-card" style="margin-bottom:1.5rem">
    <div class="admin-card-header">
        <span class="admin-card-title">A/B Test Comparison</span>
        <span class="badge badge-active">Live</span>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem">

        <!-- This campaign -->
        <div style="padding:1.5rem; border-radius:8px; border:2px solid <?= $isWinner ? 'var(--color-accent)' : 'var(--color-border)' ?>">
            <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-muted); margin-bottom:0.25rem">
                Variant <?= htmlspecialchars($campaign['variant'] ?? 'A') ?>
                <?= $isWinner ? ' &mdash; Winner' : '' ?>
            </div>
            <strong style="color:#1a1a1a"><?= htmlspecialchars($campaign['name']) ?></strong>
            <div style="margin-top:1rem; display:flex; flex-direction:column; gap:0.35rem; font-size:0.875rem; color:#555">
                <div>Impressions: <strong><?= number_format($impressions) ?></strong></div>
                <div>Clicks: <strong><?= number_format($clicks) ?></strong></div>
                <div>CTR: <strong><?= $ctr ?>%</strong></div>
                <div>Spend: <strong>$<?= number_format($spend, 2) ?></strong></div>
                <?php if ($clicks > 0): ?>
                <div>CPC: <strong>$<?= $cpc ?></strong></div>
                <?php endif; ?>
            </div>
            <?php if ($status === 'active' && $hasFbAd): ?>
            <form method="POST" action="/admin/campaigns/<?= (int) $campaign['id'] ?>/pause" style="margin-top:1.25rem">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <button type="submit" class="admin-btn admin-btn-ghost" style="font-size:0.75rem; padding:0.4rem 0.875rem">Pause This Variant</button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Partner campaign -->
        <div style="padding:1.5rem; border-radius:8px; border:2px solid <?= !$isWinner ? 'var(--color-accent)' : 'var(--color-border)' ?>">
            <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-muted); margin-bottom:0.25rem">
                Variant <?= htmlspecialchars($partner['variant'] ?? 'B') ?>
                <?= !$isWinner ? ' &mdash; Winner' : '' ?>
            </div>
            <strong style="color:#1a1a1a"><?= htmlspecialchars($partner['name']) ?></strong>
            <div style="margin-top:1rem; display:flex; flex-direction:column; gap:0.35rem; font-size:0.875rem; color:#555">
                <div>Impressions: <strong><?= number_format($pImpressions) ?></strong></div>
                <div>Clicks: <strong><?= number_format($pClicks) ?></strong></div>
                <div>CTR: <strong><?= $pCtr ?>%</strong></div>
                <div>Spend: <strong>$<?= number_format($pSpend, 2) ?></strong></div>
                <?php if ($pClicks > 0): ?>
                <div>CPC: <strong>$<?= number_format($pSpend / $pClicks, 2) ?></strong></div>
                <?php endif; ?>
            </div>
            <?php if ($partnerStatus === 'active' && !empty($partner['fb_ad_id'])): ?>
            <form method="POST" action="/admin/campaigns/<?= (int) $partner['id'] ?>/pause" style="margin-top:1.25rem">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <button type="submit" class="admin-btn admin-btn-ghost" style="font-size:0.75rem; padding:0.4rem 0.875rem">Pause This Variant</button>
            </form>
            <?php endif; ?>
            <div style="margin-top:0.875rem">
                <a href="/admin/campaigns/<?= (int) $partner['id'] ?>" class="admin-btn admin-btn-ghost" style="font-size:0.75rem; padding:0.4rem 0.875rem">View Detail</a>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<!-- Creative Preview -->
<div class="admin-card">
    <div class="admin-card-header">
        <span class="admin-card-title">Creative Preview</span>
    </div>
    <div style="display:grid; grid-template-columns:<?= !empty($campaign['ad_image_path']) ? '1fr 2fr' : '1fr' ?>; gap:1.5rem; align-items:start">

        <?php if (!empty($campaign['ad_image_path'])): ?>
        <div>
            <img src="/public/uploads/products/<?= htmlspecialchars($campaign['ad_image_path']) ?>"
                 alt="Ad creative image"
                 style="width:100%; border-radius:8px; border:1px solid var(--color-border); object-fit:cover; max-height:300px">
        </div>
        <?php endif; ?>

        <div style="display:flex; flex-direction:column; gap:1rem">
            <?php if (!empty($campaign['ad_headline'])): ?>
            <div>
                <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-muted); margin-bottom:0.25rem">Headline</div>
                <p style="font-size:1.05rem; font-weight:600; color:#1a1a1a; margin:0"><?= htmlspecialchars($campaign['ad_headline']) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($campaign['ad_copy'])): ?>
            <div>
                <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-muted); margin-bottom:0.25rem">Ad Copy</div>
                <p style="color:#444; margin:0; font-size:0.9rem; line-height:1.55"><?= nl2br(htmlspecialchars($campaign['ad_copy'])) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($campaign['ad_link'])): ?>
            <div>
                <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-muted); margin-bottom:0.25rem">Destination URL</div>
                <a href="<?= htmlspecialchars($campaign['ad_link']) ?>"
                   target="_blank" rel="noopener"
                   style="color:var(--color-primary); font-size:0.875rem; word-break:break-all">
                    <?= htmlspecialchars($campaign['ad_link']) ?>
                </a>
            </div>
            <?php endif; ?>

            <?php if (!empty($campaign['budget'])): ?>
            <div>
                <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-muted); margin-bottom:0.25rem">Daily Budget</div>
                <p style="color:#444; margin:0; font-size:0.9rem">$<?= number_format((float) $campaign['budget'], 2) ?>/day</p>
            </div>
            <?php endif; ?>

            <?php if (!empty($campaign['fb_ad_id'])): ?>
            <div>
                <div style="font-size:0.7rem; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--color-muted); margin-bottom:0.25rem">Facebook Ad ID</div>
                <code style="font-size:0.8rem; color:#555; background:var(--color-bg-light); padding:0.2rem 0.4rem; border-radius:4px"><?= htmlspecialchars($campaign['fb_ad_id']) ?></code>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

</div><!-- /.admin-content -->
