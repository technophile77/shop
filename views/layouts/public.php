<?php
/**
 * views/layouts/public.php — Master layout for all public-facing pages.
 *
 * Wraps every public page in the complete HTML5 document structure,
 * injecting brand colours, analytics snippets, navigation, and footer.
 * The inner page content is received via the $content variable, which
 * BaseController::render() populates from the view file's output buffer.
 *
 * Variables available (injected by BaseController::render()):
 *   string                $lang       Current locale ('en' or 'es').
 *   array<string,string>  $settings   All site_settings rows.
 *   Closure               $config     fn(string $key, mixed $default = null): mixed
 *   Closure               $t          fn(string $key): string
 *   string                $pageTitle  Page-specific title (falls back to BUSINESS_NAME).
 *   string                $metaDesc   Page meta description.
 *   string                $bodyClass  Optional CSS class(es) for <body>.
 *   string                $content    Rendered inner view HTML.
 *
 * @see \App\Controllers\BaseController::render()
 */

// Expose CSRF token via <meta> for Alpine.js fetch() components.
$_layoutCsrfToken = (new \App\Core\Request())->csrfToken();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDesc) ?>">
    <?php
    // LANG_STRIPPED_PATH is set by index.php after removing the /en/ or /es/ prefix.
    // Use it to build correct lang-prefixed canonical/hreflang/OG URLs.
    $_seoBasePath = $_SERVER['LANG_STRIPPED_PATH'] ?? '/';
    $_seoAppUrl   = rtrim((string) \App\Core\Config::get('APP_URL', ''), '/');
    $_seoPageUrl  = $_seoAppUrl . '/' . $lang . ($_seoBasePath === '/' ? '/' : $_seoBasePath);
    ?>
    <link rel="canonical"                     href="<?= htmlspecialchars($_seoPageUrl) ?>">
    <link rel="alternate" hreflang="en"       href="<?= htmlspecialchars($_seoAppUrl . '/en' . ($_seoBasePath === '/' ? '/' : $_seoBasePath)) ?>">
    <link rel="alternate" hreflang="es"       href="<?= htmlspecialchars($_seoAppUrl . '/es' . ($_seoBasePath === '/' ? '/' : $_seoBasePath)) ?>">
    <link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars($_seoAppUrl . '/en' . ($_seoBasePath === '/' ? '/' : $_seoBasePath)) ?>">

    <!-- Open Graph -->
    <meta property="og:type"        content="website">
    <meta property="og:site_name"   content="<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?>">
    <meta property="og:title"       content="<?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDesc ?: ($lang === 'es' ? 'Arreglos florales y ramos personalizados en Tulsa, OK.' : 'Custom bouquets and fresh flowers in Tulsa, OK.')) ?>">
    <meta property="og:url"         content="<?= htmlspecialchars($_seoPageUrl) ?>">
    <meta property="og:image"       content="<?= htmlspecialchars($ogImage ?? ($_seoAppUrl . '/public/assets/images/header.jpg')) ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="<?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDesc ?: ($lang === 'es' ? 'Arreglos florales y ramos personalizados en Tulsa, OK.' : 'Custom bouquets and fresh flowers in Tulsa, OK.')) ?>">
    <meta name="twitter:image"       content="<?= htmlspecialchars($ogImage ?? ($_seoAppUrl . '/public/assets/images/header.jpg')) ?>">

    <meta name="csrf-token" content="<?= htmlspecialchars($_layoutCsrfToken) ?>">

    <?php if ($gscVerification = \App\Core\Config::get('GOOGLE_SITE_VERIFICATION')): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars($gscVerification) ?>">
    <?php endif; ?>

    <link rel="icon" href="/favicon.ico" sizes="48x48 32x32 16x16">

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

    <link rel="stylesheet" href="/public/assets/css/main.css">

    <?php if (\App\Core\Config::get('GA4_MEASUREMENT_ID')): ?>
    <!-- Google Analytics 4 -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars(\App\Core\Config::get('GA4_MEASUREMENT_ID')) ?>"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag('js', new Date());
    gtag('config', '<?= htmlspecialchars(\App\Core\Config::get('GA4_MEASUREMENT_ID')) ?>');
    </script>
    <?php endif; ?>

    <?php if (\App\Core\Config::get('META_PIXEL_ID')): ?>
    <!-- Meta Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init','<?= htmlspecialchars(\App\Core\Config::get('META_PIXEL_ID')) ?>');
    fbq('track','PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=<?= htmlspecialchars(\App\Core\Config::get('META_PIXEL_ID')) ?>&ev=PageView&noscript=1"
        alt=""></noscript>
    <?php endif; ?>

    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Florist",
      "name": "<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', ''), ENT_QUOTES) ?>",
      "url": "<?= htmlspecialchars(\App\Core\Config::get('APP_URL', ''), ENT_QUOTES) ?>",
      "telephone": "<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_PHONE', ''), ENT_QUOTES) ?>",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_STREET_ADDRESS', ''), ENT_QUOTES) ?>",
        "addressLocality": "<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_CITY', 'Tulsa'), ENT_QUOTES) ?>",
        "addressRegion": "<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_STATE', 'OK'), ENT_QUOTES) ?>",
        "postalCode": "<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_POSTAL_CODE', ''), ENT_QUOTES) ?>",
        "addressCountry": "US"
      },
      "geo": {
        "@type": "GeoCoordinates",
        "latitude": <?= (float) \App\Core\Config::get('BUSINESS_LAT', 36.0814) ?>,
        "longitude": <?= (float) \App\Core\Config::get('BUSINESS_LNG', -95.9987) ?>
      },
      "sameAs": [
        <?php
        $_schemaLinks = array_values(array_filter([
            \App\Core\Config::get('FACEBOOK_URL'),
            \App\Core\Config::get('INSTAGRAM_URL',
                'https://www.instagram.com/' . \App\Core\Config::get('INSTAGRAM_HANDLE', '')),
        ]));
        echo implode(",\n        ", array_map(
            fn($u) => '"' . htmlspecialchars((string) $u, ENT_QUOTES) . '"',
            $_schemaLinks
        ));
        ?>
      ],
      "priceRange": "<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_PRICE_RANGE', '$$'), ENT_QUOTES) ?>"
      <?php
      // Emit openingHoursSpecification only when at least one day is configured.
      $_days = ['Mo','Tu','We','Th','Fr','Sa','Su'];
      $_dayNames = ['mon','tue','wed','thu','fri','sat','sun'];
      $_hoursEntries = [];
      foreach ($_days as $_i => $_dayCode):
          $_opens  = \App\Core\Settings::get('business_hours_' . $_dayNames[$_i] . '_open');
          $_closes = \App\Core\Settings::get('business_hours_' . $_dayNames[$_i] . '_close');
          if ($_opens && $_closes):
              $_hoursEntries[] = '{"@type":"OpeningHoursSpecification","dayOfWeek":"https://schema.org/' . $_dayCode . '","opens":"' . htmlspecialchars($_opens, ENT_QUOTES) . '","closes":"' . htmlspecialchars($_closes, ENT_QUOTES) . '"}';
          endif;
      endforeach;
      if (!empty($_hoursEntries)):
      ?>,
      "openingHoursSpecification": [
        <?= implode(",\n        ", $_hoursEntries) ?>

      ]
      <?php endif; ?>
    }
    </script>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">

