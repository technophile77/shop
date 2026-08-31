<?php

declare(strict_types=1);

/**
 * views/public/checkout.php — Shop-cart checkout review + pay page.
 *
 * Rendered inside views/layouts/public.php via CheckoutController::view().
 * Shows the cart summary, collects sender contact, a card message, and the
 * delivery address (Google Places autocomplete capturing latitude/longitude),
 * and previews the delivery fee + sales tax + total. Submitting POSTs to
 * /checkout, which recomputes the fee server-side and redirects to Stripe.
 *
 * Variables injected by BaseController::render():
 *   @var array<int, array> $items        Cart line items.
 *   @var float             $subtotal      Merchandise subtotal.
 *   @var float             $taxRate       Sales-tax rate (e.g. 0.08517).
 *   @var array|null        $destination   Send-flowers destination, or null.
 *   @var float|null        $estimatedFee  Pre-estimated fee for a known venue, or null.
 *   @var string            $lang          Active locale.
 *   @var string            $csrfToken     CSRF token.
 *   @var string            $earliestDate  Earliest selectable fulfillment date (date input min).
 *   @var string            $samedayCutoff Same-day order cutoff time (e.g. '13:00').
 *   @var string            $pickupAddress Studio pickup address.
 *   @var list<string>      $outOfStockColors Localized "{color} {type}" labels for out-of-stock chosen colors.
 *   @var bool              $hasStockWarning  Whether any chosen color is out of stock (warn-only).
 *   @var list<string>      $closedDates   Store-closed dates ('Y-m-d') in the next 365 days.
 *   @var string            $closedLabel   Same closures formatted as a human-readable list.
 *
 * @see \App\Controllers\CheckoutController
 */

use App\Core\Config;

$bizLat    = (float) Config::get('BUSINESS_LAT', 36.0814);
$bizLng    = (float) Config::get('BUSINESS_LNG', -95.9987);
$baseMiles = (float) Config::get('BUSINESS_DELIVERY_BASE_MILES', 5);
$baseFee   = (float) Config::get('BUSINESS_DELIVERY_BASE_FEE', 10);
$perMile   = (float) Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1);
$prefillAddress = $destination['venue_address'] ?? '';
?>

<div class="section section--dark" style="padding: 3rem 0 2rem">
  <div class="container text-center">
    <h1 style="color:var(--color-text-light)"><?= htmlspecialchars(__t('checkout.heading')) ?></h1>
  </div>
</div>

