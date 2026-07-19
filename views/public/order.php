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
 *   string $arrangementHint Pre-fill value for the arrangement_style field (may be empty).
 *   string $occasionHint    Pre-fill value for the occasion field (may be empty).
 *   string $pageTitle       Localised page title from site settings.
 *   array  $addons          Active add-ons for the checkbox grid (may be empty).
 *   string $todayYmd        Server-rendered 'Y-m-d' for today, used as the date
 *                           input's min so the picker isn't computed from the
 *                           browser's UTC clock.
 *   array  $closedDates     Store-closure dates over the next year, 'Y-m-d' strings
 *                           (may be empty). Client-side courtesy check only — the
 *                           server is authoritative (see OrderController::submit()).
 *   string $closedLabel     Human-readable, localised closure ranges for the notice
 *                           banner, e.g. 'Jul 4 – Jul 8, 2026; Sep 1, 2026'.
 *
 * @see \App\Controllers\OrderController::form()
 */

use App\Core\Config;
use App\Core\Settings;

$addonsJson = json_encode(
    array_map(
        fn ($a) => [
            'id'      => (int) $a['id'],
            'name_en' => $a['name_en'],
            'name_es' => $a['name_es'] ?? null,
            'image'   => $a['image_path'] ?? null,
        ],
        $addons ?? []
    ),
    JSON_HEX_TAG | JSON_HEX_QUOT
);
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
                  x-data="orderForm(<?= htmlspecialchars($addonsJson, ENT_QUOTES, 'UTF-8') ?>)"
                  @submit.prevent="submitOrder">

                <!-- Pickup or Delivery -->
                <div class="form-group" style="margin-bottom:1.5rem">
                    <label style="display:block;margin-bottom:0.75rem;font-weight:600">
                        <?= htmlspecialchars(__t('order.delivery_type')) ?>
                    </label>
                    <div style="display:flex;gap:2rem;flex-wrap:wrap">
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-weight:normal">
                            <input type="radio" x-model="form.delivery_type" value="pickup"
                                   style="width:1.1rem;height:1.1rem;accent-color:var(--color-primary)">
                            <?= htmlspecialchars(__t('order.pickup')) ?>
                        </label>
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-weight:normal">
                            <input type="radio" x-model="form.delivery_type" value="delivery"
                                   style="width:1.1rem;height:1.1rem;accent-color:var(--color-primary)">
                            <?= htmlspecialchars(__t('order.delivery')) ?>
                        </label>
                    </div>
                </div>

                <!-- Pickup info card -->
                <div x-show="form.delivery_type === 'pickup'"
                     style="background:var(--color-bg-light);border:1px solid var(--color-border);border-radius:8px;padding:1rem;margin-bottom:1.5rem">
                    <p style="font-weight:600;margin-bottom:0.25rem;color:var(--color-text-dark)">
                        <?= $lang === 'es' ? 'Recogida en Estudio' : 'Studio Pickup' ?>
                    </p>
                    <p style="color:var(--color-text-dark);margin-bottom:0.5rem">
                        <?= htmlspecialchars(\App\Core\Config::get('BUSINESS_ADDRESS', '6134 S Troost Ave, Tulsa, OK 74136')) ?>
                    </p>
                    <p style="font-size:0.875rem;color:var(--color-muted);margin-bottom:0.75rem">
                        <?= $lang === 'es'
                            ? 'Somos un estudio privado — no hay ventas sin cita. Por favor, envía un mensaje de texto cuando llegues y llevamos tu pedido de inmediato.'
                            : 'We are a private studio — no walk-ins. Please text us when you arrive and we\'ll bring your order right out.' ?>
                    </p>
                    <a href="sms:<?= htmlspecialchars(preg_replace('/\D/', '', (string) \App\Core\Config::get('WHATSAPP_PHONE', ''))) ?>"
                       style="font-size:0.85rem;color:var(--color-primary)">
                        <?= $lang === 'es' ? 'Enviar mensaje de texto al llegar &rarr;' : 'Text us when you arrive &rarr;' ?>
                    </a>
                </div>

                <!-- Delivery address + fee calculator -->
                <div x-show="form.delivery_type === 'delivery'"
                     style="margin-bottom:1.5rem"
                     x-init="$nextTick(() => initAutocomplete($refs.deliveryInput, p => calculateFeeFromPlace(p)))">
                    <div class="form-group">
                        <label>
                            <?= htmlspecialchars(__t('order.delivery_address')) ?>
                            <span style="color:var(--color-accent)">*</span>
                        </label>
                        <input type="text"
                               x-ref="deliveryInput"
                               id="delivery-address-input"
                               placeholder="Start typing your address..."
                               autocomplete="off"
                               style="width:100%">
                    </div>

                    <!-- Fee result -->
                    <div x-show="feeResult !== null"
                         style="background:var(--color-bg-light);border:1px solid var(--color-border);border-radius:8px;padding:1rem">
                        <div style="display:flex;justify-content:space-between;margin-bottom:0.25rem">
                            <span style="color:var(--color-muted);font-size:0.9rem">Distance</span>
                            <span x-text="feeResult ? feeResult.distance + ' miles' : ''"></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-weight:600">
                            <span><?= htmlspecialchars(__t('order.delivery_fee')) ?></span>
                            <span style="color:var(--color-primary)" x-text="feeResult ? '$' + feeResult.fee.toFixed(2) : ''"></span>
                        </div>
                    </div>

                    <!-- Fee error -->
                    <p x-show="feeError"
                       style="color:#d32f2f;font-size:0.9rem;margin-top:0.5rem"
                       x-text="feeError"></p>

                    <!-- Tax notice -->
                    <?php $deliveryTaxRate = (float) \App\Core\Config::get('BUSINESS_SALES_TAX_RATE', 0); ?>
                    <?php if ($deliveryTaxRate > 0): ?>
                    <p style="font-size:0.8rem; color:var(--color-muted); margin-top:0.5rem">
                        <?= number_format($deliveryTaxRate * 100, 3) ?>% <?= htmlspecialchars((string) \App\Core\Config::get('BUSINESS_STATE', 'OK')) ?> sales tax will apply to your order total.
                    </p>
                    <?php endif; ?>
                </div>

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
                               min="<?= htmlspecialchars($todayYmd) ?>"
                               @change="checkClosedDate">
                        <p x-show="closedWarning"
                           x-text="closedWarning"
                           style="background:#fff4e5; border:1px solid #ffcc80; color:#8a5300; border-radius:8px; padding:0.75rem 1rem; font-size:0.85rem; margin-top:0.5rem"
                           role="alert"></p>
                    </div>
                </div>

                <?php if (!empty($closedDates)): ?>
                <div role="status"
                     style="background:#fff4e5; border:1px solid #ffcc80; color:#8a5300; border-radius:8px; padding:0.75rem 1rem; font-size:0.85rem; margin:-0.5rem 0 1.5rem">
                    <?= htmlspecialchars(__t('closure.notice')) ?>
                    <?= htmlspecialchars($closedLabel) ?>
                </div>
                <?php endif; ?>

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
                               placeholder="<?= htmlspecialchars(__t('order.style_placeholder')) ?>"
                               <?php if (!empty($arrangementHint)): ?>
                               readonly
                               style="background:var(--color-bg-light); color:var(--color-muted); cursor:default"
                               <?php endif; ?>>
                        <?php if (!empty($arrangementHint)): ?>
                        <p style="font-size:0.8rem; color:var(--color-muted); margin-top:0.25rem">
                            Pre-filled from your selection — contact us if you'd like a different arrangement.
                        </p>
                        <?php endif; ?>
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

                <!-- Add-Ons -->
                <template x-if="availableAddons.length > 0">
                    <div class="form-group">
                        <label style="display:block; margin-bottom:0.75rem; font-weight:600">
                            <?= $lang === 'es' ? 'Extras' : 'Add-Ons' ?>
                        </label>
                        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:0.75rem">
                            <template x-for="addon in availableAddons" :key="addon.id">
                                <label style="display:flex; flex-direction:column; align-items:center; gap:0.5rem;
                                              cursor:pointer; padding:0.75rem; border-radius:8px;
                                              border:2px solid; transition:border-color 0.15s, background 0.15s;
                                              background:var(--color-bg-light); text-align:center"
                                       :style="form.addons.some(a => a.id === addon.id)
                                           ? 'border-color:var(--color-primary); background:#fdf3fc'
                                           : 'border-color:#e8e8e8'">
                                    <img x-show="addon.image"
                                         :src="'/public/uploads/products/' + addon.image"
                                         style="width:80px; height:80px; object-fit:cover; border-radius:6px"
                                         :alt="addon.name_en">
                                    <div x-show="!addon.image"
                                         style="width:80px; height:80px; background:#ececec; border-radius:6px;
                                                display:flex; align-items:center; justify-content:center; font-size:1.5rem">
                                        🎁
                                    </div>
                                    <span style="font-size:0.82rem; color:var(--color-text-dark); line-height:1.3"
                                          x-text="addon.name_en"></span>
                                    <input type="checkbox"
                                           :checked="form.addons.some(a => a.id === addon.id)"
                                           @change="toggleAddon(addon)"
                                           style="width:1rem; height:1rem; accent-color:var(--color-primary)">
                                </label>
                            </template>
                        </div>
                    </div>
                </template>

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

