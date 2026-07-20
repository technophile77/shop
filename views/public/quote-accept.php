<?php
/**
 * views/public/quote-accept.php — Public quote acceptance page.
 *
 * Rendered inside views/layouts/public.php. Presents the quote to the customer
 * in a three-step Alpine.js flow: review → payment → confirmed.
 *
 * The line-items table is rendered outside all step divs so customers see
 * their quote details regardless of which step they land on (e.g. a customer
 * who has already accepted sees their items on the payment step too).
 *
 * Variables injected by QuoteController::show():
 *   array<string, mixed>                              $quote      Quote row (includes token, status,
 *                                                                 subtotal, tax_amount, deposit_amount,
 *                                                                 event_date, valid_until, notes,
 *                                                                 items_json, customer_* columns).
 *   array<int, array{description,qty,unit_price}>     $items      Decoded items from QuoteService::decodeItems().
 *   string                                            $csrfToken  Session CSRF token.
 *   bool                                              $expired    True when valid_until is in the past.
 *   string                                            $lang       Active locale code ('en' or 'es').
 *   string                                            $startStep  Initial Alpine step: 'review'|'payment'|'confirmed'.
 *
 * @see \App\Controllers\QuoteController::show()
 * @see \App\Services\QuoteService::decodeItems()
 */

use App\Core\Config;
?>

