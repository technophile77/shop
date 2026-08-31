<?php
/**
 * views/public/home.php — Home page content.
 *
 * Rendered inside views/layouts/public.php; outputs only the <main> body content.
 * All variables are injected by BaseController::render() and HomeController::index().
 *
 * Available variables:
 *   string                $lang       Current locale ('en' or 'es').
 *   array<string,string>  $settings   All site_settings rows.
 *   Closure               $config     fn(string $key, mixed $default = null): mixed
 *   Closure               $t          fn(string $key): string
 *   array<int,array>      $products   Featured products from DB (up to 6 rows).
 *   array<int,array>      $productColorOptions Per-product flower-color options keyed by product id.
 *   array<int,array>      $paperColors Active paper-color rows for the add-to-cart panel.
 *   array<int,array>      $addons     Active add-on rows for the add-to-cart panel.
 *   string                $csrfToken  Session CSRF token for the signup and add-to-cart forms.
 *
 * @see \App\Controllers\HomeController::index()
 */

use App\Core\Config;
use App\Core\Settings;
?>

<!-- ============================================================
     SECTION 1: HERO
     ============================================================ -->
<section class="hero"
         style="background-image: url('/public/assets/images/header.jpg'); background-size:cover; background-position:center">
    <div class="hero-bg"></div>
    <div class="hero-content container">
        <span class="eyebrow label">
            <?= htmlspecialchars(Config::get('BUSINESS_NAME', '')) ?>
        </span>
        <h1>
            <?= htmlspecialchars(Settings::get('hero_headline_' . $lang, 'Handcrafted with Love')) ?>
        </h1>
        <p class="hero-subtitle">
            <?= htmlspecialchars(Settings::get('hero_subtext_' . $lang, '')) ?>
        </p>
        <div class="hero-actions">
            <a href="/<?= $lang ?>/order" class="btn btn-accent btn-lg">
                <?= htmlspecialchars(Settings::get('order_button_text_' . $lang, 'Request a Custom Bouquet')) ?>
            </a>
            <?php if (Settings::get('show_doordash_button', '1') === '1' && Config::get('DOORDASH_ENABLED') === 'true' && Config::get('DOORDASH_STORE_URL')): ?>
            <a href="<?= htmlspecialchars(Config::get('DOORDASH_STORE_URL')) ?>"
               class="btn btn-outline-light btn-lg"
               target="_blank"
               rel="noopener noreferrer">
                <?= htmlspecialchars(Settings::get('doordash_button_label_' . $lang, 'Order on DoorDash')) ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ============================================================
     SECTION 1.5: SHOP BY OCCASION
     ============================================================ -->
<?php if (!empty($occasionTiles)): ?>
<section class="section section--light">
    <div class="container">
        <div class="section-heading">
            <span class="eyebrow label"><?= $lang === 'es' ? 'Comprar por Ocasión' : 'Shop by Occasion' ?></span>
            <h2><?= $lang === 'es' ? 'Flores para Cada Ocasión' : 'Flowers for Every Occasion' ?></h2>
        </div>
        <?php include __DIR__ . '/_occasion_tiles.php'; ?>
        <div class="text-center" style="margin-top:2.5rem">
            <a href="/<?= $lang ?>/flowers/occasions" class="btn btn-primary">
                <?= $lang === 'es' ? 'Ver Todas las Ocasiones' : 'View All Occasions' ?>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============================================================
     SECTION 2: FEATURED PRODUCTS
     ============================================================ -->
<?php if (!empty($products)): ?>
<section class="section section--light">
    <div class="container">
        <div class="section-heading">
            <span class="eyebrow label"><?= htmlspecialchars(__t('home.featured_title')) ?></span>
            <h2><?= htmlspecialchars(Settings::get('products_page_title_' . $lang, __t('products.title'))) ?></h2>
        </div>

        <div class="product-grid">
            <?php $occasionLabel = ''; ?>
            <?php foreach ($products as $product): ?>
            <?php include __DIR__ . '/_bouquet_card.php'; ?>
            <?php endforeach; ?>
        </div>

        <div class="text-center" style="margin-top:3rem">
            <a href="/<?= $lang ?>/products" class="btn btn-primary">
                <?= htmlspecialchars(__t('home.view_all')) ?>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============================================================
     SECTION 3: PROMOTION SIGNUP STRIP
     ============================================================ -->
