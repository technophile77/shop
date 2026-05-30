<?php
/** @var string $csrfToken */
/** @var string $error */
?>
<div class="admin-content">

<div style="margin-bottom:1.5rem">
    <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Change Password</h1>
    <p style="color:#666; font-size:0.9rem">Update the password for your admin account</p>
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

<?php if (!empty($error)): ?>
<div style="margin-bottom:1.5rem; padding:0.875rem 1.125rem; border-radius:6px;
            background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb">
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="admin-card" style="max-width:480px">
    <form method="POST" action="/admin/change-password">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

        <div class="admin-form-group">
            <label for="current_password">Current Password</label>
            <input type="password"
                   id="current_password"
                   name="current_password"
                   placeholder="Your current password"
                   required
                   autofocus>
        </div>

        <div class="admin-form-group">
            <label for="new_password">New Password</label>
            <input type="password"
                   id="new_password"
                   name="new_password"
                   placeholder="Minimum 8 characters"
                   minlength="8"
                   required>
        </div>

        <div class="admin-form-group">
            <label for="confirm_password">Confirm New Password</label>
            <input type="password"
                   id="confirm_password"
                   name="confirm_password"
                   placeholder="Re-enter new password"
                   minlength="8"
                   required>
        </div>

        <div style="margin-top:1.75rem">
            <button type="submit" class="admin-btn admin-btn-primary" style="padding:0.75rem 2rem">
                Update Password
            </button>
        </div>
    </form>
</div>

</div><!-- /.admin-content -->