<section class="section section--light">
  <div class="container">
    <div class="admin-card" style="max-width:720px; margin:0 auto"
         x-data="checkoutForm()">

      <!-- Order summary -->
      <h2 style="font-size:1.1rem; margin:0 0 1rem"><?= htmlspecialchars(__t('checkout.order_summary')) ?></h2>
      <div style="border:1px solid rgba(0,0,0,0.08); border-radius:8px; padding:1rem; margin-bottom:1.5rem">
        <?php foreach ($items as $item): ?>
        <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:0.35rem">
          <span><?= (int) ($item['qty'] ?? 1) ?>× <?= htmlspecialchars($item['name_' . $lang] ?? $item['name_en'] ?? '') ?></span>
          <span>$<?= number_format((float) \App\Support\CartPricing::lineTotal($item), 2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($destination['venue_name'])): ?>
      <p style="font-size:0.9rem; color:var(--color-muted); margin-bottom:1.5rem">
        📍 <?= htmlspecialchars($lang === 'es' ? 'Enviando a:' : 'Sending to:') ?>
        <strong><?= htmlspecialchars($destination['venue_name']) ?></strong>
      </p>
      <?php endif; ?>

      <form method="post" action="/checkout" @submit="onSubmit">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="grid-2">
          <div class="form-group">
            <label><?= htmlspecialchars(__t('order.name')) ?> <span style="color:var(--color-accent)">*</span></label>
            <input type="text" name="name" x-model="name" autocomplete="name" required>
          </div>
          <div class="form-group">
            <label><?= htmlspecialchars(__t('order.phone')) ?></label>
            <input type="tel" name="phone" x-model="phone" autocomplete="tel">
          </div>
        </div>

        <div class="form-group">
          <label><?= htmlspecialchars(__t('order.email')) ?> <span style="color:var(--color-accent)">*</span></label>
          <input type="email" name="email" x-model="email" autocomplete="email" required>
        </div>

        <div class="form-group">
          <label><?= htmlspecialchars(__t('checkout.card_message')) ?></label>
          <textarea name="card_message" rows="3"
                    placeholder="<?= htmlspecialchars(__t('checkout.card_message_ph')) ?>"></textarea>
        </div>

        <!-- Delivery / Pickup toggle -->
        <div class="form-group">
          <label><?= htmlspecialchars($lang === 'es' ? '¿Cómo desea recibir su pedido?' : 'How would you like to receive your order?') ?></label>
          <div style="display:flex; gap:0.75rem; flex-wrap:wrap">
            <label style="flex:1; min-width:140px; display:flex; align-items:center; gap:0.5rem; border:1px solid rgba(0,0,0,0.15); border-radius:8px; padding:0.75rem 1rem; cursor:pointer"
                   :style="fulfillType === 'delivery' ? 'border-color:var(--color-accent); background:rgba(0,0,0,0.02)' : ''">
              <input type="radio" name="delivery_type" value="delivery" x-model="fulfillType">
              <span><?= htmlspecialchars(__t('order.delivery')) ?></span>
            </label>
            <label style="flex:1; min-width:140px; display:flex; align-items:center; gap:0.5rem; border:1px solid rgba(0,0,0,0.15); border-radius:8px; padding:0.75rem 1rem; cursor:pointer"
                   :style="fulfillType === 'pickup' ? 'border-color:var(--color-accent); background:rgba(0,0,0,0.02)' : ''">
              <input type="radio" name="delivery_type" value="pickup" x-model="fulfillType">
              <span><?= htmlspecialchars(__t('order.pickup')) ?></span>
            </label>
          </div>
        </div>

        <!-- Delivery address + live fee (delivery only) -->
        <div class="form-group" x-show="fulfillType === 'delivery'"
             x-init="$nextTick(() => initAutocomplete($refs.addr, p => onPlace(p)))">
          <label><?= htmlspecialchars(__t('order.delivery_address')) ?> <span style="color:var(--color-accent)">*</span></label>
          <input type="text" x-ref="addr" id="checkout-address-input"
                 value="<?= htmlspecialchars($prefillAddress) ?>"
                 placeholder="Start typing the delivery address..." autocomplete="off">
          <input type="hidden" name="delivery_address" x-model="address">
          <input type="hidden" name="latitude"  x-model="lat">
          <input type="hidden" name="longitude" x-model="lng">
          <p x-show="feeError" style="color:#d32f2f; font-size:0.9rem; margin-top:0.5rem" x-text="feeError"></p>
        </div>

        <!-- Pickup studio info (pickup only) -->
        <div class="form-group" x-show="fulfillType === 'pickup'" x-cloak>
          <label><?= htmlspecialchars($lang === 'es' ? 'Lugar de Recogida' : 'Pickup Location') ?></label>
          <div style="border:1px solid rgba(0,0,0,0.08); border-radius:8px; padding:1rem; font-size:0.9rem; line-height:1.6">
            <strong><?= htmlspecialchars((string) Config::get('BUSINESS_NAME', "Perla's Flowers")) ?></strong><br>
            <?php if ($pickupAddress !== ''): ?>
            <?= htmlspecialchars($pickupAddress) ?><br>
            <?php endif; ?>
            <span style="color:var(--color-muted)">
              <?= htmlspecialchars($lang === 'es'
                  ? 'Le enviaremos un mensaje de texto cuando su pedido esté listo. No se cobra tarifa de entrega.'
                  : "We'll text you when your order is ready. No delivery fee.") ?>
            </span>
          </div>
        </div>

        <!-- Requested date + exact time -->
        <div class="grid-2">
          <div class="form-group">
            <label x-text="fulfillType === 'pickup'
                ? '<?= htmlspecialchars($lang === 'es' ? 'Fecha de recogida' : 'Pickup date', ENT_QUOTES) ?>'
                : '<?= htmlspecialchars($lang === 'es' ? 'Fecha de entrega' : 'Delivery date', ENT_QUOTES) ?>'"></label>
            <input type="date" name="fulfill_date" x-model="fulfillDate"
                   min="<?= htmlspecialchars($earliestDate) ?>" required>
          </div>
          <div class="form-group">
            <label x-text="fulfillType === 'pickup'
                ? '<?= htmlspecialchars($lang === 'es' ? 'Hora de recogida' : 'Pickup time', ENT_QUOTES) ?>'
                : '<?= htmlspecialchars($lang === 'es' ? 'Hora de entrega' : 'Delivery time', ENT_QUOTES) ?>'"></label>
            <input type="time" name="fulfill_time" x-model="fulfillTime" required>
          </div>
        </div>
        <p style="font-size:0.8rem; color:var(--color-muted); margin:-0.5rem 0 1.25rem">
          <?= htmlspecialchars($lang === 'es'
              ? 'Pedidos para el mismo día deben hacerse antes de las ' . $samedayCutoff . '.'
              : 'Same-day orders must be placed before ' . $samedayCutoff . '.') ?>
        </p>
        <?php if ($closedDates !== []): ?>
        <div role="status" style="background:#fff4e5; border:1px solid #ffcc80; color:#8a5300; border-radius:8px; padding:0.75rem 1rem; font-size:0.85rem; margin:-0.25rem 0 1.25rem">
          <?= htmlspecialchars(__t('closure.notice')) ?> <?= htmlspecialchars($closedLabel) ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($hasStockWarning)): ?>
        <div role="status" style="background:#fff4e5; border:1px solid #ffcc80; color:#8a5300; border-radius:8px; padding:0.75rem 1rem; font-size:0.85rem; margin:-0.25rem 0 1.25rem">
          <?php
          $names = implode(', ', array_map('htmlspecialchars', $outOfStockColors));
          echo $lang === 'es'
              ? 'Algunos colores de flores en su pedido están agotados (' . $names . '), por lo que la entrega el mismo día después de las ' . htmlspecialchars($samedayCutoff) . ' no está disponible para este pedido. Por favor elija una fecha posterior.'
              : 'Some flower colors in your order are currently out of stock (' . $names . '), so same-day delivery after ' . htmlspecialchars($samedayCutoff) . ' isn\'t available for this order. Please choose a later date.';
          ?>
        </div>
        <?php endif; ?>

        <!-- Totals -->
        <div style="border-top:1px solid rgba(0,0,0,0.08); padding-top:1rem; margin-bottom:1.5rem">
          <div style="display:flex; justify-content:space-between; margin-bottom:0.3rem">
            <span><?= htmlspecialchars(__t('cart.subtotal')) ?></span>
            <span>$<?= number_format((float) $subtotal, 2) ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; margin-bottom:0.3rem">
            <span><?= htmlspecialchars(__t('checkout.delivery_fee')) ?></span>
            <span x-text="fulfillType === 'pickup'
                ? '<?= htmlspecialchars($lang === 'es' ? 'Gratis' : 'Free', ENT_QUOTES) ?>'
                : (fee === null ? '<?= htmlspecialchars($lang === 'es' ? 'según dirección' : 'set by address', ENT_QUOTES) ?>' : '$' + fee.toFixed(2))"></span>
          </div>
          <div style="display:flex; justify-content:space-between; margin-bottom:0.3rem">
            <span><?= htmlspecialchars(__t('checkout.sales_tax')) ?></span>
            <span>$<?= number_format((float) $subtotal * (float) $taxRate, 2) ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; font-weight:700; font-size:1.1rem; margin-top:0.5rem">
            <span><?= htmlspecialchars(__t('checkout.total')) ?></span>
            <span x-text="'$' + total.toFixed(2)"></span>
          </div>
        </div>

        <div style="display:flex; flex-wrap:wrap; gap:1rem 2rem; margin-bottom:1rem">
            <label class="form-check" x-show="email !== ''">
                <input type="checkbox" name="opted_in_email" value="1">
                <?= htmlspecialchars(__t('signup.email_consent')) ?>
            </label>
            <label class="form-check" x-show="phone !== ''">
                <input type="checkbox" name="opted_in_sms" value="1">
                <?= htmlspecialchars(__t('signup.sms_consent')) ?>
            </label>
        </div>

        <button type="submit" class="btn btn-accent btn-lg" style="width:100%; justify-content:center"
                :disabled="submitting">
          <span x-show="!submitting"><?= htmlspecialchars(__t('checkout.pay_button')) ?></span>
          <span x-show="submitting"><?= htmlspecialchars(__t('general.loading')) ?></span>
        </button>
      </form>
    </div>
  </div>
