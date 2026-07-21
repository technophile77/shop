<?php
/**
 * views/admin/quotes/builder.php — Alpine.js-powered quote builder form.
 *
 * Variables injected by QuotesController::newForm():
 *   array  $customers  All customer rows, each with id, name, email, phone, lifetime_spend.
 *   string $csrfToken  CSRF token for the POST form.
 *   string $pageTitle  Page title string.
 *
 * The form submits to $formAction (POST). Items are sent as parallel arrays:
 * item_description[], item_qty[], item_unit_price[], item_full_deposit[] (0/1
 * per row, marking lines that must be paid in full upfront). Customer fields
 * are sent as hidden inputs so Alpine x-model changes are captured on submission.
 *
 * Shared by the new-quote and edit-quote flows. Optional variables:
 *   string $formAction    POST target. Default '/admin/quotes' (create).
 *   string $submitLabel   Submit button text. Default 'Generate Quote & Get Link'.
 *   array  $initialState  Seed for the Alpine component (edit mode pre-fill).
 *                         Keys: customerId, customerName, customerEmail,
 *                         customerPhone, eventDate, validDays, depositPct,
 *                         deliveryFee, notes, items[]. Defaults to an empty
 *                         new-quote state.
 *
 * @see \App\Controllers\Admin\QuotesController::newForm()
 * @see \App\Controllers\Admin\QuotesController::create()
 * @see \App\Controllers\Admin\QuotesController::editForm()
 * @see \App\Controllers\Admin\QuotesController::update()
 */

/** @var array<int, array<string, mixed>> $customers */
/** @var string $csrfToken */
/** @var string $formAction */
/** @var string $submitLabel */
/** @var array<string, mixed> $initialState */

$formAction   = $formAction   ?? '/admin/quotes';
$submitLabel  = $submitLabel  ?? 'Generate Quote & Get Link';
$initialState = $initialState ?? [];
?>

<form method="POST" action="<?= htmlspecialchars($formAction) ?>">

