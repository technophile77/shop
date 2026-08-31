<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Models\PageView;
use App\Models\Quote;
use App\Services\MailService;
use App\Services\QuoteService;
use App\Support\CustomerSource;
use App\Support\QuotePaymentEmail;

/**
 * Handles the customer-facing quote acceptance flow.
 *
 * These are fully public routes — no authentication is required. The quote is
 * looked up by its opaque token so the URL is the only credential the customer
 * needs.
 *
 * Routes:
 *   GET  /quote/{token}          → {@see show()}
 *   POST /quote/{token}/accept   → {@see accept()}
 *   POST /quote/{token}/deposit  → {@see confirmDeposit()}
 *
 * @see \App\Models\Quote
 * @see \App\Services\QuoteService
 */
final class QuoteController extends BaseController
{
    /**
     * Renders the public quote acceptance page.
     *
     * Resolves the quote from the token in the URL. Returns a 404 when the
     * token is unknown or the quote is cancelled. When the quote is found, the
     * view receives the decoded items array, an `$expired` flag, and a
     * `$startStep` value so the Alpine.js component lands on the correct step
     * without client-side status inference.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters; must contain 'token'.
     *
     * @return Response Rendered HTML response, or a 404.
     *
     * @example
     *   // GET /quote/abc123def456…
     */
    public function show(Request $request, array $params = []): Response
    {
        $token = (string) ($params['token'] ?? '');
        $quote = Quote::findByToken($token);

        if ($quote === null) {
            return Response::notFound();
        }

        try {
            $items = QuoteService::decodeItems((string) ($quote['items_json'] ?? ''));
        } catch (\JsonException) {
            $items = [];
            error_log('[QuoteController] Failed to decode items_json for token: ' . $token);
        }

        $expired = !empty($quote['valid_until'])
            && strtotime((string) $quote['valid_until']) < strtotime('today');

        $startStep = match($quote['status'] ?? '') {
            'deposit_confirmed', 'completed' => 'confirmed',
            'accepted'                        => 'payment',
            default                           => 'review',
        };

        $html = $this->render('public/quote-accept', [
            'quote'      => $quote,
            'items'      => $items,
            'csrfToken'  => $request->csrfToken(),
            'expired'    => $expired,
            'lang'       => Lang::current(),
            'pageTitle'  => __t('quote.title'),
            'startStep'  => $startStep,
        ]);

        return Response::html($html);
    }

    /**
     * Accepts the quote on behalf of the customer.
     *
     * Validates the CSRF token, then verifies the quote is in `sent` status.
     * Upserts the customer from the posted name / email / phone and the
     * posted `opted_in_email` / `opted_in_sms` A2P 10DLC consent flags
     * (source resolved from the session's UTM attribution, falling back to
     * 'quote_accept'), links the customer to the quote if not already linked,
     * and transitions the quote to `accepted`, marking the visitor's ad
     * session as converted. Returns JSON so the Alpine.js component can
     * advance the step without a full page reload.
     *
     * @param Request              $request HTTP request (JSON or form POST).
     * @param array<string, mixed> $params  Route parameters; must contain 'token'.
     *
     * @return Response JSON with `success: true` and `step: 'payment'` on
     *         success, or `success: false` and `error: string` on failure.
     *
     * @example
     *   // POST /quote/abc123def456… — body: {name, email, phone,
     *   //   opted_in_email, opted_in_sms, _csrf_token}
     *   // → {"success":true,"step":"payment"}
     */
    public function accept(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(
                ['success' => false, 'error' => 'Invalid security token.'],
                422
            );
        }

        $token = (string) ($params['token'] ?? '');
        $quote = Quote::findByToken($token);

        if ($quote === null || $quote['status'] !== 'sent') {
            return Response::json(
                ['success' => false, 'error' => 'This quote is not available for acceptance.'],
                422
            );
        }

        $name  = trim((string) $request->post('name',  ''));
        $email = trim((string) $request->post('email', ''));
        $phone = trim((string) $request->post('phone', ''));

        $optedInEmail = $request->post('opted_in_email') ? 1 : 0;
        $optedInSms   = $request->post('opted_in_sms')   ? 1 : 0;

        $customerId = Customer::upsert([
            'name'           => $name  !== '' ? $name  : null,
            'email'          => $email !== '' ? $email : null,
            'phone'          => $phone !== '' ? $phone : null,
            'source'         => CustomerSource::resolve($_SESSION['utm'] ?? [], 'quote_accept'),
            'opted_in_email' => $optedInEmail,
            'opted_in_sms'   => $optedInSms,
        ]);

        // Link customer to the quote only if it has none yet.
        if (empty($quote['customer_id'])) {
            $db = \App\Core\Database::rw();
            $db->prepare('UPDATE quotes SET customer_id = ? WHERE id = ?')
               ->execute([$customerId, (int) $quote['id']]);
        }

