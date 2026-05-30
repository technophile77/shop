<?php
/** @var array<int, array<string, mixed>> $admins */
/** @var string $csrfToken */
$currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);
?>
<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Admin Users</h1>
        <p style="color:#666; font-size:0.9rem"><?= count($admins) ?> admin<?= count($admins) !== 1 ? 's' : '' ?></p>
    </div>
    <a href="/admin/admin-users/new" class="admin-btn admin-btn-primary">+ Add Admin</a>
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
                    <th style="padding-left:1.5rem">Name</th>
                    <th>Email</th>
                    <th>Last Login</th>
                    <th>Created</th>
                    <th style="padding-right:1.5rem">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $a): ?>
                <?php $isSelf = (int) $a['id'] === $currentAdminId; ?>
                <tr>
                    <td style="padding-left:1.5rem">
                        <strong style="color:#1a1a1a"><?= htmlspecialchars($a['name']) ?></strong>
                        <?php if ($isSelf): ?>
                        <span style="margin-left:0.5rem; font-size:0.7rem; font-family:'Montserrat',sans-serif;
                                     text-transform:uppercase; letter-spacing:0.05em;
                                     background:#f3e8f9; color:var(--color-primary);
                                     padding:0.15rem 0.5rem; border-radius:4px">You</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#555"><?= htmlspecialchars($a['email']) ?></td>
                    <td style="color:#666; font-size:0.875rem">
                        <?= $a['last_login'] ? htmlspecialchars(date('M j, Y g:i a', strtotime($a['last_login']))) : '<span style="color:var(--color-muted)">Never</span>' ?>
                    </td>
                    <td style="color:#666; font-size:0.875rem">
                        <?= htmlspecialchars(date('M j, Y', strtotime($a['created_at']))) ?>
                    </td>
                    <td style="padding-right:1.5rem; white-space:nowrap">
                        <?php if ($isSelf): ?>
                        <button type="button"
                                class="admin-btn"
                                disabled
                                title="You cannot delete your own account"
                                style="padding:0.4rem 0.875rem; font-size:0.7rem;
                                       background:#f5f5f5; color:#aaa; border-color:#e0e0e0; cursor:not-allowed">
                            Delete
                        </button>
                        <?php else: ?>
                        <form method="POST"
                              action="/admin/admin-users/<?= (int) $a['id'] ?>/delete"
                              style="display:inline-block"
                              data-confirm="Delete admin &quot;<?= htmlspecialchars($a['name']) ?>&quot;? This cannot be undone.">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                            <button type="submit"
                                    class="admin-btn"
                                    style="padding:0.4rem 0.875rem; font-size:0.7rem;
                                           background:#fee2e2; color:#b91c1c; border-color:#fca5a5">
                                Delete
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($admins)): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:3rem 1.5rem; color:var(--color-muted)">
                        No admin users found.
                        <a href="/admin/admin-users/new" style="color:var(--color-primary); text-decoration:underline">
                            Add the first admin.
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /.admin-content -->
