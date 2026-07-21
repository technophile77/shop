<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Models\Order;
use App\Models\PageView;
use App\Models\Quote;
use App\Services\MailService;
use App\Services\QuoteService;
use App\Services\StripeService;
use App\Support\Analytics;

/**
 * Handles Stripe Checkout flows for quote full-payment and webhook events.
 *
 * All routes handled here are public (no auth middleware). The quote is
 * identified by its opaque token, not an integer ID, so the URL itself acts
 * as the credential.
 *
 * Routes:
 *   POST /quote/{token}/stripe/checkout  → {@see initiateQuoteCheckout()}
 *   GET  /quote/{token}/stripe/success   → {@see handleQuoteSuccess()}
 *   POST /stripe/webhook                 → {@see webhook()}
 *
 * @see \App\Services\StripeService
 * @see \App\Models\Quote
 */
final class StripeController extends BaseController
{
    /**
     * Creates a Stripe Checkout Session for the full quote amount and returns
     * the hosted-page URL so the Alpine.js client can redirect the browser.
     *
     * Validates the CSRF token and checks that the quote is in 'accepted'
     * status before creating the session. Stores the session ID on the quote
     * row so the webhook handler can look it up.
     *
     * @param Request              $request HTTP request (JSON body with _csrf_token).
     * @param array<string, mixed> $params  Route parameters; must contain 'token'.
     *
     * @return Response JSON {success: true, url: string} or {success: false, error: string}.
     */
    public function initiateQuoteCheckout(Request $request, array $params = []): Response
    {
        // CSRF check
        $csrfToken = (string) ($request->post('_csrf_token') ?? $request->header('X-CSRF-Token') ?? '');
        if (!$request->validateCsrf($csrfToken)) {
            return Response::json(['success' => false, 'error' => 'Invalid security token.'], 403);
        }

        $token = (string) ($params['token'] ?? '');
        $quote = Quote::findByToken($token);

        if ($quote === null) {
            return Response::json(['success' => false, 'error' => 'Quote not found.'], 404);
        }

        if ($quote['status'] !== 'accepted') {
            return Response::json(['success' => false, 'error' => 'This quote is not in an accepted state.'], 422);
        }

        if (!StripeService::isConfigured()) {
            return Response::json(['success' => false, 'error' => 'Card payment is not available at this time.'], 503);
        }

        try {
            $items       = QuoteService::decodeItems((string) ($quote['items_json'] ?? ''));
            $taxAmount   = (float) ($quote['tax_amount'] ?? 0.00);
            $email       = (string) ($quote['customer_email'] ?? '');
            $deliveryFee = (float) ($quote['delivery_fee'] ?? 0.00);

            $session = StripeService::createQuoteCheckoutSession(
                (int) $quote['id'],
                $token,
                $items,
                $taxAmount,
                $email,
                $deliveryFee,
            );

            Quote::setStripeCheckoutSession((int) $quote['id'], $session['id']);

            return Response::json(['success' => true, 'url' => $session['url']]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('[StripeController] Stripe API error: ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => 'Payment service error. Please try again.'], 502);
        } catch (\Exception $e) {
            error_log('[StripeController] Unexpected error: ' . $e->getMessage());
            return Response::json(['success' => false, 'error' => 'An unexpected error occurred.'], 500);
        }
    }

    /**
     * Handles the success redirect from Stripe after a completed payment.
     *
     * Reads the ?session_id= query parameter, verifies the payment was
     * successful, and transitions the quote to 'deposit_confirmed'. Idempotent:
     * if the quote is already confirmed, just redirects without re-processing.
     *
     * On the actual transition to paid (never on a re-hit of this URL, and never
     * when the webhook already confirmed the quote), enqueues a GA4/Pixel/Google
     * Ads `purchase` conversion into `$_SESSION['analytics_pending']` so
     * {@see \App\Support\Analytics::purchase()}'s spec is drained and emitted by
     * the very next page render (`views/layouts/public.php`, the redirect target
     * below). The conversion value is the amount Stripe actually charged for
     * this session (`amount_total`, in cents) rather than the quote's full total,
     * since a quote may only require a partial deposit. The transaction id is
     * namespaced `'quote-' . $quote['id']` so it can never collide with a shop
     * order id and cause Google Ads to de-duplicate two distinct conversions.
     *
     * @param Request              $request HTTP GET request.
     * @param array<string, mixed> $params  Route parameters; must contain 'token'.
     *
     * @return Response Redirect to /quote/{token}.
     *
     * @see \App\Support\Analytics::purchase()
     */
    public function handleQuoteSuccess(Request $request, array $params = []): Response
    {
        $token     = (string) ($params['token'] ?? '');
        $sessionId = (string) ($request->query('session_id', ''));

        $quote = Quote::findByToken($token);
        if ($quote === null) {
            return Response::notFound();
        }

        // Already confirmed — nothing to do (idempotent)
        if (in_array($quote['status'], ['deposit_confirmed', 'completed'], true)) {
            return $this->redirect('/quote/' . $token);
        }

        if ($sessionId === '') {
            return $this->redirect('/quote/' . $token);
        }

        try {
            $session = StripeService::retrieveCheckoutSession($sessionId);

            // Verify the session belongs to this quote
            $metaToken = $session->metadata->quote_token ?? '';
            if ($metaToken !== $token) {
                error_log('[StripeController] Token mismatch on success redirect: ' . $sessionId);
                return $this->redirect('/quote/' . $token);
            }

            if ($session->payment_status === 'paid') {
                $paymentIntentId = is_string($session->payment_intent)
                    ? $session->payment_intent
                    : (string) ($session->payment_intent->id ?? '');

                Quote::transition((int) $quote['id'], 'deposit_confirmed');
                Quote::updateStripePayment((int) $quote['id'], $sessionId, $paymentIntentId, 'stripe_full');

                // Notify owner via SMS
                $customerName = (string) ($quote['customer_name'] ?? 'Customer');
                $total        = '$' . number_format(
                    (float) $quote['subtotal'] + (float) ($quote['tax_amount'] ?? 0) + (float) ($quote['delivery_fee'] ?? 0),
                    2
                );
                QuoteService::notifyOwner(
                    "Stripe payment received — {$customerName} — {$total} — Quote #{$quote['id']}"
                );

                // Report the conversion for ad platforms. Enqueued into the session
                // so views/layouts/public.php drains and emits it on the very next
                // render (the redirect target below, /quote/{token}). This only runs
                // on the actual transition to paid above — the idempotency guard at
                // the top of this method (already 'deposit_confirmed'/'completed')
                // skips this whole block on a refresh or when the webhook beat us to
                // the transition, so the purchase event fires at most once here.
                $amountPaid   = (float) ($session->amount_total ?? 0) / 100;
                $quoteItems   = QuoteService::decodeItems((string) ($quote['items_json'] ?? ''));
                $lineItems    = [];
                foreach ($quoteItems as $i => $item) {
                    // Quote line items carry {description, qty, unit_price}, not the
                    // {item_id/id, item_name/name, price, quantity} keys Analytics::item()
                    // looks for — only qty/unit_price line up directly. Map description →
                    // name and synthesize a positional id (quotes aren't catalogue items).
                    $lineItems[] = [
                        'id'    => (string) ($i + 1),
                        'name'  => $item['description'],
                        'price' => $item['unit_price'],
                        'qty'   => $item['qty'],
                    ];
                }
                $_SESSION['analytics_pending'][] = Analytics::purchase(
                    ['id' => 'quote-' . $quote['id'], 'total' => $amountPaid],
                    $lineItems,
                );
            }
        } catch (\Exception $e) {
            error_log('[StripeController] Error verifying session ' . $sessionId . ': ' . $e->getMessage());
        }

        return $this->redirect('/quote/' . $token);
    }

    /**
     * Handles incoming Stripe webhook events.
     *
     * Reads the raw request body directly (bypassing the Request object) so
     * the stream is not consumed before signature verification. Only processes
     * checkout.session.completed events. Always returns HTTP 200 to prevent
     * Stripe from retrying.
     *
     * This handler is intentionally idempotent: it checks the current quote
     * status before attempting a transition.
     *
     * @param Request              $request HTTP POST request from Stripe.
     * @param array<string, mixed> $params  Route parameters (none).
     *
     * @return Response JSON {received: true} with HTTP 200.
     */
    public function webhook(Request $request, array $params = []): Response
    {
        // Read raw body BEFORE any $request->post() calls (stream is single-read).
        $payload   = (string) file_get_contents('php://input');
        $sigHeader = (string) ($request->header('Stripe-Signature') ?? '');

        try {
            $event = StripeService::constructWebhookEvent($payload, $sigHeader);
        } catch (\Exception $e) {
            error_log('[StripeController] Webhook signature verification failed: ' . $e->getMessage());
            return Response::json(['error' => 'Invalid signature.'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $this->handleCheckoutSessionCompleted($event->data->object);
        }

        return Response::json(['received' => true]);
    }

    /**
     * Processes a checkout.session.completed event object.
     *
     * Dispatches to the quote handler or the shop-order handler depending on
     * which metadata key is present:
     *   - metadata.quote_token  → existing quote full-payment flow (unchanged).
     *   - metadata.shop_order_id → shop-cart order payment flow (Phase 4).
     *
     * Both branches are idempotent. When neither key is present the event is
     * silently ignored so future session types can be added without breaking
     * the webhook.
     *
     * @param \Stripe\Checkout\Session $session The Stripe Session object from the event.
     */
    private function handleCheckoutSessionCompleted(object $session): void
    {
        // ── Quote path (existing behaviour — leave intact) ────────────────────
        $token = $session->metadata->quote_token ?? '';
        if ($token !== '') {
            $this->handleQuoteCheckoutCompleted($session, (string) $token);
            return;
        }

        // ── Shop-cart order path (Phase 4) ────────────────────────────────────
        $shopOrderId = $session->metadata->shop_order_id ?? '';
        if ($shopOrderId !== '') {
            $this->handleShopOrderCheckoutCompleted($session, (int) $shopOrderId);
        }
    }

    /**
     * Handles checkout.session.completed for a quote full-payment session.
     *
     * Extracted from the original handleCheckoutSessionCompleted() to keep the
     * dispatch method readable. Logic and behaviour are identical to Phase 3.
     *
     * @param \Stripe\Checkout\Session $session The Stripe Session object.
     * @param string                   $token   The quote token from metadata.
     */
    private function handleQuoteCheckoutCompleted(object $session, string $token): void
    {
        $quote = Quote::findByToken($token);
        if ($quote === null) {
            return;
        }

        // Idempotent: skip if already confirmed
        if (in_array($quote['status'], ['deposit_confirmed', 'completed'], true)) {
            return;
        }

        if (($session->payment_status ?? '') !== 'paid') {
            return;
        }

        $sessionId       = (string) ($session->id ?? '');
        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : (string) ($session->payment_intent->id ?? '');

        try {
            Quote::transition((int) $quote['id'], 'deposit_confirmed');
            Quote::updateStripePayment((int) $quote['id'], $sessionId, $paymentIntentId, 'stripe_full');

            $customerName = (string) ($quote['customer_name'] ?? 'Customer');
            $total        = '$' . number_format(
                (float) $quote['subtotal'] + (float) ($quote['tax_amount'] ?? 0) + (float) ($quote['delivery_fee'] ?? 0),
                2
            );
            QuoteService::notifyOwner(
                "Stripe payment received — {$customerName} — {$total} — Quote #{$quote['id']}"
            );
        } catch (\Exception $e) {
            error_log('[StripeController] Failed to confirm quote from webhook: ' . $e->getMessage());
        }
    }

    /**
     * Handles checkout.session.completed for a shop-cart order session.
     *
     * Idempotent: skips the update when the order is already marked paid.
     * Notifies the owner by email after marking the order paid, and marks the
     * ad session tracked on the order (if any) as converted — webhooks carry
     * no customer session of their own, so the token stored on the order at
     * creation time is used instead.
     *
     * @param \Stripe\Checkout\Session $session     The Stripe Session object.
     * @param int                      $shopOrderId The order ID from metadata.
     */
    private function handleShopOrderCheckoutCompleted(object $session, int $shopOrderId): void
    {
        if (($session->payment_status ?? '') !== 'paid') {
            return;
        }

        $order = Order::find($shopOrderId);
        if ($order === null) {
            error_log('[StripeController] Shop order not found for webhook: ' . $shopOrderId);
            return;
        }

        // Idempotent: skip if already marked paid
        if (($order['payment_status'] ?? '') === 'paid') {
            return;
        }

        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : (string) ($session->payment_intent->id ?? '');

        try {
            Order::markPaid($shopOrderId, $paymentIntentId);

            // Webhooks carry no customer session — use the tracking token stored on
            // the order at creation to mark the ad session converted (no-op when the
            // visitor had no ad session or the column is empty).
            if (!empty($order['session_token'])) {
                PageView::markConversion((string) $order['session_token'], 'order');
            }

            $customerName = (string) ($order['customer_name'] ?? 'Customer');
            $total        = '$' . number_format((float) ($order['total'] ?? 0), 2);
            $this->notifyOwnerShopOrder($order, $customerName, $total);
        } catch (\Exception $e) {
            error_log('[StripeController] Failed to mark shop order paid from webhook: ' . $e->getMessage());
        }
    }

    /**
     * Sends the owner a notification email when a shop order is paid.
     *
     * Failures are silently logged so a mail misconfiguration never prevents
     * the order from being recorded as paid.
     *
     * @param array<string, mixed> $order        The order row from Order::find().
     * @param string               $customerName Display name for the subject line.
     * @param string               $total        Formatted total string (e.g. '$58.83').
     */
    private function notifyOwnerShopOrder(array $order, string $customerName, string $total): void
    {
        $to = (string) Config::get('BUSINESS_EMAIL', '');
        if ($to === '' || !MailService::isConfigured()) {
            return;
        }

        $orderId  = (int) ($order['id'] ?? 0);
        $email    = (string) ($order['customer_email'] ?? '');
        $phone    = (string) ($order['customer_phone'] ?? '');
        $address  = (string) ($order['delivery_address'] ?? '');
        $occasion = (string) ($order['occasion_type'] ?? '');
        $message  = (string) ($order['card_message'] ?? '');
        $appUrl   = rtrim((string) Config::get('APP_URL', ''), '/');

        $rows = '';
        foreach ([
            'Order #'          => (string) $orderId,
            'Customer'         => $customerName,
            'Email'            => $email,
            'Phone'            => $phone,
            'Occasion'         => $occasion,
            'Delivery Address' => $address,
            'Card Message'     => $message,
            'Order Total'      => $total,
        ] as $label => $value) {
            if ($value === '' || $value === '0') {
                continue;
            }
            $rows .= '<tr>'
                . '<td style="padding:6px 12px;font-weight:600;color:#555;white-space:nowrap">'
                . htmlspecialchars($label) . '</td>'
                . '<td style="padding:6px 12px;color:#1a1a1a">'
                . nl2br(htmlspecialchars($value)) . '</td>'
                . '</tr>';
        }

        $html = MailService::buildHtml(
            '<h2 style="margin:0 0 1rem;font-size:1.2rem">New Shop Order — Payment Received</h2>'
            . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
            . '<p style="margin:1.5rem 0 0;font-size:0.85rem;color:#999">'
            . 'View all orders in the <a href="' . htmlspecialchars($appUrl) . '/admin">admin panel</a>.'
            . '</p>'
        );

        $result = MailService::send(
            $to,
            '',
            'New Shop Order #' . $orderId . ' — ' . $customerName . ' — ' . $total,
            $html
        );

        if (!$result['success']) {
            error_log('[StripeController] Shop order notification failed: ' . ($result['error'] ?? 'unknown'));
        }
    }
}