        Quote::transition((int) $quote['id'], 'accepted');

        // Mark the visitor's ad session as converted now that the quote is accepted.
        PageView::markConversion(session_id(), 'quote');

        return Response::json(['success' => true, 'step' => 'payment']);
    }

    /**
     * Records that the customer has sent the deposit.
     *
     * Validates CSRF, verifies the quote is in `accepted` status, transitions
     * it to `deposit_confirmed`, and notifies the shop owner by SMS/voice call
     * ({@see \App\Services\QuoteService::notifyOwner()}) and, additively, by
     * email ({@see notifyOwnerDepositEmail()}). Returns JSON so the Alpine.js
     * component can advance to the confirmation step.
     *
     * @param Request              $request HTTP request (JSON or form POST).
     * @param array<string, mixed> $params  Route parameters; must contain 'token'.
     *
     * @return Response JSON with `success: true` on success, or
     *         `success: false` and `error: string` on failure.
     *
     * @example
     *   // POST /quote/abc123def456…/deposit — body: {_csrf_token}
     *   // → {"success":true}
     */
    public function confirmDeposit(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(
                ['success' => false, 'error' => 'Invalid security token.'],
                422
            );
        }

        $token = (string) ($params['token'] ?? '');
        $quote = Quote::findByToken($token);

        if ($quote === null || $quote['status'] !== 'accepted') {
            return Response::json(
                ['success' => false, 'error' => 'This quote is not awaiting a deposit confirmation.'],
                422
            );
        }

        Quote::transition((int) $quote['id'], 'deposit_confirmed');

        $customerName   = !empty($quote['customer_name']) ? $quote['customer_name'] : 'Unknown';
        $eventDate      = !empty($quote['event_date'])    ? $quote['event_date']    : 'TBD';
        $depositAmount  = number_format((float) $quote['deposit_amount'], 2);

        QuoteService::notifyOwner(
            "Deposit confirmed — {$customerName} — {$eventDate} — \${$depositAmount}"
        );

        $this->notifyOwnerDepositEmail($quote, (float) $quote['deposit_amount']);

        return Response::json(['success' => true]);
    }

    /**
     * Emails the shop owner when a manually-confirmed (non-Stripe) deposit is
     * recorded — additive to the SMS/voice notification already sent by
     * {@see \App\Services\QuoteService::notifyOwner()} in {@see confirmDeposit()},
     * mirroring how {@see \App\Controllers\StripeController::notifyOwnerQuotePayment()}
     * pairs with its own SMS/voice call for a Stripe payment.
     *
     * Reuses {@see \App\Support\QuotePaymentEmail::bodyHtml()} for the rich
     * body — it's payment-method-agnostic despite living in a class named
     * after Stripe payments; the Stripe PaymentIntent row is simply omitted
     * here since $paymentIntentId is passed as ''. The subject line is built
     * locally rather than via {@see \App\Support\QuotePaymentEmail::subject()},
     * which hardcodes "Stripe Payment Received" — this deposit was reported
     * by the customer as sent via Zelle, CashApp, or similar, not charged
     * through Stripe, so that wording would misrepresent how it was paid.
     *
     * Failures are logged and swallowed so a mail problem never blocks the
     * deposit-confirmation flow.
     *
     * @param array<string, mixed> $quote         The quote row from {@see \App\Models\Quote::findByToken()}.
     * @param float                $depositAmount The confirmed deposit amount, in dollars.
     *
     * @see \App\Support\QuotePaymentEmail
     */
    private function notifyOwnerDepositEmail(array $quote, float $depositAmount): void
    {
        $to = (string) Config::get('BUSINESS_EMAIL', '');
        if ($to === '' || !MailService::canSend()) {
            return;
        }

        try {
            $items = QuoteService::decodeItems((string) ($quote['items_json'] ?? ''));
        } catch (\JsonException $e) {
            error_log('[QuoteController] Failed to decode quote items for deposit email: ' . $e->getMessage());
            $items = [];
        }

        $customerName = !empty($quote['customer_name']) ? (string) $quote['customer_name'] : 'Customer';
        $appUrl       = rtrim((string) Config::get('APP_URL', ''), '/');
        $subject      = 'Deposit Confirmed — Quote #' . (int) ($quote['id'] ?? 0)
            . " — {$customerName} — $" . number_format($depositAmount, 2);
        $html         = MailService::buildHtml(
            QuotePaymentEmail::bodyHtml($quote, $items, $depositAmount, '', $appUrl)
        );

        $result = MailService::send($to, '', $subject, $html);

        if (!$result['success']) {
            error_log('[QuoteController] Deposit confirmation email failed: ' . ($result['error'] ?? 'unknown'));
        }
    }
}
