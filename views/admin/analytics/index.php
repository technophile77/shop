<?php
/**
 * views/admin/analytics/index.php — Analytics dashboard.
 *
 * Rendered inside the 'admin' layout. Three sections:
 *   1. Traffic Overview — daily page-view bar chart, top pages, top referrers.
 *   2. Ad Attribution Funnel — UTM campaign → conversion breakdown.
 *   3. Instagram Insights — account stats and recent-posts grid (when configured).
 *
 * Variables available:
 *   array  $dailyCounts    [{date: string, count: int}, ...]  — last 30 days.
 *   array  $topPages       [{page_url: string, count: int}, ...]
 *   array  $topReferrers   [{referrer: string, count: int}, ...]
 *   array  $campaignFunnel [{campaign, source, visits, signups, orders, quotes}, ...]
 *   bool   $igConfigured   True when Instagram credentials are present.
 *   array|null $igAccount  {followers_count, media_count, username} or null.
 *   array|null $igPosts    List of recent posts or null.
 *   array|null $igInsights {reach, impressions, profile_views, website_clicks} or null.
 *
 * @see \App\Controllers\Admin\AnalyticsController::index()
 */
?>

<!-- ====================================================================
     Section 1: Traffic Overview
     ==================================================================== -->

<!-- Daily page views — pure CSS bar chart -->
<div class="admin-card">
    <div class="admin-card-header">
        <span class="admin-card-title">Page Views — Last 30 Days</span>
        <span style="color:var(--color-muted); font-size:0.875rem">
            Total: <?= number_format(array_sum(array_column($dailyCounts, 'count'))) ?>
        </span>
    </div>

    <?php if (empty($dailyCounts)): ?>
    <p style="color:var(--color-muted); padding:1rem 0; font-size:0.9rem">No page views recorded yet.</p>
    <?php else: ?>
    <div style="display:flex; align-items:flex-end; gap:4px; height:120px; padding:0 0 0.5rem">
        <?php
        $maxCount = max(array_column($dailyCounts, 'count') ?: [1]);
        foreach ($dailyCounts as $day):
            $pct = $maxCount > 0 ? round(($day['count'] / $maxCount) * 100) : 0;
        ?>
        <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px"
             title="<?= htmlspecialchars($day['date']) ?>: <?= $day['count'] ?> views">
            <div style="width:100%; background:var(--color-primary); opacity:0.8; height:<?= $pct ?>%; border-radius:2px 2px 0 0; min-height:2px"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex; justify-content:space-between; color:var(--color-muted); font-size:0.75rem; font-family:Montserrat,sans-serif">
        <span><?= htmlspecialchars($dailyCounts[0]['date'] ?? '') ?></span>
        <span><?= htmlspecialchars(end($dailyCounts)['date'] ?? '') ?></span>
    </div>
    <?php endif; ?>
</div><!-- /.admin-card daily page views -->

