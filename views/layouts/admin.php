<?php
/**
 * views/layouts/admin.php — Full admin layout with sidebar and topbar.
 *
 * Wraps all authenticated admin pages in the complete two-column shell:
 * a fixed dark sidebar with grouped navigation links and a scrollable main
 * area containing a sticky topbar and the page content.
 *
 * Variables available (injected by BaseController::render()):
 *   string  $lang       Current locale ('en' or 'es').
 *   string  $pageTitle  Page-specific title shown in <title> and the topbar.
 *   string  $bodyClass  Optional extra CSS class(es) for <body>.
 *   string  $content    Rendered inner view HTML.
 *
 * Active sidebar links are highlighted by comparing REQUEST_URI against each
 * known route prefix — exact match for /admin, prefix match for all others.
 *
 * @see \App\Controllers\BaseController::render()
 * @see \App\Controllers\Admin\AuthController  Provides logout route.
 */

/** @var string $currentUri */
$currentUri = $_SERVER['REQUEST_URI'] ?? '/admin';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | Admin — <?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?></title>

    <!-- Google Fonts: Cormorant Garamond, Montserrat, Lato -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Montserrat:wght@300;400;500;600&family=Lato:wght@300;400;700&display=swap">

    <!-- CSS custom properties from .env -->
    <style>
    :root {
        --color-primary:    <?= htmlspecialchars(\App\Core\Config::get('COLOR_PRIMARY',    '#B55AA0')) ?>;
        --color-accent:     <?= htmlspecialchars(\App\Core\Config::get('COLOR_ACCENT',     '#D4409A')) ?>;
        --color-bg-dark:    <?= htmlspecialchars(\App\Core\Config::get('COLOR_BG_DARK',    '#0D0D0D')) ?>;
        --color-bg-light:   <?= htmlspecialchars(\App\Core\Config::get('COLOR_BG_LIGHT',   '#FAF7FA')) ?>;
        --color-text-dark:  <?= htmlspecialchars(\App\Core\Config::get('COLOR_TEXT_DARK',  '#1A1A1A')) ?>;
        --color-text-light: <?= htmlspecialchars(\App\Core\Config::get('COLOR_TEXT_LIGHT', '#FFFFFF')) ?>;
        --color-muted:      <?= htmlspecialchars(\App\Core\Config::get('COLOR_MUTED',      '#999999')) ?>;
        --color-border:     <?= htmlspecialchars(\App\Core\Config::get('COLOR_BORDER',     '#E8D5E8')) ?>;
    }
    </style>

    <link rel="stylesheet" href="/public/assets/css/admin.css">
    <link rel="stylesheet" href="/public/assets/css/main.css">
</head>
<body class="admin-body <?= htmlspecialchars($bodyClass) ?>">

