<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Quote;
use App\Services\QuoteService;
use App\Services\StripeService;

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
            $items     = QuoteService::decodeItems((string) ($quote['items_json'] ?? ''));
            $taxAmount = (float) ($quote['tax_amount'] ?? 0.00);
            $email     = (string) ($quote['customer_email'] ?? '');

            $session = StripeService::createQuoteCheckoutSession(
                (int) $quote['id'],
                $token,
                $items,
                $taxAmount,
                $email,
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
     * @param Request              $request HTTP GET request.
     * @param array<string, mixed> $params  Route parameters; must contain 'token'.
     *
     * @return Response Redirect to /quote/{token}.
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
                $total        = '$' . number_format((float) $quote['subtotal'] + (float) ($quote['tax_amount'] ?? 0), 2);
                QuoteService::notifyOwner(
                    "Stripe payment received — {$customerName} — {$total} — Quote #{$quote['id']}"
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
     * @param \Stripe\Checkout\Session $session The Stripe Session object from the event.
     */
    private function handleCheckoutSessionCompleted(object $session): void
    {
        $token = $session->metadata->quote_token ?? '';
        if ($token === '') {
            return;
        }

        $quote = Quote::findByToken((string) $token);
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
            $total        = '$' . number_format((float) $quote['subtotal'] + (float) ($quote['tax_amount'] ?? 0), 2);
            QuoteService::notifyOwner(
                "Stripe payment received — {$customerName} — {$total} — Quote #{$quote['id']}"
            );
        } catch (\Exception $e) {
            error_log('[StripeController] Failed to confirm quote from webhook: ' . $e->getMessage());
        }
    }
}
