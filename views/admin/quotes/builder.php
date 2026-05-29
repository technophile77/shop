<?php
/**
 * views/admin/quotes/builder.php — Alpine.js-powered quote builder form.
 *
 * Variables injected by QuotesController::newForm():
 *   array  $customers  All customer rows, each with id, name, email, phone, lifetime_spend.
 *   string $csrfToken  CSRF token for the POST form.
 *   string $pageTitle  Page title string.
 *
 * The form submits to POST /admin/quotes. Items are sent as parallel arrays:
 * item_description[], item_qty[], item_unit_price[]. Customer fields are sent
 * as hidden inputs so Alpine x-model changes are captured on submission.
 *
 * @see \App\Controllers\Admin\QuotesController::newForm()
 * @see \App\Controllers\Admin\QuotesController::create()
 */

/** @var array<int, array<string, mixed>> $customers */
/** @var string $csrfToken */
?>

<form method="POST" action="/admin/quotes">

<div class="admin-card" x-data="quoteBuilder()" style="max-width:900px">

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
                <strong x-text="'$' + subtotal.toFixed(2)"></strong>
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
            Generate Quote &amp; Get Link
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
 * carries the correct data.
 *
 * @returns {object} Alpine component data and methods.
 */
function quoteBuilder() {
    return {
        /** Selected customer ID; 0 means "new customer". */
        customerId: 0,
        customerName: '',
        customerEmail: '',
        customerPhone: '',

        eventDate: '',
        /** Number of days the quote remains valid. */
        validDays: 14,
        /** Deposit percentage (25 | 50 | 75 | 100). */
        depositPct: 50,
        notes: '',

        /** Line-item list; always starts with one blank row. */
        items: [{ description: '', qty: 1, unit_price: 0 }],

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
         * Deposit amount in dollars based on depositPct.
         * @returns {number}
         */
        get deposit() {
            return this.subtotal * (this.depositPct / 100);
        },

        /** Append a blank line item to the table. */
        addItem() {
            this.items.push({ description: '', qty: 1, unit_price: 0 });
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
