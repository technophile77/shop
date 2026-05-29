<?php
/**
 * views/public/order.php — Custom bouquet request form.
 *
 * Rendered inside views/layouts/public.php. Outputs only the page body content.
 * Uses Alpine.js (already loaded by the layout) for form state and async submit.
 *
 * Variables injected by BaseController::render() via OrderController::form():
 *   string $lang            Current locale ('en' or 'es').
 *   string $csrfToken       Session CSRF token.
 *   string $arrangementHint Pre-fill value for the occasion field (may be empty).
 *   string $pageTitle       Localised page title from site settings.
 *
 * @see \App\Controllers\OrderController::form()
 */

use App\Core\Config;
use App\Core\Settings;
?>

<!-- ============================================================
     PAGE HEADER — dark strip
     ============================================================ -->
<div class="section section--dark" style="padding: 3rem 0 2rem">
    <div class="container text-center">
        <span class="eyebrow label" style="color:var(--color-muted)">
            <?= htmlspecialchars(Settings::get('order_page_title_' . $lang, 'Request a Custom Bouquet')) ?>
        </span>
        <h1 style="color:var(--color-text-light)">
            <?= htmlspecialchars(Settings::get('order_button_text_' . $lang, 'Request a Custom Bouquet')) ?>
        </h1>
        <p style="color:rgba(255,255,255,0.7); max-width:600px; margin: 1rem auto 0">
            <?= htmlspecialchars(__t('order.subtitle')) ?>
        </p>
    </div>
</div>

<!-- ============================================================
     FORM SECTION — light background
     ============================================================ -->
<section class="section section--light">
    <div class="container">
        <div class="admin-card" style="max-width:700px; margin:0 auto">

            <form id="order-form"
                  x-data="orderForm()"
                  @submit.prevent="submitOrder">

                <!-- Name + Event Date -->
                <div class="grid-2">
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars(__t('order.name')) ?>
                            <span style="color:var(--color-accent)">*</span>
                        </label>
                        <input type="text"
                               name="name"
                               x-model="form.name"
                               autocomplete="name"
                               required>
                    </div>
                    <div class="form-group">
                        <label><?= htmlspecialchars(__t('order.event_date')) ?></label>
                        <input type="date"
                               name="event_date"
                               x-model="form.event_date"
                               :min="new Date().toISOString().split('T')[0]">
                    </div>
                </div>

                <!-- Email + Phone -->
                <div class="grid-2">
                    <div class="form-group">
                        <label><?= htmlspecialchars(__t('order.email')) ?></label>
                        <input type="email"
                               name="email"
                               x-model="form.email"
                               autocomplete="email">
                    </div>
                    <div class="form-group">
                        <label><?= htmlspecialchars(__t('order.phone')) ?></label>
                        <input type="tel"
                               name="phone"
                               x-model="form.phone"
                               autocomplete="tel">
                    </div>
                </div>

                <!-- Occasion -->
                <div class="form-group">
                    <label><?= htmlspecialchars(__t('order.occasion')) ?></label>
                    <input type="text"
                           name="occasion"
                           x-model="form.occasion"
                           placeholder="<?= htmlspecialchars(__t('order.occasion_placeholder')) ?>">
                </div>

                <!-- Style + Colors -->
                <div class="grid-2">
                    <div class="form-group">
                        <label><?= htmlspecialchars(__t('order.style')) ?></label>
                        <input type="text"
                               name="arrangement_style"
                               x-model="form.arrangement_style"
                               placeholder="<?= htmlspecialchars(__t('order.style_placeholder')) ?>">
                    </div>
                    <div class="form-group">
                        <label><?= htmlspecialchars(__t('order.colors')) ?></label>
                        <input type="text"
                               name="color_preferences"
                               x-model="form.color_preferences">
                    </div>
                </div>

                <!-- Budget -->
                <div class="form-group">
                    <label><?= htmlspecialchars(__t('order.budget')) ?></label>
                    <select name="budget_range" x-model="form.budget_range">
                        <option value="">&#8212; Select &#8212;</option>
                        <option value="Under $50">Under $50</option>
                        <option value="$50–$100">$50&#8211;$100</option>
                        <option value="$100–$200">$100&#8211;$200</option>
                        <option value="$200–$350">$200&#8211;$350</option>
                        <option value="$350+">$350+</option>
                        <option value="Custom">Custom / Tell me more in notes</option>
                    </select>
                </div>

                <!-- Notes -->
                <div class="form-group">
                    <label><?= htmlspecialchars(__t('order.notes')) ?></label>
                    <textarea name="notes"
                              x-model="form.notes"
                              rows="4"></textarea>
                </div>

                <!-- CSRF -->
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <!-- Feedback messages -->
                <div x-show="error"
                     class="form-error"
                     style="margin-bottom:1rem"
                     x-text="error"
                     role="alert"></div>

                <div x-show="success"
                     class="form-success"
                     role="status">
                    <?= htmlspecialchars(__t('order.success')) ?>
                </div>

                <!-- Submit -->
                <button type="submit"
                        class="btn btn-accent btn-lg"
                        style="width:100%; justify-content:center; margin-top:0.5rem"
                        :disabled="submitting"
                        x-show="!success">
                    <span x-show="!submitting"><?= htmlspecialchars(__t('order.submit')) ?></span>
                    <span x-show="submitting"><?= htmlspecialchars(__t('general.loading')) ?></span>
                </button>

            </form>
        </div><!-- /.admin-card -->
    </div><!-- /.container -->
</section>

<script>
/**
 * Alpine.js component for the custom bouquet order form.
 *
 * Sends the form as JSON to POST /order and shows either a localised success
 * banner or an inline error message without a full page reload.
 */
function orderForm() {
    return {
        submitting: false,
        success:    false,
        error:      '',
        form: {
            name:               '',
            email:              '',
            phone:              '',
            event_date:         '',
            occasion:           '<?= htmlspecialchars(addslashes($arrangementHint ?? ''), ENT_QUOTES) ?>',
            arrangement_style:  '',
            color_preferences:  '',
            budget_range:       '',
            notes:              '',
            _csrf_token:        '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>'
        },

        async submitOrder() {
            this.submitting = true;
            this.error      = '';

            try {
                const res = await fetch('/order', {
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
                    this.error = data.error || '<?= htmlspecialchars(__t('order.error'), ENT_QUOTES) ?>';
                }
            } catch (_e) {
                this.error = '<?= htmlspecialchars(__t('order.error'), ENT_QUOTES) ?>';
            } finally {
                this.submitting = false;
            }
        }
    };
}
</script>