<!-- ============================================================
     NAVIGATION
     ============================================================ -->
<nav class="site-nav" role="navigation" aria-label="Main navigation">
    <div class="container nav-inner">

        <!-- Logo -->
        <a href="/<?= $lang ?>/" class="nav-logo" aria-label="<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?> — Home">
            <img src="/public/assets/images/logo.jpg"
                 alt="<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?> logo">
        </a>

        <!-- Desktop nav links -->
        <ul class="nav-links" role="list">
            <li><a href="/<?= $lang ?>"><?= htmlspecialchars(__t('nav.home')) ?></a></li>
            <li><a href="/<?= $lang ?>/flowers/occasions"><?= htmlspecialchars(__t('nav.occasions')) ?></a></li>
            <li><a href="/<?= $lang ?>/products"><?= htmlspecialchars(__t('nav.products')) ?></a></li>
            <li>
                <a href="/<?= $lang ?>/order" class="btn btn-accent btn-sm">
                    <?= htmlspecialchars(\App\Core\Settings::get('order_button_text_' . $lang, __t('nav.order'))) ?>
                </a>
            </li>
            <li><a href="/<?= $lang ?>/about"><?= htmlspecialchars(__t('nav.about')) ?></a></li>
            <li><a href="/<?= $lang ?>/contact"><?= htmlspecialchars(__t('nav.contact')) ?></a></li>
            <?php $__cartCount = \App\Support\CartSession::count(); ?>
            <li>
                <a href="/<?= $lang ?>/cart">
                    <?= htmlspecialchars(__t('nav.cart')) ?><?php if ($__cartCount > 0): ?>
                    <span class="cart-badge" style="display:inline-block; min-width:1.2rem; padding:0 0.35rem; margin-left:0.25rem; border-radius:999px; background:var(--color-accent); color:#fff; font-size:0.72rem; text-align:center"><?= $__cartCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php if (\App\Core\Config::get('DOORDASH_ORDER_ONLINE_URL')): ?>
            <li>
                <a href="<?= htmlspecialchars(\App\Core\Config::get('DOORDASH_ORDER_ONLINE_URL')) ?>"
                   class="btn btn-accent btn-sm"
                   target="_blank"
                   rel="noopener noreferrer">
                    <?= htmlspecialchars($lang === 'es' ? 'Ordenar en Línea' : 'Order Online') ?>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <!-- Desktop nav actions: language toggle -->
        <div class="nav-actions">
            <a href="/lang/<?= htmlspecialchars($lang === 'en' ? 'es' : 'en') ?>"
               class="lang-toggle"
               aria-label="Switch language">
                <?= htmlspecialchars(__t('nav.lang_switch')) ?>
            </a>

            <!-- Mobile hamburger -->
            <button class="nav-toggle"
                    aria-label="Toggle mobile navigation"
                    aria-expanded="false"
                    aria-controls="nav-mobile">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>

    </div><!-- /.nav-inner -->
