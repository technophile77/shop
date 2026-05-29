<?php
/**
 * views/admin/sms/index.php — SMS campaign compose view.
 *
 * Displays a Twilio configuration warning when credentials are missing,
 * then renders the compose form powered by Alpine.js. The form sends a
 * JSON POST to /admin/sms/send and displays per-campaign send/fail counts.
 *
 * Variables expected from SmsController::index():
 *   int    $smsOptedIn       Count of opted-in-SMS customers with a phone number.
 *   array  $smsCustomers     All SMS-opted-in customer rows.
 *   bool   $twilioConfigured Whether Twilio env vars are all set.
 *   string $csrfToken        Session CSRF token.
 *
 * @see \App\Controllers\Admin\SmsController::index()
 * @see \App\Services\TwilioService
 */
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">SMS Campaign</h1>
        <p style="color:#666; font-size:0.9rem">Send a text message to your opted-in customers</p>
    </div>
</div>

<?php if (!$twilioConfigured): ?>
<div style="background:#fffbeb; border:1px solid #fbbf24; border-radius:8px; padding:1.5rem; margin-bottom:1.5rem">
    <h3 style="margin:0 0 0.5rem; color:#92400e; font-size:1rem">Twilio Not Configured</h3>
    <p style="margin:0; color:#78350f; font-size:0.9rem">
        To send SMS campaigns, add your Twilio credentials to the <code>.env</code> file:
        TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER.
        <a href="https://www.twilio.com" target="_blank" rel="noopener">Get a free Twilio account &rarr;</a>
    </p>
</div>
<?php endif; ?>

<div class="admin-card" x-data="smsComposer()">

    <!-- Stats bar -->
    <div style="display:flex; gap:2rem; margin-bottom:2rem; flex-wrap:wrap">
        <div>
            <div style="font-family:Montserrat,sans-serif; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:#999">SMS-Opted-In Customers</div>
            <div style="font-size:2rem; font-family:'Cormorant Garamond',serif; color:var(--color-primary)"><?= (int)$smsOptedIn ?></div>
        </div>
    </div>

    <!-- Compose form -->
    <form @submit.prevent="sendCampaign">

        <!-- Recipients -->
        <div class="admin-form-group">
            <label>Recipients</label>
            <div style="display:flex; flex-direction:column; gap:0.75rem">
                <label class="admin-form-check">
                    <input type="radio" name="recipients" value="all_sms" x-model="recipients" checked>
                    All SMS-opted-in customers (<?= (int)$smsOptedIn ?> contacts)
                </label>
                <label class="admin-form-check">
                    <input type="radio" name="recipients" value="vip" x-model="recipients">
                    VIP customers only (opted-in SMS, $<?= htmlspecialchars((string) Settings::get('vip_spend_threshold', '200')) ?>+ spend)
                </label>
                <label class="admin-form-check">
                    <input type="radio" name="recipients" value="manual" x-model="recipients">
                    Enter numbers manually
                </label>
                <div x-show="recipients === 'manual'" x-cloak>
                    <textarea
                        x-model="manualNumbers"
                        rows="4"
                        placeholder="One phone number per line (digits only)&#10;7203883496&#10;9185551234"
                        style="width:100%; font-family:monospace; font-size:0.85rem; padding:0.625rem; border:1px solid var(--color-border); border-radius:4px; resize:vertical"
                    ></textarea>
                </div>
            </div>
        </div>

        <!-- Message -->
        <div class="admin-form-group">
            <label>
                Message&nbsp;
                <span
                    x-text="message.length"
                    :style="{ color: message.length > 160 ? '#e53e3e' : 'var(--color-muted)' }"
                ></span>/160
            </label>
            <textarea
                x-model="message"
                rows="4"
                maxlength="160"
                placeholder="Hi! It's Perla's Flowers 🌸 Don't miss our special this week..."
                style="width:100%; padding:0.625rem; border:1px solid var(--color-border); border-radius:4px; resize:vertical"
            ></textarea>
            <small style="color:var(--color-muted)">Keep messages under 160 characters for a single SMS (no extra charges).</small>
        </div>

        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <!-- Send button -->
        <div style="display:flex; gap:1rem; align-items:center; flex-wrap:wrap">
            <button
                type="submit"
                class="admin-btn admin-btn-primary"
                :disabled="sending || message.length === 0 || message.length > 160 || !<?= $twilioConfigured ? 'true' : 'false' ?>"
                style="padding:0.75rem 2rem"
            >
                <span x-show="!sending">Send Campaign</span>
                <span x-show="sending" x-cloak>Sending&hellip;</span>
            </button>
            <span style="color:var(--color-muted); font-size:0.875rem" x-show="!sending">
                Will send to <strong x-text="recipientCount"></strong> contacts
            </span>
        </div>

        <!-- Success result -->
        <div
            x-show="results"
            style="margin-top:1.5rem; padding:1.5rem; background:#f0fff4; border-radius:8px; border-left:4px solid #38a169"
            x-cloak
        >
            <p style="margin:0; font-weight:600; color:#2f855a">
                Campaign sent! <span x-text="results?.sent"></span> delivered,
                <span x-text="results?.failed"></span> failed.
            </p>
        </div>

        <!-- Error message -->
        <div x-show="errorMsg" style="margin-top:1rem" x-cloak>
            <p style="color:#c53030; font-size:0.875rem; margin:0" x-text="errorMsg"></p>
        </div>

    </form>

</div><!-- /.admin-card -->

<script>
/**
 * Alpine.js component that drives the SMS campaign compose form.
 *
 * Manages recipient selection, character counting, the async send request,
 * and the results / error display states.
 *
 * @returns {object} Alpine.js component data object.
 */
function smsComposer() {
    return {
        recipients:    'all_sms',
        message:       '',
        manualNumbers: '',
        sending:       false,
        results:       null,
        errorMsg:      '',
        csrf:          '<?= htmlspecialchars($csrfToken) ?>',
        allSmsCount:   <?= (int)$smsOptedIn ?>,

        /**
         * Computed count of contacts that will receive the message.
         *
         * - 'all_sms'  Returns the pre-queried opted-in count.
         * - 'vip'      Returns the label '(VIP)' — exact count not pre-fetched.
         * - 'manual'   Counts non-blank lines in the textarea.
         *
         * @returns {number|string}
         */
        get recipientCount() {
            if (this.recipients === 'all_sms') return this.allSmsCount;
            if (this.recipients === 'vip') return '(VIP)';
            if (this.recipients === 'manual') {
                return this.manualNumbers.split('\n').filter(n => n.trim() !== '').length;
            }
            return 0;
        },

        /**
         * Submits the campaign via fetch() POST and updates the results state.
         *
         * Prompts for confirmation before sending. On success sets this.results;
         * on failure sets this.errorMsg.
         *
         * @returns {Promise<void>}
         */
        async sendCampaign() {
            if (!confirm(`Send to ${this.recipientCount} contacts?`)) return;
            this.sending  = true;
            this.results  = null;
            this.errorMsg = '';

            try {
                const res = await fetch('/admin/sms/send', {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrf,
                    },
                    body: JSON.stringify({
                        recipients:     this.recipients,
                        message:        this.message,
                        manual_numbers: this.manualNumbers,
                        _csrf_token:    this.csrf,
                    }),
                });

                const data = await res.json();

                if (data.success) {
                    this.results = data;
                } else {
                    this.errorMsg = data.error || 'An error occurred.';
                }
            } catch (e) {
                this.errorMsg = 'Network error — please try again.';
            } finally {
                this.sending = false;
            }
        },
    };
}
</script>
