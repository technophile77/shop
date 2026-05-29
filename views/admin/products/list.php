<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Products</h1>
        <p style="color:#666; font-size:0.9rem"><?= count($products) ?> product<?= count($products) !== 1 ? 's' : '' ?></p>
    </div>
    <a href="/admin/products/new" class="admin-btn admin-btn-primary">+ Add Product</a>
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
                    <th>Category</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th style="text-align:center">Featured</th>
                    <th style="padding-right:1.5rem">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td style="padding-left:1.5rem">
                        <?php if ($p['image_path']): ?>
                        <img src="/public/uploads/products/<?= htmlspecialchars($p['image_path']) ?>"
                             style="width:60px; height:60px; object-fit:cover; border-radius:4px; border:1px solid #e8e8e8"
                             alt="">
                        <?php else: ?>
                        <div style="width:60px; height:60px; background:var(--color-bg-light); border-radius:4px;
                                    display:flex; align-items:center; justify-content:center; color:var(--color-muted);
                                    font-size:1.25rem; border:1px solid #e8e8e8">
                            —
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong style="color:#1a1a1a"><?= htmlspecialchars($p['name_en']) ?></strong>
                        <?php if (!empty($p['name_es'])): ?>
                        <br><small style="color:var(--color-muted)"><?= htmlspecialchars($p['name_es']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="color:#555"><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($p['price_from']): ?>
                        <span style="font-weight:500">$<?= number_format((float) $p['price_from'], 2) ?></span>
                        <?php if (!empty($p['price_to']) && $p['price_to'] != $p['price_from']): ?>
                        <span style="color:var(--color-muted)"> – $<?= number_format((float) $p['price_to'], 2) ?></span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="color:var(--color-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $p['active'] ? 'active' : 'draft' ?>">
                            <?= $p['active'] ? 'Active' : 'Hidden' ?>
                        </span>
                    </td>
                    <td style="text-align:center; font-size:1.1rem; color:var(--color-primary)">
                        <?= $p['featured'] ? '★' : '<span style="color:#ccc">☆</span>' ?>
                    </td>
                    <td style="padding-right:1.5rem; white-space:nowrap">
                        <a href="/admin/products/<?= (int) $p['id'] ?>/edit"
                           class="admin-btn admin-btn-ghost"
                           style="padding:0.4rem 0.875rem; font-size:0.7rem">
                            Edit
                        </a>
                        <form method="POST"
                              action="/admin/products/<?= (int) $p['id'] ?>/delete"
                              style="display:inline-block; margin-left:0.375rem"
                              data-confirm="Delete this product? It will be hidden from the site.">
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

                <?php if (empty($products)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:3rem 1.5rem; color:var(--color-muted)">
                        No products yet.
                        <a href="/admin/products/new" style="color:var(--color-primary); text-decoration:underline">
                            Add your first product.
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /.admin-content -->