</nav>

<!-- Mobile drawer -->
<div id="nav-mobile" class="nav-mobile" role="dialog" aria-label="Mobile navigation">
    <nav class="nav-mobile-links" role="list">
        <a href="/<?= $lang ?>"><?= htmlspecialchars(__t('nav.home')) ?></a>
        <a href="/<?= $lang ?>/flowers/occasions"><?= htmlspecialchars(__t('nav.occasions')) ?></a>
        <a href="/<?= $lang ?>/products"><?= htmlspecialchars(__t('nav.products')) ?></a>
        <a href="/<?= $lang ?>/order"><?= htmlspecialchars(\App\Core\Settings::get('order_button_text_' . $lang, __t('nav.order'))) ?></a>
        <a href="/<?= $lang ?>/about"><?= htmlspecialchars(__t('nav.about')) ?></a>
        <a href="/<?= $lang ?>/contact"><?= htmlspecialchars(__t('nav.contact')) ?></a>
        <a href="/<?= $lang ?>/cart"><?= htmlspecialchars(__t('nav.cart')) ?><?php $__mc = \App\Support\CartSession::count(); if ($__mc > 0): ?> (<?= $__mc ?>)<?php endif; ?></a>
        <?php if (\App\Core\Config::get('DOORDASH_ORDER_ONLINE_URL')): ?>
        <a href="<?= htmlspecialchars(\App\Core\Config::get('DOORDASH_ORDER_ONLINE_URL')) ?>"
           target="_blank"
           rel="noopener noreferrer">
            <?= htmlspecialchars(\App\Core\Settings::get('doordash_button_label_' . $lang, 'Order Online')) ?>
        </a>
        <?php endif; ?>
        <a href="/lang/<?= htmlspecialchars($lang === 'en' ? 'es' : 'en') ?>"
           class="lang-toggle"
           aria-label="Switch language"><?= htmlspecialchars(__t('nav.lang_switch')) ?></a>
    </nav>
</div>

<!-- ============================================================
     MAIN CONTENT
     ============================================================ -->
<main id="main-content">
    <?= $content ?>
</main>

<!-- ============================================================
     WHATSAPP FLOATING BUTTON
     ============================================================ -->