</section>

<?php if (Config::get('GOOGLE_MAPS_API_KEY')): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars((string) Config::get('GOOGLE_MAPS_API_KEY')) ?>&libraries=places"></script>
<?php endif; ?>

<script>
/**
 * Alpine.js component for the checkout page.
 *
 * Captures the delivery address via Google Places, computes the delivery fee
 * (Haversine) and order total client-side for preview, and blocks submission
 * until a valid in-range address is selected. The server recomputes the fee
 * authoritatively on POST.
 */
function checkoutForm() {
    return {
        name: '',
        phone: '',
        email: '',
        fulfillType: 'delivery',
        fulfillDate: '',
        fulfillTime: '',
        address: <?= json_encode($prefillAddress) ?>,
        lat: '',
        lng: '',
        fee: <?= $estimatedFee !== null ? number_format((float) $estimatedFee, 2, '.', '') : 'null' ?>,
        feeError: '',
        // Client-side UX only: blocks obviously-closed dates before submit so
        // the customer doesn't have to round-trip to the server to find out.
        // The server (CheckoutController::submit()) remains authoritative.
        closedDates: <?= json_encode($closedDates) ?>,
        closedDateTemplate: <?= json_encode(__t('closure.rejected')) ?>,
        closedDateChooseAnother: <?= json_encode(__t('closure.choose_another')) ?>,
        closedDateMonths: <?= json_encode(explode(',', __t('closure.months'))) ?>,
        submitting: false,
        subtotal: <?= json_encode(round((float) $subtotal, 2)) ?>,
        taxRate: <?= json_encode((float) $taxRate) ?>,
        // Pickup carries no delivery fee; delivery uses the address-derived fee.
        get effectiveFee() {
            if (this.fulfillType === 'pickup') return 0;
            return this.fee === null ? 0 : this.fee;
        },
        get total() {
            return Math.round((this.subtotal + this.effectiveFee + this.subtotal * this.taxRate) * 100) / 100;
        },
        onPlace(place) {
            if (!place || !place.geometry) {
                this.feeError = 'Please select an address from the dropdown suggestions.';
                this.fee = null; this.lat = ''; this.lng = ''; this.address = '';
                return;
            }
            const lat = place.geometry.location.lat();
            const lng = place.geometry.location.lng();
            const R = 3958.8;
            const bizLat = <?= $bizLat ?>, bizLng = <?= $bizLng ?>;
            const dLat = (lat - bizLat) * Math.PI / 180;
            const dLng = (lng - bizLng) * Math.PI / 180;
            const a = Math.sin(dLat/2)**2 + Math.cos(bizLat*Math.PI/180)*Math.cos(lat*Math.PI/180)*Math.sin(dLng/2)**2;
            const distance = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            if (distance > 30) {
                this.feeError = '<?= htmlspecialchars(__t('order.delivery_outside_range'), ENT_QUOTES) ?>';
                this.fee = null; this.lat = ''; this.lng = ''; this.address = '';
                return;
            }
            const baseMiles = <?= $baseMiles ?>, baseFee = <?= $baseFee ?>, perMile = <?= $perMile ?>;
            this.fee = Math.round((baseFee + Math.max(0, distance - baseMiles) * perMile) * 100) / 100;
            this.lat = String(lat);
            this.lng = String(lng);
            this.address = place.formatted_address;
            this.feeError = '';
        },
        /**
         * Format a 'Y-m-d' date the way Closures::formatRange() does server-side,
         * so the client-side warning and the authoritative server rejection read
         * identically ('Jul 4, 2026' — never a raw '2026-07-04').
         *
         * Parses by parts rather than via `new Date(str)`, which would treat a
         * bare 'Y-m-d' as UTC and shift the day in America/Chicago.
         *
         * @param {string} ymd Date as 'Y-m-d'.
         * @returns {string} e.g. 'Jul 4, 2026'; the input unchanged when malformed.
         */
        formatClosedDate(ymd) {
            const parts = String(ymd).split('-');
            if (parts.length !== 3) return ymd;
            const month = this.closedDateMonths[Number(parts[1]) - 1];
            return month ? month + ' ' + Number(parts[2]) + ', ' + parts[0] : ymd;
        },

        onSubmit(e) {
            // Delivery requires a geocoded address; pickup does not.
            if (this.fulfillType === 'delivery' && (!this.lat || !this.lng || !this.address)) {
                e.preventDefault();
                this.feeError = 'Please select your delivery address from the suggestions.';
                return;
            }
            if (!this.fulfillDate || !this.fulfillTime) {
                e.preventDefault();
                this.feeError = '<?= htmlspecialchars($lang === 'es' ? 'Por favor elija una fecha y hora.' : 'Please choose a date and time.', ENT_QUOTES) ?>';
                return;
            }
            if (this.closedDates.includes(this.fulfillDate)) {
                e.preventDefault();
                this.feeError = this.closedDateTemplate.replace('%s', this.formatClosedDate(this.fulfillDate))
                    + ' ' + this.closedDateChooseAnother;
                return;
            }
            this.submitting = true;
        }
    };
}

/**
 * Initialise a Google Places Autocomplete (US street addresses) on an input.
 *
 * @param {HTMLInputElement} inputEl
 * @param {Function} onPlaceSelected
 */
function initAutocomplete(inputEl, onPlaceSelected) {
    if (typeof google === 'undefined' || !google.maps || !inputEl) return;
    const ac = new google.maps.places.Autocomplete(inputEl, {
        componentRestrictions: { country: 'us' },
        fields: ['formatted_address', 'geometry'],
        types: ['address'],
    });
    ac.addListener('place_changed', () => onPlaceSelected(ac.getPlace()));
}
</script>
