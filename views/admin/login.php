<?php
/**
 * views/admin/login.php — Admin login form.
 *
 * Rendered inside the 'admin-bare' layout (no sidebar). Presents a centred,
 * dark-background login card with the business logo, email/password fields,
 * and a CSRF-protected form action.
 *
 * Variables available:
 *   string $csrfToken  CSRF token to embed as a hidden field.
 *   string $error      Non-empty when a login attempt failed.
 *   string $pageTitle  'Admin Login' (unused directly here; consumed by layout).
 *
 * @see \App\Controllers\Admin\AuthController::loginForm()
 * @see \App\Controllers\Admin\AuthController::login()
 */
?>
<div style="min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--color-bg-dark)">
  <div style="width:100%; max-width:400px; padding:2rem">

    <!-- Logo + portal label -->
    <div class="text-center" style="margin-bottom:2.5rem">
      <img
        src="/public/assets/images/logo.jpg"
        alt="<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?>"
        style="height:80px; margin:0 auto 1rem; display:block"
      >
      <p style="color:rgba(255,255,255,0.4); font-family:Montserrat,sans-serif; font-size:0.75rem; letter-spacing:0.2em; text-transform:uppercase">
        Admin Portal
      </p>
    </div>

    <!-- Login card -->
    <div style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:2.5rem; backdrop-filter:blur(10px)">

      <?php if (!empty($error)): ?>
      <div style="background:rgba(229,62,62,0.15); border:1px solid rgba(229,62,62,0.3); border-radius:6px; padding:0.875rem 1rem; margin-bottom:1.5rem; color:#fc8181; font-size:0.9rem">
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action="/admin/login">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="form-group">
          <label style="color:rgba(255,255,255,0.5); font-family:Montserrat,sans-serif; font-size:0.7rem; letter-spacing:0.12em; text-transform:uppercase">
            Email
          </label>
          <input
            type="email"
            name="email"
            required
            autofocus
            autocomplete="email"
            style="background:rgba(255,255,255,0.07); border-color:rgba(255,255,255,0.15); color:#fff"
          >
        </div>

        <div class="form-group" style="margin-bottom:2rem">
          <label style="color:rgba(255,255,255,0.5); font-family:Montserrat,sans-serif; font-size:0.7rem; letter-spacing:0.12em; text-transform:uppercase">
            Password
          </label>
          <input
            type="password"
            name="password"
            required
            autocomplete="current-password"
            style="background:rgba(255,255,255,0.07); border-color:rgba(255,255,255,0.15); color:#fff"
          >
        </div>

        <button type="submit" class="btn btn-accent" style="width:100%; padding:0.875rem">
          Sign In
        </button>
      </form>

    </div><!-- /.card -->
  </div>
</div>
