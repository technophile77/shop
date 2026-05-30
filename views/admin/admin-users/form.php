<?php
/** @var string $name */
/** @var string $email */
/** @var string $error */
/** @var string $csrfToken */
?>
<div class="admin-content">

<div style="margin-bottom:1.5rem">
    <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Add Admin User</h1>
    <p style="color:#666; font-size:0.9rem">Create a new administrator account</p>
</div>

<?php if (!empty($error)): ?>
<div style="margin-bottom:1.5rem; padding:0.875rem 1.125rem; border-radius:6px;
            background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb">
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="admin-card" style="max-width:560px">
    <form method="POST" action="/admin/admin-users">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

        <div class="admin-form-group">
            <label for="name">Full Name</label>
            <input type="text"
                   id="name"
                   name="name"
                   value="<?= htmlspecialchars($name ?? '') ?>"
                   placeholder="Jane Smith"
                   required
                   autofocus>
        </div>

        <div class="admin-form-group">
            <label for="email">Email Address</label>
            <input type="email"
                   id="email"
                   name="email"
                   value="<?= htmlspecialchars($email ?? '') ?>"
                   placeholder="jane@example.com"
                   required>
        </div>

        <div class="admin-form-group">
            <label for="password">Password</label>
            <input type="password"
                   id="password"
                   name="password"
                   placeholder="Minimum 8 characters"
                   minlength="8"
                   required>
        </div>

        <div class="admin-form-group">
            <label for="password_confirm">Confirm Password</label>
            <input type="password"
                   id="password_confirm"
                   name="password_confirm"
                   placeholder="Re-enter password"
                   minlength="8"
                   required>
        </div>

        <div style="display:flex; gap:0.75rem; margin-top:1.75rem">
            <button type="submit" class="admin-btn admin-btn-primary" style="padding:0.75rem 2rem">
                Create Admin
            </button>
            <a href="/admin/admin-users" class="admin-btn admin-btn-ghost" style="padding:0.75rem 1.5rem">
                Cancel
            </a>
        </div>
    </form>
</div>

</div><!-- /.admin-content -->
