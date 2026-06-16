<?php
/**
 * views/admin/addons/list.php — Admin list of all add-ons.
 *
 * Rendered inside views/layouts/admin.php via AddonsController::index().
 *
 * Variables injected:
 *   array  $addons    All add-on rows from Addon::all().
 *   string $csrfToken Session CSRF token for delete forms.
 *   string $pageTitle Page title ('Add-Ons').
 *
 * @see \App\Controllers\Admin\AddonsController::index()
 */
?>
<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Add-Ons</h1>
        <p style="color:#666; font-size:0.9rem"><?= count($addons) ?> add-on<?= count($addons) !== 1 ? 's' : '' ?></p>
    </div>
    <a href="/admin/addons/new" class="admin-btn admin-btn-primary">+ Add Add-On</a>
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

<div class="admin-card" style="padding:0">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:80px; padding-left:1.5rem">Photo</th>
                    <th>Name (EN)</th>
                    <th>Name (ES)</th>
                    <th style="text-align:right">Price</th>
                    <th style="text-align:center">Sort</th>
                    <th>Status</th>
                    <th style="padding-right:1.5rem">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($addons as $a): ?>
                <tr>
                    <td style="padding-left:1.5rem">
                        <?php if (!empty($a['image_path'])): ?>
                        <img src="/public/uploads/products/<?= htmlspecialchars($a['image_path']) ?>"
                             style="width:60px; height:60px; object-fit:cover; border-radius:4px; border:1px solid #e8e8e8"
                             alt="">
                        <?php else: ?>
                        <div style="width:60px; height:60px; background:var(--color-bg-light); border-radius:4px;
                                    display:flex; align-items:center; justify-content:center; color:var(--color-muted);
                                    font-size:1.25rem; border:1px solid #e8e8e8">—</div>
                        <?php endif; ?>
                    </td>
                    <td><strong style="color:#1a1a1a"><?= htmlspecialchars($a['name_en']) ?></strong></td>
                    <td style="color:#555">
                        <?php if (!empty($a['name_es'])): ?>
                            <?= htmlspecialchars($a['name_es']) ?>
                        <?php else: ?>
                            <span style="color:var(--color-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; color:#555; white-space:nowrap">
                        <?php if (isset($a['price']) && (float) $a['price'] > 0): ?>
                            $<?= number_format((float) $a['price'], 2) ?>
                        <?php else: ?>
                            <span style="color:var(--color-muted)">Free</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center; color:#555"><?= (int) $a['sort_order'] ?></td>
                    <td>
                        <span class="badge badge-<?= $a['active'] ? 'active' : 'draft' ?>">
                            <?= $a['active'] ? 'Active' : 'Hidden' ?>
                        </span>
                    </td>
                    <td style="padding-right:1.5rem; white-space:nowrap">
                        <a href="/admin/addons/<?= (int) $a['id'] ?>/edit"
                           class="admin-btn admin-btn-ghost"
                           style="padding:0.4rem 0.875rem; font-size:0.7rem">Edit</a>
                        <form method="POST"
                              action="/admin/addons/<?= (int) $a['id'] ?>/delete"
                              style="display:inline-block; margin-left:0.375rem"
                              data-confirm="Hide this add-on? It will no longer appear on the order form.">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                            <button type="submit"
                                    class="admin-btn"
                                    style="padding:0.4rem 0.875rem; font-size:0.7rem;
                                           background:#fee2e2; color:#b91c1c; border-color:#fca5a5">
                                Delete
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($addons)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:3rem 1.5rem; color:var(--color-muted)">
                        No add-ons yet.
                        <a href="/admin/addons/new" style="color:var(--color-primary); text-decoration:underline">Add your first add-on.</a>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /.admin-content -->
