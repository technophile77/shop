<?php
/**
 * views/public/about.php — About / Our Story page.
 *
 * Rendered inside views/layouts/public.php. Outputs only the page body content.
 *
 * Variables injected by BaseController::render() via AboutController::index():
 *   string $lang      Current locale ('en' or 'es').
 *   string $pageTitle Localised page title from site settings.
 *
 * @see \App\Controllers\AboutController::index()
 */

use App\Core\Config;
use App\Core\Settings;

$fbUrl  = Config::get('FACEBOOK_URL', '');
$igUrl  = Config::get('INSTAGRAM_URL', 'https://www.instagram.com/' . Config::get('INSTAGRAM_HANDLE', ''));
$igHandle = Config::get('INSTAGRAM_HANDLE', '');
$city   = Config::get('BUSINESS_CITY', '');
$area   = Config::get('BUSINESS_DELIVERY_AREA', '');
$ddUrl  = Config::get('DOORDASH_STORE_URL', '');
$ddEnabled = Config::get('DOORDASH_ENABLED') === 'true' && Settings::get('show_doordash_button', '1') === '1';
$aboutText = Settings::get('about_text_' . $lang, Config::get($lang === 'es' ? 'BUSINESS_ABOUT_ES' : 'BUSINESS_ABOUT_EN', ''));
?>

<!-- ============================================================
     HERO HEADER — dark strip
     ============================================================ -->
<div class="section section--dark" style="padding: 3rem 0 2.5rem">
    <div class="container text-center">
        <span class="eyebrow label" style="color:var(--color-muted)">
            <?= htmlspecialchars(Config::get('BUSINESS_NAME', '')) ?>
        </span>
        <h1 style="color:var(--color-text-light)">
            <?= htmlspecialchars(Settings::get('about_page_title_' . $lang, 'Our Story')) ?>
        </h1>
    </div>
</div>

<!-- ============================================================
     STORY SECTION — two-column: text + featured image
     ============================================================ -->
<section class="section section--light">
    <div class="container">
        <div class="grid-2" style="align-items:center; gap:4rem">

            <!-- Text column -->
            <div>
                <span class="eyebrow label" style="margin-bottom:1rem">
                    <?= htmlspecialchars(__t('about.title')) ?>
                </span>

                <?php if ($aboutText !== ''): ?>
                <div style="font-size:1.05rem; line-height:1.85; color:var(--color-text-dark)">
                    <?php
                    // Each paragraph separated by double newline gets its own <p>.
                    $paragraphs = preg_split('/\n{2,}/', trim($aboutText));
                    foreach ($paragraphs as $para):
                        $para = trim($para);
                        if ($para === '') continue;
                    ?>
                    <p><?= htmlspecialchars($para) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Location / delivery info -->
                <?php if ($city !== '' || $area !== ''): ?>
                <div style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--color-border)">
                    <p style="color:var(--color-muted); font-size:0.9rem; margin-bottom:0.25rem">
                        <?= $lang === 'es' ? 'Área de entrega' : 'Delivery &amp; pickup area' ?>
                    </p>
                    <p style="font-weight:600; color:var(--color-text-dark); margin-bottom:0">
                        <?php
                        $locationParts = array_filter([$city, $area]);
                        echo htmlspecialchars(implode(' — ', $locationParts));
                        ?>
                    </p>
                </div>
                <?php endif; ?>

                <!-- Social links -->
                <?php if ($fbUrl !== '' || $igUrl !== ''): ?>
                <div style="margin-top:2rem; display:flex; gap:1rem; flex-wrap:wrap">
                    <?php if ($fbUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($fbUrl) ?>"
                       class="btn btn-outline"
                       target="_blank"
                       rel="noopener noreferrer"
                       style="gap:0.5rem">
                        <!-- Facebook icon -->
                        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"
                             xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M22 12C22 6.477 17.523 2 12 2S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879V14.89h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.989C18.343 21.129 22 16.99 22 12z"/>
                        </svg>
                        Facebook
                    </a>
                    <?php endif; ?>

                    <?php if ($igUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($igUrl) ?>"
                       class="btn btn-outline"
                       target="_blank"
                       rel="noopener noreferrer"
                       style="gap:0.5rem">
                        <!-- Instagram icon -->
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" width="18" height="18"
                             xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
                            <circle cx="12" cy="12" r="4"/>
                            <circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>
                        </svg>
                        <?php if ($igHandle !== ''): ?>
                            @<?= htmlspecialchars($igHandle) ?>
                        <?php else: ?>
                            Instagram
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Delivery / pickup note -->
                <p style="margin-top:1.5rem; color:var(--color-muted); font-size:0.9rem">
                    <?= htmlspecialchars(__t('footer.delivery')) ?>
                </p>
            </div>

            <!-- Featured image column -->
            <div>
                <img src="/public/assets/images/header.jpg"
                     alt="<?= htmlspecialchars(Config::get('BUSINESS_NAME', '')) ?> — <?= htmlspecialchars(__t('about.title')) ?>"
                     style="border-radius:8px; box-shadow:0 4px 24px rgba(0,0,0,0.15); width:100%; aspect-ratio:4/3; object-fit:cover">
            </div>

        </div><!-- /.grid-2 -->
    </div><!-- /.container -->
</section>

<!-- ============================================================
     DOORDASH SECTION — dark strip (only when DoorDash is enabled)
     ============================================================ -->
<?php if ($ddEnabled && $ddUrl !== ''): ?>
<section class="section section--dark" style="padding:4rem 0; text-align:center">
    <div class="container">
        <span class="eyebrow label" style="color:var(--color-muted); margin-bottom:1rem">
            DoorDash
        </span>
        <h2 style="color:var(--color-text-light); margin-bottom:1rem">
            <?= $lang === 'es' ? 'Pídenos en DoorDash' : 'Order Us on DoorDash' ?>
        </h2>
        <p style="color:rgba(255,255,255,0.7); max-width:520px; margin:0 auto 2.5rem">
            <?= $lang === 'es'
                ? 'Ahora puedes pedir nuestras flores directamente desde DoorDash y recibirlas en tu puerta.'
                : 'You can now order our arrangements directly through DoorDash and have them delivered straight to your door.'
            ?>
        </p>
        <a href="<?= htmlspecialchars($ddUrl) ?>"
           class="btn btn-accent btn-lg"
           target="_blank"
           rel="noopener noreferrer">
            <?= htmlspecialchars(Settings::get('doordash_button_label_' . $lang, 'Order Now on DoorDash')) ?>
        </a>
    </div>
</section>
<?php endif; ?>

<!-- ============================================================
     INSTAGRAM CTA — light
     ============================================================ -->
<?php if ($igUrl !== ''): ?>
<section class="section section--light" style="padding:3rem 0; text-align:center">
    <div class="container">
        <p style="color:var(--color-muted); font-size:1rem; margin-bottom:1.25rem">
            <?= htmlspecialchars(__t('about.instagram_cta')) ?>
        </p>
        <a href="<?= htmlspecialchars($igUrl) ?>"
           class="btn btn-primary"
           target="_blank"
           rel="noopener noreferrer">
            <?php if ($igHandle !== ''): ?>
                @<?= htmlspecialchars($igHandle) ?>
            <?php else: ?>
                Instagram
            <?php endif; ?>
        </a>
    </div>
</section>
<?php endif; ?>