<!-- Two-column row: top pages + top referrers -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-top:1.5rem">

    <!-- Top pages -->
    <div class="admin-card" style="margin-top:0">
        <div class="admin-card-header">
            <span class="admin-card-title">Top Pages</span>
        </div>
        <?php if (empty($topPages)): ?>
        <p style="color:var(--color-muted); font-size:0.9rem; padding:1rem 0">No data yet.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Page</th>
                    <th style="text-align:right">Views</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topPages as $page): ?>
                <tr>
                    <td style="font-family:monospace; font-size:0.85rem">
                        <?= htmlspecialchars($page['page_url']) ?>
                    </td>
                    <td style="text-align:right"><?= number_format($page['count']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div><!-- /.admin-card top pages -->

    <!-- Top referrers -->
    <div class="admin-card" style="margin-top:0">
        <div class="admin-card-header">
            <span class="admin-card-title">Top Referrers</span>
        </div>
        <?php if (empty($topReferrers)): ?>
        <p style="color:var(--color-muted); font-size:0.9rem; padding:1rem 0">No referrer data yet.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Referrer</th>
                    <th style="text-align:right">Visits</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topReferrers as $ref): ?>
                <tr>
                    <td style="font-size:0.85rem; word-break:break-all"
                        title="<?= htmlspecialchars($ref['referrer']) ?>">
                        <?= htmlspecialchars(
                            strlen($ref['referrer']) > 60
                                ? substr($ref['referrer'], 0, 60) . '…'
                                : $ref['referrer']
                        ) ?>
                    </td>
                    <td style="text-align:right"><?= number_format($ref['count']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div><!-- /.admin-card top referrers -->

</div><!-- /.two-column grid -->

<!-- ====================================================================
     Section 2: Ad Attribution Funnel
     ==================================================================== -->

<div class="admin-card" style="margin-top:1.5rem">
    <div class="admin-card-header">
        <span class="admin-card-title">Ad Campaign Conversion Funnel</span>
        <small style="color:var(--color-muted)">Visits from UTM-tagged links → actual conversions</small>
    </div>

    <?php if (empty($campaignFunnel)): ?>
    <p style="color:var(--color-muted); padding:1.5rem 0">
        No UTM-tagged visits yet. Share links with <code>?utm_source=facebook&amp;utm_campaign=your_campaign</code> to track conversions.
    </p>
    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Campaign</th>
                <th>Source</th>
                <th style="text-align:right">Visits</th>
                <th style="text-align:right">Signups</th>
                <th style="text-align:right">Orders</th>
                <th style="text-align:right">Quotes</th>
                <th style="text-align:right">Conv. Rate</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($campaignFunnel as $row): ?>
            <?php
                $totalConv = $row['signups'] + $row['orders'] + $row['quotes'];
                $convRate  = $row['visits'] > 0 ? round(($totalConv / $row['visits']) * 100, 1) : 0;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($row['campaign'] ?: '(direct)') ?></strong></td>
                <td><?= htmlspecialchars($row['source'] ?: '—') ?></td>
                <td style="text-align:right"><?= number_format($row['visits']) ?></td>
                <td style="text-align:right"><?= number_format($row['signups']) ?></td>
                <td style="text-align:right"><?= number_format($row['orders']) ?></td>
                <td style="text-align:right"><?= number_format($row['quotes']) ?></td>
                <td style="text-align:right">
                    <span style="color:<?= $convRate > 5 ? '#38a169' : ($convRate > 1 ? 'var(--color-primary)' : 'var(--color-muted)') ?>; font-weight:600">
                        <?= $convRate ?>%
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div><!-- /.admin-card campaign funnel -->

<!-- ====================================================================
     Section 3: Instagram Insights
     ==================================================================== -->

<?php if (!$igConfigured): ?>
<div style="background:#f0f4ff; border:1px solid #bee3f8; border-radius:8px; padding:1.5rem; margin-top:1.5rem">
    <strong style="color:#2b6cb0">Instagram Insights Not Connected</strong>
    <p style="margin:0.5rem 0 0; color:#2c5282; font-size:0.9rem">
        Add META_IG_ACCESS_TOKEN and META_IG_USER_ID to your .env to see Instagram analytics here.
        <a href="https://developers.facebook.com/docs/instagram-api/getting-started" target="_blank" rel="noopener">Setup guide →</a>
    </p>
</div>

<?php else: ?>
<div class="admin-card" style="margin-top:1.5rem">
    <div class="admin-card-header">
        <span class="admin-card-title">Instagram — @<?= htmlspecialchars($igAccount['username'] ?? $config('INSTAGRAM_HANDLE', '')) ?></span>
        <a href="https://www.instagram.com/<?= htmlspecialchars($config('INSTAGRAM_HANDLE', '')) ?>"
           target="_blank" class="admin-btn admin-btn-ghost">View Profile</a>
    </div>

    <!-- Account stat cards -->
    <?php if ($igAccount): ?>
    <div class="stat-grid" style="margin-bottom:1.5rem">
        <div class="stat-card" style="border-left-color:#E1306C">
            <div class="stat-value"><?= number_format($igAccount['followers_count']) ?></div>
            <div class="stat-label">Followers</div>
        </div>
        <div class="stat-card" style="border-left-color:#E1306C">
            <div class="stat-value"><?= number_format($igAccount['media_count']) ?></div>
            <div class="stat-label">Posts</div>
        </div>
        <?php if ($igInsights): ?>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($igInsights['reach']) ?></div>
            <div class="stat-label">Reach (30d)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($igInsights['profile_views']) ?></div>
            <div class="stat-label">Profile Views (30d)</div>
        </div>
        <?php endif; ?>
    </div><!-- /.stat-grid -->
    <?php endif; ?>

    <!-- Recent posts grid -->
    <?php if (!empty($igPosts)): ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(120px, 1fr)); gap:0.5rem">
        <?php foreach ($igPosts as $post): ?>
        <a href="<?= htmlspecialchars($post['permalink']) ?>" target="_blank" rel="noopener"
           style="position:relative; aspect-ratio:1; display:block; border-radius:6px; overflow:hidden; background:#eee"
           title="❤️ <?= (int) $post['like_count'] ?> | 💬 <?= (int) $post['comments_count'] ?>">
            <?php if (!empty($post['thumbnail_url'])): ?>
            <img src="<?= htmlspecialchars($post['thumbnail_url']) ?>"
                 style="width:100%; height:100%; object-fit:cover"
                 loading="lazy" alt="">
            <?php else: ?>
            <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#999; font-size:0.75rem">Post</div>
            <?php endif; ?>
            <div style="position:absolute; inset:0; background:rgba(0,0,0,0.4); opacity:0; transition:opacity 0.2s; display:flex; align-items:center; justify-content:center; color:#fff; font-size:0.8rem; gap:0.5rem"
                 onmouseenter="this.style.opacity=1" onmouseleave="this.style.opacity=0">
                ❤️ <?= (int) $post['like_count'] ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div><!-- /.posts grid -->
    <?php endif; ?>

</div><!-- /.admin-card instagram -->
<?php endif; ?>