<div class="admin-layout">

    <!-- ============================================================
         SIDEBAR
         ============================================================ -->
    <aside class="admin-sidebar" id="admin-sidebar" role="navigation" aria-label="Admin navigation">

        <div class="admin-sidebar-logo">
            <a href="/admin" aria-label="Admin dashboard">
                <img
                    src="/public/assets/images/logo.jpg"
                    alt="<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?> logo"
                    style="height:40px; width:auto"
                >
            </a>
        </div>

        <nav class="admin-nav">

            <!-- Main -->
            <div class="admin-nav-section">Main</div>
            <a href="/admin" class="<?= ($currentUri === '/admin') ? 'active' : '' ?>">
                <!-- Dashboard icon: four-square grid -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <rect x="1" y="1" width="6" height="6" rx="1" fill="currentColor"/>
                    <rect x="9" y="1" width="6" height="6" rx="1" fill="currentColor"/>
                    <rect x="1" y="9" width="6" height="6" rx="1" fill="currentColor"/>
                    <rect x="9" y="9" width="6" height="6" rx="1" fill="currentColor"/>
                </svg>
                Dashboard
            </a>

            <!-- Catalog -->
            <div class="admin-nav-section">Catalog</div>
            <a href="/admin/products" class="<?= str_starts_with($currentUri, '/admin/products') ? 'active' : '' ?>">
                <!-- Flower/leaf icon: circle with small petal marks -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="8" cy="8" r="3" fill="currentColor"/>
                    <ellipse cx="8" cy="2" rx="2" ry="2.5" fill="currentColor" opacity="0.6"/>
                    <ellipse cx="8" cy="14" rx="2" ry="2.5" fill="currentColor" opacity="0.6"/>
                    <ellipse cx="2" cy="8" rx="2.5" ry="2" fill="currentColor" opacity="0.6"/>
                    <ellipse cx="14" cy="8" rx="2.5" ry="2" fill="currentColor" opacity="0.6"/>
                </svg>
                Products
            </a>
            <a href="/admin/categories" class="<?= str_starts_with($currentUri, '/admin/categories') ? 'active' : '' ?>">
                <!-- Tag icon: rounded rectangle with a circle cut-out -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M2 2h6l6 6-6 6-6-6V2z" fill="currentColor" opacity="0.85"/>
                    <circle cx="5" cy="5" r="1.25" fill="white"/>
                </svg>
                Categories
            </a>

            <!-- Orders -->
            <div class="admin-nav-section">Orders</div>
            <a href="/admin/quotes" class="<?= str_starts_with($currentUri, '/admin/quotes') ? 'active' : '' ?>">
                <!-- Document icon: rectangle with lines -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <rect x="2" y="1" width="12" height="14" rx="1.5" fill="currentColor" opacity="0.3"/>
                    <rect x="2" y="1" width="12" height="14" rx="1.5" stroke="currentColor" stroke-width="1.5"/>
                    <line x1="5" y1="5.5" x2="11" y2="5.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    <line x1="5" y1="8" x2="11" y2="8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    <line x1="5" y1="10.5" x2="8.5" y2="10.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
                Quotes
            </a>

            <!-- Customers -->
            <div class="admin-nav-section">Customers</div>
            <a href="/admin/customers" class="<?= str_starts_with($currentUri, '/admin/customers') ? 'active' : '' ?>">
                <!-- People icon: two overlapping circles + arcs -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="6" cy="5" r="2.5" fill="currentColor"/>
                    <path d="M1 13c0-2.76 2.24-5 5-5s5 2.24 5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="12" cy="5" r="2" fill="currentColor" opacity="0.5"/>
                    <path d="M12 10.5c1.66 0 3 1.34 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.5"/>
                </svg>
                Customers
            </a>

            <!-- Marketing -->
            <div class="admin-nav-section">Marketing</div>
            <a href="/admin/campaigns" class="<?= str_starts_with($currentUri, '/admin/campaigns') ? 'active' : '' ?>">
                <!-- Megaphone icon -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M2 6h2v4H2z" fill="currentColor"/>
                    <path d="M4 6L12 2v12L4 10V6z" fill="currentColor" opacity="0.8"/>
                    <path d="M5 10l1.5 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                Campaigns
            </a>
            <a href="/admin/sms" class="<?= str_starts_with($currentUri, '/admin/sms') ? 'active' : '' ?>">
                <!-- Message bubble icon -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <rect x="1" y="1" width="14" height="10" rx="2" fill="currentColor" opacity="0.85"/>
                    <path d="M4 15l2.5-4h3L12 15" fill="currentColor" opacity="0.5"/>
                </svg>
                SMS
            </a>
            <a href="/admin/analytics" class="<?= str_starts_with($currentUri, '/admin/analytics') ? 'active' : '' ?>">
                <!-- Bar chart icon -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <rect x="1" y="9" width="3" height="6" rx="0.5" fill="currentColor"/>
                    <rect x="6" y="5" width="3" height="10" rx="0.5" fill="currentColor"/>
                    <rect x="11" y="2" width="3" height="13" rx="0.5" fill="currentColor"/>
                </svg>
                Analytics
            </a>

            <!-- Settings -->
            <div class="admin-nav-section">Settings</div>
            <a href="/admin/settings" class="<?= str_starts_with($currentUri, '/admin/settings') ? 'active' : '' ?>">
                <!-- Gear icon: circle with notches -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="8" cy="8" r="2.5" fill="currentColor"/>
                    <path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.05 3.05l1.42 1.42M11.53 11.53l1.42 1.42M11.53 4.47l1.42-1.42M3.05 12.95l1.42-1.42"
                          stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                Site Settings
            </a>
            <a href="/admin/admin-users" class="<?= str_starts_with($currentUri, '/admin/admin-users') ? 'active' : '' ?>">
                <!-- Shield with person icon -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M8 1L2 3.5v4C2 11 5 13.5 8 15c3-1.5 6-4 6-7.5v-4L8 1z" fill="currentColor" opacity="0.25" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                    <circle cx="8" cy="6.5" r="1.75" fill="currentColor"/>
                    <path d="M5 11.5c0-1.66 1.34-3 3-3s3 1.34 3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
                Admin Users
            </a>
            <a href="/admin/change-password" class="<?= $currentUri === '/admin/change-password' ? 'active' : '' ?>">
                <!-- Key icon -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="5.5" cy="8" r="3.5" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M8.5 8h5M11.5 8v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                </svg>
                Change Password
            </a>
            <a href="/admin/logout">
                <!-- Arrow-right-from-box logout icon -->
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M6 3H3a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="M10 5l3 3-3 3M13 8H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Sign Out
            </a>

        </nav>
    </aside>

    <!-- Mobile sidebar overlay backdrop -->
    <div class="admin-sidebar-overlay" id="admin-sidebar-overlay"></div>

    <!-- ============================================================
         MAIN AREA
         ============================================================ -->
    <div class="admin-main">

        <!-- Topbar -->
        <div class="admin-topbar">
            <div style="display:flex; align-items:center; gap:1rem">
                <!-- Mobile hamburger button -->
                <button
                    class="admin-menu-toggle"
                    id="admin-menu-toggle"
                    aria-label="Toggle navigation"
                    aria-controls="admin-sidebar"
                    aria-expanded="false"
                >
                    <svg width="22" height="22" viewBox="0 0 22 22" fill="none" aria-hidden="true">
                        <line x1="3" y1="6" x2="19" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="3" y1="11" x2="19" y2="11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="3" y1="16" x2="19" y2="16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
                <h1 class="admin-topbar-title"><?= htmlspecialchars($pageTitle) ?></h1>
            </div>
            <div style="display:flex; align-items:center; gap:1rem">
                <span style="font-size:0.875rem; color:#666">
                    <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?>
                </span>
                <a href="/admin/logout" class="admin-btn admin-btn-ghost" style="font-size:0.75rem">
                    Sign Out
                </a>
            </div>
        </div>

        <!-- Page content -->
        <div class="admin-content">
            <?= $content ?>
        </div>

    </div><!-- /.admin-main -->

</div><!-- /.admin-layout -->

<!-- Alpine.js -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="/public/assets/js/admin.js"></script>

</body>
</html>