<div class="admin-card" x-data="quoteBuilder(<?= htmlspecialchars(json_encode($initialState, JSON_THROW_ON_ERROR), ENT_QUOTES) ?>)" style="max-width:900px">

    <!-- ============================================================
         Customer section
         ============================================================ -->
    <div class="admin-card-header">
        <span class="admin-card-title">Customer</span>
    </div>

    <div class="form-group">
        <label>Search Existing Customer</label>
        <select name="customer_id_display" x-model="customerId"
                @change="selectCustomer($event.target.value)">
            <option value="0">— New Customer —</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= (int) $c['id'] ?>"
                    data-name="<?= htmlspecialchars((string) ($c['name'] ?? '')) ?>"
                    data-email="<?= htmlspecialchars((string) ($c['email'] ?? '')) ?>"
                    data-phone="<?= htmlspecialchars((string) ($c['phone'] ?? '')) ?>">
                <?= htmlspecialchars((string) ($c['name'] ?? '(no name)')) ?>
                <?php if (!empty($c['email'])): ?> — <?= htmlspecialchars((string) $c['email']) ?><?php endif; ?>
                <?php if ((float) ($c['lifetime_spend'] ?? 0) > 0): ?>
                    ($<?= number_format((float) $c['lifetime_spend'], 0) ?> lifetime)
                <?php endif; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- New customer fields — shown when "New Customer" selected -->
    <div x-show="customerId == 0" x-cloak>
        <div class="grid-2">
            <div class="form-group">
                <label>Name</label>
                <input type="text" x-model="customerName"
                       placeholder="Customer name">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" x-model="customerEmail">
            </div>
        </div>
        <div class="form-group" style="max-width:300px">
            <label>Phone</label>
            <input type="tel" x-model="customerPhone">
        </div>
    </div>

    <!-- ============================================================
         Event details
         ============================================================ -->
    <div class="admin-card-header" style="margin-top:1.5rem">
        <span class="admin-card-title">Event Details</span>
    </div>

    <div class="grid-2">
        <div class="form-group">
            <label>Event Date <span style="color:var(--color-muted); font-size:0.875em">(optional)</span></label>
            <input type="date" x-model="eventDate">
        </div>
        <div class="form-group">
            <label>Quote Valid For</label>
            <select x-model.number="validDays">
                <option value="7">7 days</option>
                <option value="14" selected>14 days</option>
                <option value="30">30 days</option>
            </select>
        </div>
    </div>

    <!-- ============================================================
         Line items
         ============================================================ -->
    <div class="admin-card-header" style="margin-top:1.5rem">
        <span class="admin-card-title">Line Items</span>
        <button type="button" class="btn btn-sm btn-outline" @click="addItem()">+ Add Item</button>
    </div>

    <table class="admin-table" style="margin-bottom:0">
        <thead>
            <tr>
                <th>Description</th>
                <th style="width:80px; text-align:center">Qty</th>
                <th style="width:130px; text-align:right">Unit Price</th>
                <th style="width:110px; text-align:center" title="Require this item to be paid in full upfront">100% Deposit</th>
                <th style="width:120px; text-align:right">Total</th>
                <th style="width:40px"></th>
            </tr>
        </thead>
        <tbody>
            <template x-for="(item, i) in items" :key="i">
                <tr>
                    <td>
                        <input type="text"
                               :name="'item_description[]'"
                               x-model="item.description"
                               placeholder="e.g. Red Rose Bouquet (24 stems)"
                               style="border:none; background:transparent; width:100%; padding:0.25rem">
                    </td>
                    <td>
                        <input type="number"
                               :name="'item_qty[]'"
                               x-model.number="item.qty"
                               min="1"
                               style="border:none; background:transparent; width:70px; text-align:center; padding:0.25rem">
                    </td>
                    <td>
                        <div style="display:flex; align-items:center; gap:0.25rem; justify-content:flex-end">
                            <span style="color:var(--color-muted)">$</span>
                            <input type="number"
                                   :name="'item_unit_price[]'"
                                   x-model.number="item.unit_price"
                                   min="0" step="0.01" placeholder="0.00"
                                   style="border:none; background:transparent; width:80px; text-align:right; padding:0.25rem">
                        </div>
                    </td>
                    <td style="text-align:center">
                        <!-- Visible toggle (no name); the hidden input below carries
                             one 0/1 value per row so indices stay aligned on POST. -->
                        <input type="checkbox" x-model="item.full_deposit"
                               style="width:18px; height:18px; cursor:pointer"
                               title="Charge this line in full as part of the deposit">
                        <input type="hidden" :name="'item_full_deposit[]'"
                               :value="item.full_deposit ? 1 : 0">
                    </td>
                    <td style="text-align:right">
                        <strong x-text="'$' + (item.qty * item.unit_price).toFixed(2)"></strong>
                    </td>
                    <td>
                        <button type="button"
                                @click="removeItem(i)"
                                style="background:none; border:none; color:#999; cursor:pointer; font-size:1.25rem; line-height:1; padding:0.25rem"
                                title="Remove item">
                            &times;
                        </button>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>

    <!-- ============================================================
         Totals
         ============================================================ -->
    <div style="display:flex; justify-content:flex-end; margin-top:1.5rem">
        <div style="width:320px">
            <div style="display:flex; justify-content:space-between; padding:0.5rem 0; border-bottom:1px solid var(--color-border)">
                <span style="color:var(--color-muted)">Subtotal</span>
                <strong x-text="'$' + subtotal.toFixed(2)"></strong>
            </div>
            <div x-show="taxRate > 0" style="display:flex; justify-content:space-between; padding:0.5rem 0; border-bottom:1px solid var(--color-border)">
                <span style="color:var(--color-muted)">Tax (<span x-text="(taxRate * 100).toFixed(3)"></span>%)</span>
                <strong x-text="'$' + taxAmount.toFixed(2)"></strong>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; padding:0.5rem 0; border-bottom:1px solid var(--color-border)">
                <span style="color:var(--color-muted)">Delivery Fee <span style="font-size:0.8em">(untaxed)</span></span>
                <div style="display:flex; align-items:center; gap:0.25rem">
                    <span style="color:var(--color-muted)">$</span>
                    <input type="number"
                           x-model.number="deliveryFee"
                           min="0" step="0.01" placeholder="0.00"
                           style="width:80px; text-align:right; padding:0.25rem; border:1px solid var(--color-border); border-radius:4px">
                </div>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 0; border-bottom:1px solid var(--color-border)">
                <div>
                    <span style="color:var(--color-muted)">Deposit</span>
                    <select x-model.number="depositPct"
                            style="margin-left:0.5rem; padding:0.25rem 0.5rem; border:1px solid var(--color-border); border-radius:4px; font-size:0.875rem">
                        <option value="25">25%</option>
                        <option value="50" selected>50%</option>
                        <option value="75">75%</option>
                        <option value="100">100%</option>
                    </select>
                </div>
                <strong style="color:var(--color-accent)" x-text="'$' + deposit.toFixed(2)"></strong>
            </div>
            <div style="display:flex; justify-content:space-between; padding:0.75rem 0; font-size:1.05rem">
                <strong>Total</strong>
                <strong x-text="'$' + total.toFixed(2)"></strong>
            </div>
        </div>
    </div>

    <!-- ============================================================
         Notes
         ============================================================ -->
    <div class="form-group" style="margin-top:1.5rem">
        <label>Notes <span style="color:var(--color-muted); font-size:0.875em">(shown to customer on the quote)</span></label>
        <textarea x-model="notes" rows="3"
                  placeholder="Any special instructions, color preferences, delivery notes…"></textarea>
    </div>

    <!-- ============================================================
         Hidden fields — carry Alpine state into the POST body
         ============================================================ -->
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="customer_id"    :value="customerId">
    <input type="hidden" name="customer_name"  :value="customerName">
    <input type="hidden" name="customer_email" :value="customerEmail">
    <input type="hidden" name="customer_phone" :value="customerPhone">
    <input type="hidden" name="deposit_pct"    :value="depositPct">
    <input type="hidden" name="delivery_fee"   :value="deliveryFee">
    <input type="hidden" name="valid_days"     :value="validDays">
    <input type="hidden" name="event_date"     :value="eventDate">
    <input type="hidden" name="notes"          :value="notes">

    <!-- ============================================================
         Submit
         ============================================================ -->
    <div style="margin-top:2rem; display:flex; gap:1rem; align-items:center">
        <button type="submit"
                class="btn btn-accent btn-lg"
                :disabled="items.length === 0 || subtotal === 0">
            <?= htmlspecialchars($submitLabel) ?>
        </button>
        <a href="/admin/quotes" class="btn btn-outline">Cancel</a>
        <span x-show="subtotal === 0"
              style="font-size:0.875rem; color:var(--color-muted)">
            Add at least one item with a price to continue.
        </span>
    </div>

