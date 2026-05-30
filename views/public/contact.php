<?php
/**
 * views/public/contact.php — Contact page.
 *
 * Rendered inside views/layouts/public.php. Outputs only the page body content.
 * Left column: contact form (Alpine.js async submit).
 * Right column: alternate contact methods + payment info.
 *
 * Variables injected by BaseController::render() via ContactController::index():
 *   string $lang      Current locale ('en' or 'es').
 *   string $pageTitle Localised page title from site settings.
 *   string $csrfToken Session CSRF token.
 *
 * @see \App\Controllers\ContactController::index()
 */

use App\Core\Config;
use App\Core\Settings;

$whatsappPhone = Config::get('WHATSAPP_PHONE', '');
$fbUrl         = Config::get('FACEBOOK_URL', '');
$igUrl         = Config::get('INSTAGRAM_URL', 'https://www.instagram.com/' . Config::get('INSTAGRAM_HANDLE', ''));
$zellePhone    = Config::get('ZELLE_PHONE', '');
$cashappTag    = Config::get('CASHAPP_TAG', '');
?>

<!-- ============================================================
     HEADER — dark strip
     ============================================================ -->
<div class="section section--dark" style="padding: 3rem 0 2rem">
    <div class="container text-center">
        <span class="eyebrow label" style="color:var(--color-muted)">
            <?= htmlspecialchars(Config::get('BUSINESS_NAME', '')) ?>
        </span>
        <h1 style="color:var(--color-text-light)">
            <?= htmlspecialchars(Settings::get('contact_page_title_' . $lang, __t('contact.title'))) ?>
        </h1>
        <p style="color:rgba(255,255,255,0.7); max-width:500px; margin:1rem auto 0">
            <?= htmlspecialchars(__t('contact.subtitle')) ?>
        </p>
    </div>
</div>

<!-- ============================================================
     TWO-COLUMN CONTENT — light background
     ============================================================ -->
