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
            <input type="tel" name="phone" autocomplete="tel">
          </div>
        </div>

        <div class="form-group">
          <label><?= htmlspecialchars(__t('order.email')) ?> <span style="color:var(--color-accent)">*</span></label>
          <input type="email" name="email" autocomplete="email" required>
        </div>

        <div class="form-group">
          <label><?= htmlspecialchars(__t('checkout.card_message')) ?></label>
          <textarea name="card_message" rows="3"
                    placeholder="<?= htmlspecialchars(__t('checkout.card_message_ph')) ?>"></textarea>
        </div>

        <!-- Delivery address + live fee -->
        <div class="form-group"
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

        <!-- Totals -->
        <div style="border-top:1px solid rgba(0,0,0,0.08); padding-top:1rem; margin-bottom:1.5rem">
          <div style="display:flex; justify-content:space-between; margin-bottom:0.3rem">
            <span><?= htmlspecialchars(__t('cart.subtotal')) ?></span>
            <span>$<?= number_format((float) $subtotal, 2) ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; margin-bottom:0.3rem">
            <span><?= htmlspecialchars(__t('checkout.delivery_fee')) ?></span>
            <span x-text="fee === null ? '<?= htmlspecialchars($lang === 'es' ? 'según dirección' : 'set by address') ?>' : '$' + fee.toFixed(2)"></span>
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
        address: <?= json_encode($prefillAddress) ?>,
        lat: '',
        lng: '',
        fee: <?= $estimatedFee !== null ? number_format((float) $estimatedFee, 2, '.', '') : 'null' ?>,
        feeError: '',
        submitting: false,
        subtotal: <?= json_encode(round((float) $subtotal, 2)) ?>,
        taxRate: <?= json_encode((float) $taxRate) ?>,
        get total() {
            const f = this.fee === null ? 0 : this.fee;
            return Math.round((this.subtotal + f + this.subtotal * this.taxRate) * 100) / 100;
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
        onSubmit(e) {
            if (!this.lat || !this.lng || !this.address) {
                e.preventDefault();
                this.feeError = 'Please select your delivery address from the suggestions.';
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