<section class="promo-strip">
    <div class="container">
        <span class="eyebrow label" style="color:rgba(255,255,255,0.4)">
            <?= htmlspecialchars(__t('signup.title')) ?>
        </span>
        <h2 style="margin-top:0.5rem">
            <?= htmlspecialchars(Settings::get('promo_strip_text_' . $lang, '')) ?>
        </h2>
        <p><?= htmlspecialchars(__t('signup.subtitle')) ?></p>

        <form id="signup-form"
              x-data="{
                  submitting: false,
                  done:       false,
                  error:      '',
                  async submit(event) {
                      this.submitting = true;
                      this.done       = false;
                      this.error      = '';
                      try {
                          const form = event.target;
                          const body = new URLSearchParams(new FormData(form));
                          const res  = await fetch('/signup', {
                              method:  'POST',
                              headers: { 'Accept': 'application/json',
                                         'Content-Type': 'application/x-www-form-urlencoded' },
                              body:    body.toString()
                          });
                          const json = await res.json();
                          if (res.ok && json.ok) {
                              this.done = true;
                              form.reset();
                          } else {
                              this.error = json.error || '<?= htmlspecialchars(__t('general.error')) ?>';
                          }
                      } catch (e) {
                          this.error = '<?= htmlspecialchars(__t('general.error')) ?>';
                      } finally {
                          this.submitting = false;
                      }
                  }
              }"
              @submit.prevent="submit($event)">

            <div style="display:flex; flex-wrap:wrap; gap:1rem; justify-content:center; max-width:600px; margin:0 auto 1rem">
                <input type="email"
                       name="email"
                       placeholder="<?= htmlspecialchars(__t('signup.email_label')) ?>"
                       style="flex:1; min-width:200px"
                       autocomplete="email">
                <input type="tel"
                       name="phone"
                       placeholder="<?= htmlspecialchars(__t('signup.phone_label')) ?>"
                       style="flex:1; min-width:180px"
                       autocomplete="tel">
            </div>

            <div style="display:flex; flex-wrap:wrap; gap:1rem; justify-content:center; margin-bottom:1.5rem">
                <label class="form-check" style="color:rgba(255,255,255,0.7)">
                    <input type="checkbox" name="opted_in_email" value="1">
                    <?= htmlspecialchars(__t('signup.email_consent')) ?>
                </label>
                <label class="form-check" style="color:rgba(255,255,255,0.7)">
                    <input type="checkbox" name="opted_in_sms" value="1">
                    <?= htmlspecialchars(__t('signup.sms_consent')) ?>
                </label>
            </div>

            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <button type="submit" class="btn btn-accent btn-lg" :disabled="submitting">
                <span x-show="!submitting"><?= htmlspecialchars(__t('signup.submit')) ?></span>
                <span x-show="submitting" style="display:none"><?= htmlspecialchars(__t('general.loading')) ?></span>
            </button>

            <p x-show="done"
               class="form-success"
               style="margin-top:1rem; background:rgba(56,161,105,0.15); color:#6ee7b7"
               x-cloak>
                <?= htmlspecialchars(__t('signup.success')) ?>
            </p>
            <p x-show="error"
               class="form-error"
               style="margin-top:1rem; color:#ff9999"
               x-text="error"
               x-cloak></p>
        </form>
    </div>
</section>

<!-- ============================================================
     SECTION 4: ABOUT SNIPPET
     ============================================================ -->
<section class="section section--dark">
    <div class="container">
        <div class="grid-2" style="align-items:center; gap:4rem">

            <!-- Text column -->
            <div>
                <span class="eyebrow label" style="color:var(--color-muted)">
                    <?= htmlspecialchars(__t('about.title')) ?>
                </span>
                <h2 style="color:var(--color-text-light); margin-bottom:1.5rem; margin-top:0.5rem">
                    <?= htmlspecialchars(Config::get('BUSINESS_NAME', '')) ?>
                </h2>
                <p style="color:rgba(255,255,255,0.75); font-size:1.1rem; line-height:1.8">
                    <?= htmlspecialchars(
                        Settings::get('about_text_' . $lang,
                        Config::get('BUSINESS_ABOUT_' . strtoupper($lang),
                        Config::get('BUSINESS_ABOUT_EN', '')))
                    ) ?>
                </p>
                <div style="margin-top:2rem; display:flex; gap:1rem; flex-wrap:wrap">
                    <?php if (Config::get('FACEBOOK_URL')): ?>
                    <a href="<?= htmlspecialchars(Config::get('FACEBOOK_URL')) ?>"
                       class="btn btn-outline-light"
                       target="_blank"
                       rel="noopener noreferrer">
                        Facebook
                    </a>
                    <?php endif; ?>
                    <?php
                    $igHandle = Config::get('INSTAGRAM_HANDLE', '');
                    $igUrl    = Config::get('INSTAGRAM_URL', $igHandle ? 'https://www.instagram.com/' . $igHandle : '');
                    ?>
                    <?php if ($igUrl): ?>
                    <a href="<?= htmlspecialchars($igUrl) ?>"
                       class="btn btn-outline-light"
                       target="_blank"
                       rel="noopener noreferrer">
                        <?php if ($igHandle): ?>
                        @<?= htmlspecialchars($igHandle) ?>
                        <?php else: ?>
                        Instagram
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Image column -->
            <div>
                <img src="/public/assets/images/header.jpg"
                     alt="<?= htmlspecialchars(Config::get('BUSINESS_NAME', '')) ?>"
                     style="border-radius:12px; width:100%; object-fit:cover; max-height:400px"
                     loading="lazy">
            </div>

        </div><!-- /.grid-2 -->
    </div><!-- /.container -->
</section>