</div><!-- /.admin-card -->

</form>

<script>
/**
 * Alpine.js component that powers the quote builder form.
 *
 * Manages customer selection, line-item CRUD, deposit calculation, and syncs
 * all reactive state back into the hidden form inputs so a plain HTML POST
 * carries the correct data. An optional `initial` object pre-fills the form for
 * edit mode; any omitted key falls back to the new-quote default.
 *
 * @param {object} [initial] Seed state: customerId, customerName, customerEmail,
 *   customerPhone, eventDate, validDays, depositPct, deliveryFee, notes, items[].
 * @returns {object} Alpine component data and methods.
 */
function quoteBuilder(initial) {
    initial = initial || {};
    var seededItems = Array.isArray(initial.items) ? initial.items : [];

    return {
        /** Selected customer ID; 0 means "new customer". */
        customerId: initial.customerId || 0,
        customerName: initial.customerName || '',
        customerEmail: initial.customerEmail || '',
        customerPhone: initial.customerPhone || '',

        eventDate: initial.eventDate || '',
        /** Number of days the quote remains valid. */
        validDays: initial.validDays || 14,
        /** Deposit percentage (25 | 50 | 75 | 100). */
        depositPct: initial.depositPct || 50,
        /**
         * Separately-stated delivery fee in dollars. Never taxed (Oklahoma
         * treats a separately-stated delivery charge as non-taxable) and never
         * included in the deposit — it falls due with the balance. Mirrors
         * App\Support\QuotePricing on the server.
         */
        deliveryFee: initial.deliveryFee || 0,
        /** Sales tax rate from server config, e.g. 0.08517. */
        taxRate: <?= (float) \App\Core\Config::get('BUSINESS_SALES_TAX_RATE', 0) ?>,
        notes: initial.notes || '',

        /** Line-item list; always starts with at least one row. */
        items: seededItems.length > 0 ? seededItems : [{ description: '', qty: 1, unit_price: 0, full_deposit: false }],

        /**
         * Sum of (qty × unit_price) for all items.
         * @returns {number}
         */
        get subtotal() {
            return this.items.reduce(function (sum, item) {
                return sum + (item.qty * item.unit_price);
            }, 0);
        },

        /**
         * Deposit amount in dollars. Items flagged full_deposit are counted in
         * full; the remaining items contribute depositPct percent of their
         * total. Mirrors QuoteService::calculateDeposit() on the server.
         * @returns {number}
         */
        get deposit() {
            var full = 0, rest = 0;
            this.items.forEach(function (item) {
                var line = (Number(item.qty) || 0) * (Number(item.unit_price) || 0);
                if (item.full_deposit) { full += line; } else { rest += line; }
            });
            return full + rest * (this.depositPct / 100);
        },

        /**
         * Tax amount in dollars based on subtotal × taxRate.
         * @returns {number}
         */
        get taxAmount() {
            return this.subtotal * this.taxRate;
        },

        /**
         * Grand total: subtotal plus tax plus the (untaxed) delivery fee.
         * @returns {number}
         */
        get total() {
            return this.subtotal + this.taxAmount + (Number(this.deliveryFee) || 0);
        },

        /** Append a blank line item to the table. */
        addItem() {
            this.items.push({ description: '', qty: 1, unit_price: 0, full_deposit: false });
        },

        /**
         * Remove the item at position i; always keeps at least one row.
         * @param {number} i Zero-based index.
         */
        removeItem(i) {
            if (this.items.length > 1) {
                this.items.splice(i, 1);
            }
        },

        /**
         * Populate name/email/phone fields when an existing customer is chosen.
         *
         * Reads data-name, data-email, and data-phone attributes from the
         * selected <option> element. Clears all three fields when "New Customer"
         * (id == 0) is selected.
         *
         * @param {string|number} id The selected customer ID value.
         */
        selectCustomer(id) {
            var opt = document.querySelector('select[name="customer_id_display"] option[value="' + id + '"]');
            if (opt && id != 0) {
                this.customerName  = opt.dataset.name  || '';
                this.customerEmail = opt.dataset.email || '';
                this.customerPhone = opt.dataset.phone || '';
            } else {
                this.customerName  = '';
                this.customerEmail = '';
                this.customerPhone = '';
            }
        },
    };
}
</script>