<section class="section section--light">
    <div class="container">
        <div class="grid-2" style="gap:4rem; align-items:start">

            <!-- ================================================
                 LEFT COLUMN: Contact form
                 ================================================ -->
            <div>
                <div class="admin-card">
                    <h2 style="font-size:1.6rem; margin-bottom:1.5rem; color:var(--color-text-dark)">
                        <?= htmlspecialchars(__t('contact.title')) ?>
                    </h2>

                    <form id="contact-form"
                          x-data="contactForm()"
                          @submit.prevent="submitContact">

                        <!-- Name -->
                        <div class="form-group">
                            <label><?= htmlspecialchars(__t('contact.name')) ?></label>
                            <input type="text"
                                   name="name"
                                   x-model="form.name"
                                   autocomplete="name">
                        </div>

                        <!-- Email + Phone -->
                        <div class="grid-2">
                            <div class="form-group">
                                <label><?= htmlspecialchars(__t('contact.email')) ?></label>
                                <input type="email"
                                       name="email"
                                       x-model="form.email"
                                       autocomplete="email">
                            </div>
                            <div class="form-group">
                                <label><?= htmlspecialchars(__t('contact.phone')) ?></label>
                                <input type="tel"
                                       name="phone"
                                       x-model="form.phone"
                                       autocomplete="tel">
                            </div>
                        </div>

                        <!-- Message -->
                        <div class="form-group">
                            <label>
                                <?= htmlspecialchars(__t('contact.message')) ?>
                                <span style="color:var(--color-accent)">*</span>
                            </label>
                            <textarea name="message"
                                      x-model="form.message"
                                      rows="5"
                                      required></textarea>
                        </div>

                        <!-- CSRF -->
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <!-- Feedback -->
                        <div x-show="error"
                             class="form-error"
                             style="margin-bottom:1rem"
                             x-text="error"
                             role="alert"></div>

                        <div x-show="success"
                             class="form-success"
                             role="status">
                            <?= htmlspecialchars(__t('contact.success')) ?>
                        </div>

                        <!-- Submit -->
                        <button type="submit"
                                class="btn btn-accent btn-lg"
                                style="width:100%; justify-content:center; margin-top:0.5rem"
                                :disabled="submitting"
                                x-show="!success">
                            <span x-show="!submitting"><?= htmlspecialchars(__t('contact.submit')) ?></span>
                            <span x-show="submitting"><?= htmlspecialchars(__t('general.loading')) ?></span>
                        </button>

                    </form>
                </div><!-- /.admin-card -->
            </div>

            <!-- ================================================
                 RIGHT COLUMN: Other contact methods + payment info
                 ================================================ -->
            <div>

                <!-- Other ways to reach us -->
                <div style="margin-bottom:2.5rem">
                    <span class="eyebrow label" style="margin-bottom:1rem">
                        <?= $lang === 'es' ? 'Otras Formas de Contactarnos' : 'Other Ways to Reach Us' ?>
                    </span>

                    <div style="display:flex; flex-direction:column; gap:1rem; margin-top:1.25rem">

                        <?php if ($whatsappPhone !== ''): ?>
                        <a href="https://wa.me/<?= htmlspecialchars($whatsappPhone) ?>"
                           class="btn btn-primary"
                           target="_blank"
                           rel="noopener noreferrer"
                           style="justify-content:center; gap:0.625rem">
                            <!-- WhatsApp icon -->
                            <svg viewBox="0 0 32 32" fill="currentColor" width="20" height="20"
                                 xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M16 3C9.373 3 4 8.373 4 15c0 2.385.693 4.61 1.895 6.488L4 29l7.733-1.867A12.94 12.94 0 0 0 16 28c6.627 0 12-5.373 12-12S22.627 3 16 3zm0 22a10.94 10.94 0 0 1-5.568-1.52l-.398-.236-4.594 1.109 1.133-4.48-.26-.412A10.94 10.94 0 0 1 5.001 15C5 9.477 9.477 5 16 5s11 4.477 11 11-4.477 11-11 11zm6.07-8.243c-.332-.166-1.963-.969-2.267-1.079-.305-.11-.527-.166-.749.166-.222.332-.86 1.079-1.054 1.3-.194.222-.388.249-.72.083-.332-.166-1.402-.516-2.67-1.646-.986-.88-1.652-1.965-1.846-2.297-.194-.332-.021-.512.146-.678.15-.149.332-.388.499-.582.166-.194.221-.332.332-.554.111-.222.055-.416-.028-.582-.083-.166-.748-1.802-1.025-2.468-.27-.648-.545-.56-.748-.57l-.638-.012c-.222 0-.582.083-.887.416-.305.332-1.163 1.137-1.163 2.772s1.19 3.214 1.357 3.436c.166.222 2.344 3.578 5.68 5.019.794.342 1.412.547 1.894.7.796.254 1.521.218 2.094.132.639-.096 1.963-.803 2.24-1.578.276-.776.276-1.44.193-1.578-.082-.138-.304-.221-.637-.387z"/>
                            </svg>
                            <?= htmlspecialchars(__t('contact.whatsapp_cta')) ?>
                        </a>
                        <?php endif; ?>

                        <?php if ($fbUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($fbUrl) ?>"
                           class="btn btn-outline"
                           target="_blank"
                           rel="noopener noreferrer"
                           style="justify-content:center; gap:0.625rem">
                            <!-- Facebook icon -->
                            <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"
                                 xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M22 12C22 6.477 17.523 2 12 2S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.879V14.89h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.989C18.343 21.129 22 16.99 22 12z"/>
                            </svg>
                            <?= htmlspecialchars(__t('contact.facebook_cta')) ?>
                        </a>
                        <?php endif; ?>

                        <?php if ($igUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($igUrl) ?>"
                           class="btn btn-outline"
                           target="_blank"
                           rel="noopener noreferrer"
                           style="justify-content:center; gap:0.625rem">
                            <!-- Instagram icon -->
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round" width="18" height="18"
                                 xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
                                <circle cx="12" cy="12" r="4"/>
                                <circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>
                            </svg>
                            Instagram
                        </a>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- Pickup & Delivery Info -->
                <?php
                $bizAddr   = \App\Core\Config::get('BUSINESS_ADDRESS', '');
                $baseMiles = (int) \App\Core\Config::get('BUSINESS_DELIVERY_BASE_MILES', 5);
                $baseFee   = (int) \App\Core\Config::get('BUSINESS_DELIVERY_BASE_FEE', 10);
                $perMile   = (int) \App\Core\Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1);
                $mapsUrl   = $bizAddr !== '' ? 'https://maps.google.com/?q=' . urlencode($bizAddr) : '';
                ?>
                <?php if ($bizAddr !== '' || $baseFee > 0): ?>
                <div class="admin-card" style="margin-bottom:1.5rem">
                    <h3 style="font-size:1rem; font-weight:600; margin-bottom:1rem; color:var(--color-text-dark)">
                        <?= $lang === 'es' ? 'Recogida y Entrega' : 'Pickup & Delivery' ?>
                    </h3>

                    <?php if ($bizAddr !== ''): ?>
                    <div style="margin-bottom:1rem">
                        <p style="font-size:0.8rem; color:var(--color-muted); margin-bottom:0.25rem; text-transform:uppercase; letter-spacing:0.05em">
                            <?= htmlspecialchars(__t('footer.pickup_address')) ?>
                        </p>
                        <p style="font-weight:600; margin-bottom:0.25rem"><?= htmlspecialchars($bizAddr) ?></p>
                        <?php if ($mapsUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($mapsUrl) ?>" target="_blank" rel="noopener noreferrer"
                           style="font-size:0.85rem; color:var(--color-primary)">
                            <?= htmlspecialchars(__t('order.get_directions')) ?> &rarr;
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($baseFee > 0): ?>
                    <div>
                        <p style="font-size:0.8rem; color:var(--color-muted); margin-bottom:0.25rem; text-transform:uppercase; letter-spacing:0.05em">
                            <?= htmlspecialchars(__t('footer.delivery_info')) ?>
                        </p>
                        <p style="font-weight:600">
                            <?= $lang === 'es'
                                ? htmlspecialchars("Dentro de {$baseMiles} millas: \${$baseFee}")
                                : htmlspecialchars("Within {$baseMiles} miles: \${$baseFee}") ?>
                        </p>
                        <p style="font-size:0.85rem; color:var(--color-muted)">
                            <?= $lang === 'es'
                                ? htmlspecialchars("+\${$perMile} por milla adicional")
                                : htmlspecialchars("+\${$perMile} per additional mile") ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Payment / deposit info -->
                <?php if ($zellePhone !== '' || $cashappTag !== ''): ?>
                <div class="admin-card" style="background:var(--color-bg-light); border:1px solid var(--color-border)">
                    <span class="eyebrow label" style="margin-bottom:0.75rem">
                        <?= $lang === 'es' ? 'Información de Pago' : 'Payment Info' ?>
                    </span>
                    <p style="font-size:0.925rem; color:var(--color-text-dark); margin-bottom:1.25rem; margin-top:0.5rem">
                        <?= $lang === 'es'
                            ? '¿Listo para hacer tu pedido? Envía un depósito para confirmarlo.'
                            : 'Ready to place an order? Send a deposit to confirm.'
                        ?>
                    </p>

                    <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:0.875rem">
                        <?php if ($zellePhone !== ''): ?>
                        <li style="display:flex; align-items:center; gap:0.75rem">
                            <span style="font-family:'Montserrat',sans-serif; font-size:0.7rem; text-transform:uppercase;
                                         letter-spacing:0.1em; color:var(--color-muted); min-width:70px">Zelle</span>
                            <strong style="color:var(--color-text-dark)"><?= htmlspecialchars($zellePhone) ?></strong>
                        </li>
                        <?php endif; ?>

                        <?php if ($cashappTag !== ''): ?>
                        <li style="display:flex; align-items:center; gap:0.75rem">
                            <span style="font-family:'Montserrat',sans-serif; font-size:0.7rem; text-transform:uppercase;
                                         letter-spacing:0.1em; color:var(--color-muted); min-width:70px">CashApp</span>
                            <strong style="color:var(--color-text-dark)"><?= htmlspecialchars($cashappTag) ?></strong>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

            </div><!-- /right column -->

        </div><!-- /.grid-2 -->
    </div><!-- /.container -->
</section>

<script>
/**
 * Alpine.js component for the contact form.
 *
 * Sends the form as JSON to POST /contact and shows a localised success
 * banner or an inline error message.
 */
function contactForm() {
    return {
        submitting: false,
        success:    false,
        error:      '',
        form: {
            name:        '',
            email:       '',
            phone:       '',
            message:     '',
            _csrf_token: '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>'
        },

        async submitContact() {
            this.submitting = true;
            this.error      = '';

            try {
                const res = await fetch('/contact', {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.form._csrf_token
                    },
                    body: JSON.stringify(this.form)
                });

                const data = await res.json();

                if (data.success) {
                    this.success = true;
                } else {
                    this.error = data.error || '<?= htmlspecialchars(__t('general.error'), ENT_QUOTES) ?>';
                }
            } catch (_e) {
                this.error = '<?= htmlspecialchars(__t('general.error'), ENT_QUOTES) ?>';
            } finally {
                this.submitting = false;
            }
        }
    };
}
</script>