<?php if (\App\Core\Settings::get('show_whatsapp_button', '1') === '1' && \App\Core\Config::get('WHATSAPP_PHONE')): ?>
<a href="https://wa.me/<?= htmlspecialchars(\App\Core\Config::get('WHATSAPP_PHONE')) ?>"
   class="whatsapp-btn"
   target="_blank"
   rel="noopener noreferrer"
   aria-label="<?= htmlspecialchars(__t('contact.whatsapp_cta')) ?>">
    <!-- WhatsApp SVG -->
    <svg viewBox="0 0 32 32" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M16 3C9.373 3 4 8.373 4 15c0 2.385.693 4.61 1.895 6.488L4 29l7.733-1.867A12.94 12.94 0 0 0 16 28c6.627 0 12-5.373 12-12S22.627 3 16 3zm0 22a10.94 10.94 0 0 1-5.568-1.52l-.398-.236-4.594 1.109 1.133-4.48-.26-.412A10.94 10.94 0 0 1 5.001 15C5 9.477 9.477 5 16 5s11 4.477 11 11-4.477 11-11 11zm6.07-8.243c-.332-.166-1.963-.969-2.267-1.079-.305-.11-.527-.166-.749.166-.222.332-.86 1.079-1.054 1.3-.194.222-.388.249-.72.083-.332-.166-1.402-.516-2.67-1.646-.986-.88-1.652-1.965-1.846-2.297-.194-.332-.021-.512.146-.678.15-.149.332-.388.499-.582.166-.194.221-.332.332-.554.111-.222.055-.416-.028-.582-.083-.166-.748-1.802-1.025-2.468-.27-.648-.545-.56-.748-.57l-.638-.012c-.222 0-.582.083-.887.416-.305.332-1.163 1.137-1.163 2.772s1.19 3.214 1.357 3.436c.166.222 2.344 3.578 5.68 5.019.794.342 1.412.547 1.894.7.796.254 1.521.218 2.094.132.639-.096 1.963-.803 2.24-1.578.276-.776.276-1.44.193-1.578-.082-.138-.304-.221-.637-.387z"/>
    </svg>
</a>
<?php endif; ?>

<!-- ============================================================
     FOOTER
     ============================================================ -->
