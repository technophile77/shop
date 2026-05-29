<?php
/**
 * views/layouts/admin-bare.php — Minimal admin layout for unauthenticated pages.
 *
 * Used for the login page and any other admin page that must render without the
 * sidebar (e.g. password-reset). Contains only the HTML shell, CSS, and scripts
 * — no navigation, sidebar, or topbar.
 *
 * Variables available (injected by BaseController::render()):
 *   string  $lang       Current locale ('en' or 'es').
 *   string  $pageTitle  Page-specific title.
 *   string  $bodyClass  Optional extra CSS class(es) for <body>.
 *   string  $content    Rendered inner view HTML.
 *
 * @see \App\Controllers\Admin\AuthController  Primary consumer of this layout.
 * @see \App\Controllers\BaseController::render()
 */
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?></title>

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

<?= $content ?>

<!-- Alpine.js -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script src="/public/assets/js/admin.js"></script>

</body>
</html>