<?php if (\App\Core\Config::get('GOOGLE_MAPS_API_KEY')): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars(\App\Core\Config::get('GOOGLE_MAPS_API_KEY')) ?>&libraries=places"></script>
<?php endif; ?>

<script>
/**
 * Alpine.js component for the custom bouquet order form.
 *
 * Delivery fee is calculated client-side using Google Places Autocomplete
 * for address selection and the Haversine formula for distance. No
 * server-side geocoding call is made.
 *
 * The closed-date check ({@see checkClosedDate}) is a client-side courtesy
 * only, using the closure list the server rendered into the page — the
 * server remains authoritative and re-checks on submit (see
 * OrderController::submit()), so a stale or bypassed client check can never
 * let a closed date through.
 *
 * @param {Array} availableAddons Active add-ons from the server, shaped as
 *        [{id, name_en, name_es, image}, …]. Empty when none are configured.
 */
function orderForm(availableAddons = []) {
    // Translated sprintf-style templates for the client-side closed-date
    // warning. json_encode (not htmlspecialchars) is deliberate: the
    // translated copy contains apostrophes ("we're closed…"), and
    // htmlspecialchars(ENT_QUOTES) would emit HTML entities into this
    // <script> block, which the browser does NOT decode inside raw-text
    // elements — json_encode() emits a valid, safe JS string literal instead.
    const closedDateTemplate      = <?= json_encode(__t('closure.rejected')) ?>;
    const closedDateChooseAnother = <?= json_encode(__t('closure.choose_another')) ?>;
    const closedDateMonths        = <?= json_encode(explode(',', __t('closure.months'))) ?>;

    /**
     * Format a 'Y-m-d' date the same way Closures::formatRange() does server-side,
     * so the client-side courtesy warning and the authoritative server rejection
     * read identically ('Jul 4, 2026' — never a raw '2026-07-04').
     *
     * Parses the string by parts rather than via `new Date(str)`, which would
     * interpret a bare 'Y-m-d' as UTC and shift the day in America/Chicago.
     *
     * @param {string} ymd Date as 'Y-m-d'.
     * @returns {string} e.g. 'Jul 4, 2026'; the input unchanged when malformed.
     */
    function formatClosedDate(ymd) {
        const parts = String(ymd).split('-');
        if (parts.length !== 3) return ymd;
        const month = closedDateMonths[Number(parts[1]) - 1];
        return month ? month + ' ' + Number(parts[2]) + ', ' + parts[0] : ymd;
    }

    return {
        availableAddons: availableAddons,
        submitting: false,
        success:    false,
        error:      '',
        feeResult:  null,
        feeError:   '',
        closedDates:   <?= json_encode($closedDates ?? []) ?>,
        closedWarning: '',
        form: {
            name:              '',
            email:             '',
            phone:             '',
            event_date:        '',
            occasion:          '<?= htmlspecialchars(addslashes($occasionHint ?? ''), ENT_QUOTES) ?>',
            arrangement_style: '<?= htmlspecialchars(addslashes($arrangementHint ?? ''), ENT_QUOTES) ?>',
            color_preferences: '',
            budget_range:      '',
            notes:             '',
            delivery_type:     'pickup',
            delivery_address:  '',
            delivery_fee:      null,
            addons:            [],
            _csrf_token:       '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>'
        },

        calculateFeeFromPlace(place) {
            if (!place || !place.geometry) {
                this.feeError  = 'Please select an address from the dropdown suggestions.';
                this.feeResult = null;
                this.form.delivery_fee    = null;
                this.form.delivery_address = '';
                return;
            }

            const lat = place.geometry.location.lat();
            const lng = place.geometry.location.lng();

            // Haversine distance in miles
            const R      = 3958.8;
            const bizLat = <?= (float) \App\Core\Config::get('BUSINESS_LAT', '36.0814') ?>;
            const bizLng = <?= (float) \App\Core\Config::get('BUSINESS_LNG', '-95.9987') ?>;
            const dLat   = (lat - bizLat) * Math.PI / 180;
            const dLng   = (lng - bizLng) * Math.PI / 180;
            const a      = Math.sin(dLat / 2) ** 2
                         + Math.cos(bizLat * Math.PI / 180) * Math.cos(lat * Math.PI / 180)
                         * Math.sin(dLng / 2) ** 2;
            const distance = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

            if (distance > 30) {
                this.feeError  = '<?= htmlspecialchars(__t('order.delivery_outside_range'), ENT_QUOTES) ?>';
                this.feeResult = null;
                this.form.delivery_fee    = null;
                this.form.delivery_address = '';
                return;
            }

            const baseMiles = <?= (float) \App\Core\Config::get('BUSINESS_DELIVERY_BASE_MILES', 5) ?>;
            const baseFee   = <?= (float) \App\Core\Config::get('BUSINESS_DELIVERY_BASE_FEE', 10) ?>;
            const perMile   = <?= (float) \App\Core\Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1) ?>;
            const fee       = baseFee + Math.max(0, distance - baseMiles) * perMile;

            this.form.delivery_address = place.formatted_address;
            this.form.delivery_fee     = Math.round(fee * 100) / 100;
            this.feeResult             = { distance: Math.round(distance * 10) / 10, fee: this.form.delivery_fee };
            this.feeError              = '';
        },

        /**
         * Client-side courtesy check for the picked event date.
         *
         * Warns immediately when the chosen date is one of the store's
         * known upcoming closures, so the customer doesn't wait for a
         * round-trip to find out. This is UX sugar only — the server
         * independently re-validates on submit (see
         * OrderController::submit()) and is the sole source of truth, so a
         * stale closedDates list (e.g. a closure added moments ago) never
         * lets a bad date slip through, it just delays the warning to the
         * submit response.
         */
        checkClosedDate() {
            this.closedWarning = this.closedDates.includes(this.form.event_date)
                ? closedDateTemplate.replace('%s', formatClosedDate(this.form.event_date)) + ' ' + closedDateChooseAnother
                : '';
        },

        /**
         * Toggle an add-on in form.addons.
         *
         * Stores a snapshot {id, name_en, name_es} so the order record and
         * notification email show names independent of future add-on changes.
         *
         * @param {{id:number, name_en:string, name_es:string|null, image:string|null}} addon
         */
        toggleAddon(addon) {
            const idx = this.form.addons.findIndex(a => a.id === addon.id);
            if (idx >= 0) {
                this.form.addons.splice(idx, 1);
            } else {
                this.form.addons.push({ id: addon.id, name_en: addon.name_en, name_es: addon.name_es });
            }
        },

        async submitOrder() {
            this.submitting = true;
            this.error      = '';

            if (this.form.delivery_type === 'delivery' && !this.form.delivery_address) {
                this.error      = 'Please select your delivery address from the suggestions.';
                this.submitting = false;
                return;
            }

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
                    if (window.pfTrackLead) window.pfTrackLead();
                } else {
                    this.error = data.error || '<?= htmlspecialchars(__t('order.error'), ENT_QUOTES) ?>';
                }
            } catch (_e) {
                this.error = '<?= htmlspecialchars(__t('order.error'), ENT_QUOTES) ?>';
            } finally {
                this.submitting = false;
            }
        },

        init() {
            // Clear delivery state when switching back to pickup
            this.$watch('form.delivery_type', val => {
                if (val === 'pickup') {
                    this.form.delivery_address = '';
                    this.form.delivery_fee     = null;
                    this.feeResult             = null;
                    this.feeError              = '';
                    const input = this.$refs.deliveryInput;
                    if (input) input.value = '';
                }
            });
        }
    };
}

/**
 * Initialises a Google Places Autocomplete widget on the given input element.
 * Restricted to US street addresses. Calls onPlaceSelected with the place object
 * when the user picks a suggestion.
 *
 * @param {HTMLInputElement} inputEl
 * @param {Function}         onPlaceSelected
 */
function initAutocomplete(inputEl, onPlaceSelected) {
    if (typeof google === 'undefined' || !google.maps || !inputEl) return;
    const ac = new google.maps.places.Autocomplete(inputEl, {
        componentRestrictions: { country: 'us' },
        fields:                ['formatted_address', 'geometry'],
        types:                 ['address'],
    });
    ac.addListener('place_changed', () => onPlaceSelected(ac.getPlace()));
}
</script>