<footer class="site-footer" role="contentinfo">
    <div class="container">
        <div class="footer-grid">

            <!-- Brand column -->
            <div class="footer-brand">
                <div class="footer-logo">
                    <a href="/<?= $lang ?>/" aria-label="<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?> — Home">
                        <img src="/public/assets/images/logo.jpg"
                             alt="<?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?> logo">
                    </a>
                </div>
                <p class="footer-tagline">
                    <?= htmlspecialchars(
                        \App\Core\Settings::get('tagline_' . $lang,
                        \App\Core\Config::get('BUSINESS_TAGLINE', __t('footer.tagline')))
                    ) ?>
                </p>
                <!-- Social icons -->
                <div class="footer-social" style="margin-top:1.25rem">
                    <?php if (\App\Core\Config::get('FACEBOOK_URL')): ?>
                    <a href="<?= htmlspecialchars(\App\Core\Config::get('FACEBOOK_URL')) ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="Follow us on Facebook">
                        <!-- Facebook 'f' icon -->
                        <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M22 12C22 6.477 17.523 2 12 2S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879V14.89h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.989C18.343 21.129 22 16.99 22 12z"/>
                        </svg>
                    </a>
                    <?php endif; ?>

                    <?php
                    $igUrl = \App\Core\Config::get('INSTAGRAM_URL',
                        'https://www.instagram.com/' . \App\Core\Config::get('INSTAGRAM_HANDLE', ''));
                    ?>
                    <?php if ($igUrl): ?>
                    <a href="<?= htmlspecialchars($igUrl) ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="Follow us on Instagram">
                        <!-- Instagram camera outline icon -->
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
                            <circle cx="12" cy="12" r="4"/>
                            <circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>
                        </svg>
                    </a>
                    <?php endif; ?>

                    <?php if (\App\Core\Config::get('GOOGLE_REVIEW_URL')): ?>
                    <!-- Google review link -->
                    <a href="<?= htmlspecialchars(\App\Core\Config::get('GOOGLE_REVIEW_URL', '')) ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="Leave a Google review"
                       style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.1);color:inherit;transition:background 0.2s">
                        <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:20px;height:20px">
                            <path d="M12 11h8.533c.044.385.067.78.067 1.184 0 2.734-.98 5.048-2.678 6.61C16.25 20.403 14.3 21.2 12 21.2c-5.09 0-9.2-4.11-9.2-9.2s4.11-9.2 9.2-9.2c2.483 0 4.57.914 6.177 2.408L16.2 7.2C14.95 6.037 13.56 5.2 12 5.2 7.91 5.2 4.6 8.51 4.6 12.6s3.31 7.4 7.4 7.4c4.084 0 6.8-2.867 6.8-6.8H12v-2.2z"/>
                        </svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick links -->
            <div class="footer-nav">
                <p class="footer-heading"><?= htmlspecialchars(__t('nav.home')) ?></p>
                <ul class="footer-links" role="list">
                    <li><a href="/<?= $lang ?>"><?= htmlspecialchars(__t('nav.home')) ?></a></li>
                    <li><a href="/<?= $lang ?>/products"><?= htmlspecialchars(__t('nav.products')) ?></a></li>
                    <li><a href="/<?= $lang ?>/order"><?= htmlspecialchars(\App\Core\Settings::get('order_button_text_' . $lang, __t('nav.order'))) ?></a></li>
                    <li><a href="/<?= $lang ?>/about"><?= htmlspecialchars(__t('nav.about')) ?></a></li>
                    <li><a href="/<?= $lang ?>/contact"><?= htmlspecialchars(__t('nav.contact')) ?></a></li>
                    <li><a href="/<?= $lang ?>/delivery-areas"><?= htmlspecialchars(__t('nav.areas')) ?></a></li>
                    <?php if (\App\Core\Settings::get('show_doordash_button', '1') === '1' && \App\Core\Config::get('DOORDASH_STORE_URL')): ?>
                    <li>
                        <a href="<?= htmlspecialchars(\App\Core\Config::get('DOORDASH_STORE_URL')) ?>"
                           target="_blank"
                           rel="noopener noreferrer">
                            <?= htmlspecialchars(\App\Core\Settings::get('doordash_button_label_' . $lang, __t('footer.doordash_cta'))) ?>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Service areas — top city pages for internal linking / local SEO -->
            <div class="footer-nav">
                <p class="footer-heading"><?= htmlspecialchars(__t('nav.areas')) ?></p>
                <ul class="footer-links" role="list">
                    <?php foreach (array_slice(\App\Support\LocalArea::areas(), 0, 4, true) as $_areaSlug => $_area): ?>
                    <li>
                        <a href="/<?= $lang ?>/flower-delivery-<?= htmlspecialchars($_areaSlug) ?>">
                            <?= htmlspecialchars((string) $_area['name']) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                    <li>
                        <a href="/<?= $lang ?>/delivery-areas">
                            <?= $lang === 'es' ? 'Ver todas las áreas' : 'View all areas' ?>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Contact / delivery info -->
            <div class="footer-info">
                <p class="footer-heading"><?= htmlspecialchars(__t('nav.contact')) ?></p>
                <ul class="footer-links" role="list">
                    <!-- NAP identity block — matches Google Business Profile exactly -->
                    <li style="margin-bottom:1rem; line-height:1.6; font-size:0.9rem; color:rgba(255,255,255,0.8)">
                        <strong style="display:block; color:#fff; margin-bottom:0.25rem">
                            <?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?>
                        </strong>
                        <span>
                            <?= htmlspecialchars(\App\Core\Config::get('BUSINESS_ADDRESS', '')) ?>
                            <span style="color:rgba(255,255,255,0.5); font-size:0.8rem; display:block; margin-top:0.1rem">
                                <?= $lang === 'es' ? '(Estudio Privado — Solo Recogida y Citas)' : '(Private Studio — Pickup &amp; Appointment Only)' ?>
                            </span>
                        </span><br>
                        <?php if (\App\Core\Config::get('BUSINESS_PHONE')): ?>
                        <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', (string) \App\Core\Config::get('BUSINESS_PHONE', ''))) ?>"
                           style="color:rgba(255,255,255,0.8); text-decoration:none">
                            <?= htmlspecialchars((string) \App\Core\Config::get('BUSINESS_PHONE', '')) ?>
                        </a>
                        <?php endif; ?>
                    </li>
                    <?php if (\App\Core\Config::get('WHATSAPP_PHONE')): ?>
                    <li>
                        <a href="https://wa.me/<?= htmlspecialchars(\App\Core\Config::get('WHATSAPP_PHONE')) ?>"
                           target="_blank"
                           rel="noopener noreferrer">
                            <?= htmlspecialchars(__t('contact.whatsapp_cta')) ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (\App\Core\Config::get('FACEBOOK_URL')): ?>
                    <li>
                        <a href="<?= htmlspecialchars(\App\Core\Config::get('FACEBOOK_URL')) ?>"
                           target="_blank"
                           rel="noopener noreferrer">
                            <?= htmlspecialchars(__t('contact.facebook_cta')) ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php
                    $bizAddr  = \App\Core\Config::get('BUSINESS_ADDRESS', '');
                    $baseMiles = (int) \App\Core\Config::get('BUSINESS_DELIVERY_BASE_MILES', 5);
                    $baseFee   = (int) \App\Core\Config::get('BUSINESS_DELIVERY_BASE_FEE', 10);
                    $perMile   = (int) \App\Core\Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1);
                    ?>
                    <?php if ($bizAddr !== ''): ?>
                    <li style="font-size:0.85rem; color:rgba(255,255,255,0.65); margin-top:0.75rem; line-height:1.4">
                        <span style="color:rgba(255,255,255,0.4); display:block; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.2rem">
                            <?= htmlspecialchars(__t('footer.pickup_address')) ?>
                        </span>
                        <?= htmlspecialchars($bizAddr) ?>
                    </li>
                    <?php endif; ?>
                    <?php if ($baseFee > 0): ?>
                    <li style="font-size:0.85rem; color:rgba(255,255,255,0.65); margin-top:0.5rem; line-height:1.4">
                        <span style="color:rgba(255,255,255,0.4); display:block; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.2rem">
                            <?= htmlspecialchars(__t('footer.delivery_info')) ?>
                        </span>
                        <?= $lang === 'es'
                            ? htmlspecialchars("Dentro de {$baseMiles} millas: \${$baseFee} · +\${$perMile} por milla adicional")
                            : htmlspecialchars("Within {$baseMiles} miles: \${$baseFee} · +\${$perMile} per additional mile") ?>
                    </li>
                    <?php endif; ?>
                    <?php if ($bizAddr === '' && $baseFee === 0): ?>
                    <li style="color:rgba(255,255,255,0.5); font-size:0.9rem">
                        <?= htmlspecialchars(__t('footer.delivery')) ?>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

        </div><!-- /.footer-grid -->

        <!-- Bottom bar -->
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(\App\Core\Config::get('BUSINESS_NAME', '')) ?>.
            <?= htmlspecialchars(__t('footer.rights')) ?>.</p>
        </div>

    </div><!-- /.container -->
