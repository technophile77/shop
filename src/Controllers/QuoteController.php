<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Models\Quote;
use App\Services\QuoteService;

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
     * Upserts the customer from the posted name / email / phone, links the
     * customer to the quote if not already linked, and transitions the quote
     * to `accepted`. Returns JSON so the Alpine.js component can advance the
     * step without a full page reload.
     *
     * @param Request              $request HTTP request (JSON or form POST).
     * @param array<string, mixed> $params  Route parameters; must contain 'token'.
     *
     * @return Response JSON with `success: true` and `step: 'payment'` on
     *         success, or `success: false` and `error: string` on failure.
     *
     * @example
     *   // POST /quote/abc123def456… — body: {name, email, phone, _csrf_token}
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

        $customerId = Customer::upsert([
            'name'           => $name  !== '' ? $name  : null,
            'email'          => $email !== '' ? $email : null,
            'phone'          => $phone !== '' ? $phone : null,
            'source'         => 'quote_accept',
            'opted_in_email' => 0,
            'opted_in_sms'   => 0,
        ]);

        // Link customer to the quote only if it has none yet.
        if (empty($quote['customer_id'])) {
            $db = \App\Core\Database::rw();
            $db->prepare('UPDATE quotes SET customer_id = ? WHERE id = ?')
               ->execute([$customerId, (int) $quote['id']]);
        }

        Quote::transition((int) $quote['id'], 'accepted');

        return Response::json(['success' => true, 'step' => 'payment']);
    }

    /**
     * Records that the customer has sent the deposit.
     *
     * Validates CSRF, verifies the quote is in `accepted` status, transitions
     * it to `deposit_confirmed`, and fires an SMS notification to the shop
     * owner. Returns JSON so the Alpine.js component can advance to the
     * confirmation step.
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

        return Response::json(['success' => true]);
    }
}