<section class="section section--dark" style="padding: 3rem 0">
  <div class="container">

    <!-- Quote Card -->
    <div class="quote-card" x-data="quoteApp()" x-cloak>

      <!-- Quote Header (dark) -->
      <div class="quote-header" style="text-align:center">
        <img src="/public/assets/images/logo.jpg"
             style="height:50px; margin: 0 auto 1rem; display:block"
             alt="<?= htmlspecialchars(Config::get('BUSINESS_NAME', '')) ?>">
        <span class="eyebrow label" style="color:rgba(255,255,255,0.4)"><?= htmlspecialchars(__t('quote.title')) ?></span>
        <h2 style="color:white; margin: 0.5rem 0"><?= htmlspecialchars(Config::get('BUSINESS_NAME', '')) ?></h2>
        <?php if (!empty($quote['event_date'])): ?>
        <p style="color:rgba(255,255,255,0.6); margin: 0.25rem 0">
          <?= htmlspecialchars(__t('quote.event_date')) ?>: <?= htmlspecialchars(date('F j, Y', (int) strtotime((string) $quote['event_date']))) ?>
        </p>
        <?php endif; ?>
        <?php if (!empty($quote['valid_until'])): ?>
        <p style="color:rgba(255,255,255,0.4); font-size:0.875rem; margin:0.25rem 0">
          <?= htmlspecialchars(__t('quote.valid_until')) ?>: <?= htmlspecialchars(date('F j, Y', (int) strtotime((string) $quote['valid_until']))) ?>
        </p>
        <?php endif; ?>
      </div><!-- /.quote-header -->

      <?php if ($expired): ?>
      <!-- Expired state -->
      <div class="quote-body text-center" style="padding: 4rem 2rem">
        <p style="color:var(--color-muted); font-size:1.1rem"><?= htmlspecialchars(__t('quote.expired')) ?></p>
        <a href="/contact" class="btn btn-primary" style="margin-top:1.5rem">
          <?= htmlspecialchars(__t('contact.title')) ?>
        </a>
      </div>

      <?php else: ?>

      <?php
      $taxRate     = (float) ($quote['tax_rate']     ?? 0.0);
      $taxAmount   = (float) ($quote['tax_amount']   ?? 0.0);
      $quoteTotal  = (float) $quote['subtotal'] + $taxAmount;

      // Deposit breakdown: items flagged full_deposit are charged in full; the
      // remainder follows deposit_pct. Used to explain the deposit total below.
      $fullDepositTotal = 0.0;
      foreach ($items as $itm) {
          if (!empty($itm['full_deposit'])) {
              $fullDepositTotal += (float) $itm['unit_price'] * (int) $itm['qty'];
          }
      }
      $hasFullDepositItems  = $fullDepositTotal > 0;
      $depositRemainingBase = (float) $quote['subtotal'] - $fullDepositTotal;
      $depositPct           = (int) ($quote['deposit_pct'] ?? 0);
      ?>

      <!-- ─── Always-visible quote summary ─────────────────────────────────── -->
      <div class="quote-body" style="border-bottom:1px solid var(--color-border)">
        <!--
          The line items and the totals are two separate <table>s on purpose.
          .quote-table-wrap below gives the item rows a horizontal-scroll
          safety net (see its CSS doc comment) for viewports too narrow to
          fit qty/price/amount even after the mobile column-width and
          padding overrides. If the totals lived inside that same scrollable
          table, the Total — the single most important number on the page —
          could scroll out of view along with the items. Keeping totals in
          their own (never-scrolled) table guarantees it can't.
        -->
        <div class="quote-table-wrap">
          <table class="quote-table" style="width:100%">
            <thead>
              <tr>
                <th class="col-desc"><?= htmlspecialchars(__t('quote.items_heading')) ?></th>
                <th class="col-qty"><?= htmlspecialchars(__t('quote.qty')) ?></th>
                <th class="col-price"><?= htmlspecialchars(__t('quote.unit_price')) ?></th>
                <th class="col-amount"><?= htmlspecialchars(__t('quote.subtotal')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
              <tr>
                <td class="col-desc">
                  <?= htmlspecialchars((string) $item['description']) ?>
                  <?php if (!empty($item['full_deposit'])): ?>
                  <span style="display:inline-block; margin-left:0.4rem; padding:0.1rem 0.5rem; font-size:0.68rem; font-weight:600; color:var(--color-primary); background:#f3e8f1; border-radius:999px; white-space:nowrap"><?= htmlspecialchars(__t('quote.paid_in_full')) ?></span>
                  <?php endif; ?>
                </td>
                <td class="col-qty"><?= (int) $item['qty'] ?></td>
                <td class="col-price">$<?= number_format((float) $item['unit_price'], 2) ?></td>
                <td class="col-amount">$<?= number_format((float) $item['qty'] * (float) $item['unit_price'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div><!-- /.quote-table-wrap -->

        <table class="quote-table quote-totals-table">
          <tfoot>
            <tr class="quote-total-row">
              <td class="quote-total-label" style="padding-top:1rem; font-weight:600">
                <?= htmlspecialchars(__t('quote.subtotal')) ?>
              </td>
              <td class="quote-total-value" style="padding-top:1rem; color:var(--color-primary); font-weight:700; font-size:1.2rem">
                $<?= number_format((float) $quote['subtotal'], 2) ?>
              </td>
            </tr>
            <?php if ($taxAmount > 0): ?>
            <tr>
              <td class="quote-total-label" style="padding-top:0.5rem; color:var(--color-muted)">
                Tax (<?= number_format($taxRate * 100, 3) ?>%)
              </td>
              <td class="quote-total-value" style="padding-top:0.5rem; color:var(--color-primary); font-weight:600">
                $<?= number_format($taxAmount, 2) ?>
              </td>
            </tr>
            <?php endif; ?>
            <tr class="quote-total-row">
              <td class="quote-total-label" style="padding-top:0.5rem; font-weight:700">
                Total
              </td>
              <td class="quote-total-value" style="padding-top:0.5rem; color:var(--color-primary); font-weight:700; font-size:1.2rem">
                $<?= number_format($quoteTotal, 2) ?>
              </td>
            </tr>
          </tfoot>
        </table>

        <?php if (!empty($quote['notes'])): ?>
        <div style="margin-top:1.5rem; padding:1rem; background:#f9f4f9; border-radius:8px; font-size:0.9rem; color:var(--color-text-dark)">
          <strong><?= htmlspecialchars(__t('quote.notes') ?: 'Notes') ?>:</strong>
          <p style="margin:0.5rem 0 0; white-space:pre-wrap"><?= htmlspecialchars((string) $quote['notes']) ?></p>
        </div>
        <?php endif; ?>
      </div><!-- /.quote-body always-visible summary -->

      <!-- ─── Step: Review ─────────────────────────────────────────────────── -->
      <div class="quote-body" x-show="step === 'review'">

        <!-- Deposit box -->
        <div class="quote-deposit-box" style="margin-top:2rem">
          <span class="eyebrow label" style="color:rgba(255,255,255,0.4)"><?= htmlspecialchars(__t('quote.deposit_label')) ?></span>
          <div style="font-family:'Cormorant Garamond',serif; font-size:3rem; color:var(--color-accent); margin:0.5rem 0">
            $<?= number_format((float) $quote['deposit_amount'], 2) ?>
          </div>
          <?php if ($hasFullDepositItems): ?>
          <p style="color:rgba(255,255,255,0.6); font-size:0.85rem; max-width:420px; margin:0 auto">
            <?= htmlspecialchars(strtr(__t('quote.deposit_breakdown'), [
                '{full}' => '$' . number_format($fullDepositTotal, 2),
                '{pct}'  => (string) $depositPct,
                '{rest}' => '$' . number_format($depositRemainingBase, 2),
            ])) ?>
          </p>
          <?php endif; ?>
          <p style="color:rgba(255,255,255,0.6); font-size:0.9rem"><?= htmlspecialchars(__t('quote.deposit_note')) ?></p>

          <?php if ($quote['status'] === 'sent'): ?>
          <!-- Accept form — collects customer contact info -->
          <form @submit.prevent="acceptQuote"
                style="margin-top:1.5rem; max-width:400px; margin-left:auto; margin-right:auto">
            <div class="form-group" style="margin-bottom:0.75rem">
              <input type="text"
                     x-model="customerName"
                     placeholder="<?= htmlspecialchars(__t('order.name')) ?>"
                     autocomplete="name"
                     style="background:rgba(255,255,255,0.1); border-color:rgba(255,255,255,0.2); color:#fff; text-align:center">
            </div>
            <div class="form-group" style="margin-bottom:0.75rem">
              <input type="email"
                     x-model="customerEmail"
                     placeholder="<?= htmlspecialchars(__t('order.email')) ?> (<?= htmlspecialchars(__t('general.optional')) ?>)"
                     autocomplete="email"
                     style="background:rgba(255,255,255,0.1); border-color:rgba(255,255,255,0.2); color:#fff; text-align:center">
            </div>
            <div class="form-group" style="margin-bottom:1.5rem">
              <input type="tel"
                     x-model="customerPhone"
                     placeholder="<?= htmlspecialchars(__t('order.phone')) ?> (<?= htmlspecialchars(__t('general.optional')) ?>)"
                     autocomplete="tel"
                     style="background:rgba(255,255,255,0.1); border-color:rgba(255,255,255,0.2); color:#fff; text-align:center">
            </div>
            <button type="submit" class="btn btn-accent btn-lg" style="width:100%" :disabled="accepting">
              <span x-show="!accepting"><?= htmlspecialchars(__t('quote.accept_button')) ?></span>
              <span x-show="accepting"><?= htmlspecialchars(__t('general.loading')) ?></span>
            </button>
            <p x-show="acceptError"
               style="color:#ff9999; margin-top:0.75rem; font-size:0.9rem; text-align:center"
               x-text="acceptError"></p>
          </form>

          <?php elseif (in_array($quote['status'], ['accepted', 'deposit_confirmed', 'completed'], true)): ?>
          <!-- Quote already accepted — offer a shortcut to the payment step -->
          <p style="color:rgba(255,255,255,0.6); margin-top:1rem">
            <?= htmlspecialchars(__t('quote.accept_button')) ?> ✓
          </p>
          <button class="btn btn-outline-light btn-lg"
                  style="margin-top:1rem"
                  @click="step = 'payment'">
            <?= htmlspecialchars(__t('quote.payment_title')) ?>
          </button>
          <?php endif; ?>

        </div><!-- /.quote-deposit-box -->
      </div><!-- /.quote-body review -->

      <!-- ─── Step: Payment ────────────────────────────────────────────────── -->
      <div class="quote-body" x-show="step === 'payment'" x-cloak>
        <div class="text-center" style="margin-bottom:2rem">
          <span class="eyebrow label"><?= htmlspecialchars(__t('quote.payment_title')) ?></span>
          <h3 style="margin:0.5rem 0">
            <?= htmlspecialchars(
                str_replace(
                    '{amount}',
                    '$' . number_format((float) $quote['deposit_amount'], 2),
                    __t('quote.payment_instructions')
                )
            ) ?>
          </h3>
        </div>

        <div class="payment-options">
          <?php if (Config::get('ZELLE_PHONE')): ?>
          <div class="payment-option">
            <div class="label"><?= htmlspecialchars(__t('quote.payment_zelle')) ?></div>
            <div class="value"><?= htmlspecialchars((string) Config::get('ZELLE_PHONE')) ?></div>
          </div>
          <?php endif; ?>
          <?php if (Config::get('CASHAPP_TAG')): ?>
          <div class="payment-option">
            <div class="label"><?= htmlspecialchars(__t('quote.payment_cashapp')) ?></div>
            <div class="value"><?= htmlspecialchars((string) Config::get('CASHAPP_TAG')) ?></div>
          </div>
          <?php endif; ?>
        </div>

        <?php
        $quoteTotal = (float) ($quote['subtotal'] ?? 0) + (float) ($quote['tax_amount'] ?? 0);
        ?>
        <?php if (\App\Services\StripeService::isConfigured()): ?>
        <div style="border-top:1px solid var(--color-border); margin-top:2rem; padding-top:1.5rem">
            <div class="label" style="margin-bottom:0.5rem; text-transform:uppercase; font-size:0.75rem; letter-spacing:0.05em; color:var(--color-muted)">
                Or Pay in Full by Card
            </div>
            <p style="font-size:0.875rem; color:var(--color-muted); margin:0 0 1rem">
                Card payments charge the full amount of $<?= number_format($quoteTotal, 2) ?>.
                Zelle and CashApp above are preferred — no fees and non-disputable.
            </p>
            <button class="btn btn-outline"
                    style="width:100%"
                    @click="payByCard()"
                    :disabled="redirectingToStripe || submitting">
                <span x-show="!redirectingToStripe">💳 Pay $<?= number_format($quoteTotal, 2) ?> by Card</span>
                <span x-show="redirectingToStripe">Redirecting to payment…</span>
            </button>
            <p x-show="stripeError"
               x-text="stripeError"
               style="color:#e53e3e; margin-top:0.5rem; font-size:0.875rem; text-align:center"></p>
        </div>
        <?php endif; ?>

        <div style="text-align:center; margin-top:2.5rem">
          <button class="btn btn-accent btn-lg"
                  @click="confirmDeposit()"
                  :disabled="confirming">
            <span x-show="!confirming"><?= htmlspecialchars(__t('quote.confirm_button')) ?></span>
            <span x-show="confirming"><?= htmlspecialchars(__t('general.loading')) ?></span>
          </button>
          <p x-show="depositError"
             style="color:#e53e3e; margin-top:0.75rem"
             x-text="depositError"></p>
        </div>
      </div><!-- /.quote-body payment -->

      <!-- ─── Step: Confirmed ──────────────────────────────────────────────── -->
      <div class="quote-body text-center" x-show="step === 'confirmed'" x-cloak>
        <div style="font-size:3rem; margin-bottom:1rem" aria-hidden="true">&#127800;</div>
        <h2><?= htmlspecialchars(__t('quote.confirmed_title')) ?></h2>
        <p style="color:var(--color-muted); max-width:500px; margin:1rem auto 2rem">
          <?= htmlspecialchars(__t('quote.confirmed_message')) ?>
        </p>
        <div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap">
          <?php if (Config::get('FACEBOOK_URL')): ?>
          <a href="<?= htmlspecialchars((string) Config::get('FACEBOOK_URL')) ?>"
             class="btn btn-outline"
             target="_blank"
             rel="noopener noreferrer">
            Facebook
          </a>
          <?php endif; ?>
          <?php
          $igUrl = Config::get(
              'INSTAGRAM_URL',
              'https://www.instagram.com/' . Config::get('INSTAGRAM_HANDLE', '')
          );
          ?>
          <?php if ($igUrl): ?>
          <a href="<?= htmlspecialchars((string) $igUrl) ?>"
             class="btn btn-outline"
             target="_blank"
             rel="noopener noreferrer">
            Instagram
          </a>
          <?php endif; ?>
        </div>
      </div><!-- /.quote-body confirmed -->

      <?php endif; // end if !$expired ?>

    </div><!-- /.quote-card -->
  </div><!-- /.container -->
</section>

<script>
/**
 * Alpine.js component that drives the three-step quote acceptance flow.
 *
 * Steps:
 *   review    — customer reads the deposit amount and submits their contact info.
 *   payment   — customer sees payment instructions and confirms they sent money.
 *   confirmed — success state shown after deposit_confirmed or completed.
 *
 * The initial step is determined server-side by QuoteController::show() and
 * injected as $startStep, avoiding a mismatch between the PHP status and the
 * JS step name.
 *
 * Both acceptQuote() and confirmDeposit() POST JSON to the PHP endpoints and
 * advance the step on success, or display an inline error message on failure.
 *
 * @returns {object} Alpine component data and methods.
 */
function quoteApp() {
  return {
    /** Active step: 'review' | 'payment' | 'confirmed' */
    step: <?= json_encode($startStep, JSON_THROW_ON_ERROR) ?>,

    customerName:  '',
    customerEmail: '',
    customerPhone: '',

    /** True while the accept POST is in flight. */
    accepting:   false,
    /** Error message shown below the accept form, or empty string. */
    acceptError: '',

    /** True while the deposit-confirm POST is in flight. */
    confirming:   false,
    /** Error message shown below the confirm button, or empty string. */
    depositError: '',

    /** True while the browser is being redirected to Stripe Checkout. */
    redirectingToStripe: false,
    /** Error message shown below the card payment button, or empty string. */
    stripeError: '',

    token: <?= json_encode($quote['token'] ?? '', JSON_THROW_ON_ERROR) ?>,
    csrf:  <?= json_encode($csrfToken,           JSON_THROW_ON_ERROR) ?>,

    /**
     * Posts customer info and transitions the quote to 'accepted'.
     * Advances to the 'payment' step on success.
     *
     * @returns {Promise<void>}
     */
    async acceptQuote() {
      if (!this.customerName.trim()) {
        this.acceptError = '<?= addslashes(__t('order.name')) ?> is required.';
        return;
      }
      this.accepting   = true;
      this.acceptError = '';

      try {
        const res = await fetch('/quote/' + this.token + '/accept', {
          method:  'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': this.csrf,
          },
          body: JSON.stringify({
            name:         this.customerName,
            email:        this.customerEmail,
            phone:        this.customerPhone,
            _csrf_token:  this.csrf,
          }),
        });

        const data = await res.json();
        if (data.success) {
          this.step = 'payment';
        } else {
          this.acceptError = data.error || '<?= addslashes(__t('general.error')) ?>';
        }
      } catch {
        this.acceptError = '<?= addslashes(__t('general.error')) ?>';
      } finally {
        this.accepting = false;
      }
    },

    /**
     * Notifies the server that the deposit has been sent and advances to
     * the 'confirmed' step on success.
     *
     * @returns {Promise<void>}
     */
    async confirmDeposit() {
      this.confirming   = true;
      this.depositError = '';

      try {
        const res = await fetch('/quote/' + this.token + '/deposit', {
          method:  'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': this.csrf,
          },
          body: JSON.stringify({ _csrf_token: this.csrf }),
        });

        const data = await res.json();
        if (data.success) {
          this.step = 'confirmed';
        } else {
          this.depositError = data.error || '<?= addslashes(__t('general.error')) ?>';
        }
      } catch {
        this.depositError = '<?= addslashes(__t('general.error')) ?>';
      } finally {
        this.confirming = false;
      }
    },

    /**
     * Initiates a Stripe Checkout Session and redirects the browser to the
     * Stripe-hosted payment page. Shows an inline error if the request fails.
     *
     * @returns {Promise<void>}
     */
    async payByCard() {
      this.redirectingToStripe = true;
      this.stripeError = '';
      try {
        const res = await fetch('/quote/' + this.token + '/stripe/checkout', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ _csrf_token: this.csrf }),
        });
        const data = await res.json();
        if (data.success && data.url) {
          window.location.href = data.url;
        } else {
          this.stripeError = data.error || 'Something went wrong. Please try again.';
          this.redirectingToStripe = false;
        }
      } catch {
        this.stripeError = 'Connection error. Please try again.';
        this.redirectingToStripe = false;
      }
    },
  };
}
</script>