</footer>

<!-- ============================================================
     SCRIPTS
     ============================================================ -->

<!-- Alpine.js -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<?php if (\App\Core\Config::get('DOORDASH_ENABLED') === 'true' && \App\Core\Settings::get('show_doordash_button', '1') === '1' && \App\Core\Config::get('DOORDASH_STORE_ID')): ?>
<!-- DoorDash Storefront SDK -->
<script src="https://web-apps.cdn4dd.com/webapps/sdk-storefront/latest/sdk.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    StorefrontSDK.executeCommand('renderFloatingButton', {
        businessId:            '<?= htmlspecialchars(\App\Core\Config::get('DOORDASH_STORE_ID')) ?>',
        businessSlug:          '<?= htmlspecialchars(\App\Core\Config::get('DOORDASH_BUSINESS_SLUG')) ?>',
        buttonBackgroundColor: '<?= htmlspecialchars(\App\Core\Config::get('COLOR_ACCENT', '#D4409A')) ?>',
        position:              'bottom',
        alignment:             'right'
    });
});
</script>
<?php endif; ?>

<?php if (\App\Core\Config::get('TAWKTO_PROPERTY_ID') && \App\Core\Config::get('TAWKTO_WIDGET_ID')): ?>
<!-- Tawk.to live chat widget -->
<script>
var Tawk_API = Tawk_API || {}, Tawk_LoadStart = new Date();
Tawk_API.onLoad = function () {
    Tawk_API.setAttributes({ language: '<?= $lang === 'es' ? 'Español' : 'English' ?>' }, function () {});
};
(function () {
    var s1 = document.createElement('script'),
        s0 = document.getElementsByTagName('script')[0];
    s1.async   = true;
    s1.src     = 'https://embed.tawk.to/<?= htmlspecialchars(\App\Core\Config::get('TAWKTO_PROPERTY_ID')) ?>/<?= htmlspecialchars(\App\Core\Config::get('TAWKTO_WIDGET_ID')) ?>';
    s1.charset = 'UTF-8';
    s1.setAttribute('crossorigin', '*');
    s0.parentNode.insertBefore(s1, s0);
})();
</script>
<?php endif; ?>

<!-- Toast notification container (populated by main.js) -->
<div class="toast-container" id="toast-container" aria-live="polite" aria-atomic="true"></div>

<script src="/public/assets/js/main.js" defer></script>

</body>
</html>
