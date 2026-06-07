<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use Stripe\StripeClient;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Webhook;

/**
 * Thin wrapper around the Stripe PHP SDK.
 *
 * All server-side Stripe operations go through this class so the rest of the
 * application is insulated from the SDK's API surface. The Stripe API version
 * is pinned to 2026-05-27.dahlia at construction time.
 *
 * @see \App\Controllers\StripeController
 */
final class StripeService
{
    private function __construct() {}

    /**
     * Returns an initialised StripeClient using STRIPE_SECRET from .env.
     * Pins the API version to 2026-05-27.dahlia.
     */
    private static function client(): StripeClient
    {
        return new StripeClient([
            'api_key'        => (string) Config::get('STRIPE_SECRET', ''),
            'stripe_version' => '2026-05-27.dahlia',
        ]);
    }

    /**
     * Returns true when STRIPE_SECRET is configured and non-empty.
     */
    public static function isConfigured(): bool
    {
        $secret = (string) Config::get('STRIPE_SECRET', '');
        return $secret !== '';
    }

    /**
     * Creates a Stripe Checkout Session for paying the full quote amount.
     *
     * Line items are built from the decoded quote items array so the Stripe
     * receipt mirrors the quote exactly. Sales tax is appended as a separate
     * line item when tax_amount > 0.00.
     *
     * Sets metadata['quote_token'] on the session so the webhook handler can
     * look up the quote without trusting URL parameters.
     *
     * @param int    $quoteId       Quote primary key (stored in metadata).
     * @param string $token         Quote opaque token (used in return URLs and metadata).
     * @param array<int, array{description: string, qty: int, unit_price: float}> $items
     *        Decoded quote items from QuoteService::decodeItems().
     * @param float  $taxAmount     Pre-computed tax in dollars from quote['tax_amount'].
     *                              Pass the stored value directly — do not recalculate.
     * @param string $customerEmail Pre-fills the email field on Stripe's hosted page.
     *
     * @return array{id: string, url: string}
     *
     * @throws \Stripe\Exception\ApiErrorException On Stripe API failure.
     *
     * @example
     *   $result = StripeService::createQuoteCheckoutSession(
     *       $quote['id'], $quote['token'], $items, (float)$quote['tax_amount'], $email
     *   );
     *   header('Location: ' . $result['url']);
     */
    public static function createQuoteCheckoutSession(
        int    $quoteId,
        string $token,
        array  $items,
        float  $taxAmount = 0.00,
        string $customerEmail = '',
    ): array {
        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');

        $lineItems = array_map(static fn(array $item): array => [
            'price_data' => [
                'currency'     => 'usd',
                'unit_amount'  => (int) round((float) $item['unit_price'] * 100),
                'product_data' => ['name' => (string) $item['description']],
            ],
            'quantity' => max(1, (int) $item['qty']),
        ], $items);

        if ($taxAmount > 0.00) {
            $lineItems[] = [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => (int) round($taxAmount * 100),
                    'product_data' => ['name' => 'Sales Tax'],
                ],
                'quantity' => 1,
            ];
        }

        $params = [
            'mode'        => 'payment',
            'line_items'  => $lineItems,
            'success_url' => $appUrl . '/quote/' . $token . '/stripe/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $appUrl . '/quote/' . $token,
            'metadata'    => [
                'quote_id'    => $quoteId,
                'quote_token' => $token,
            ],
            'custom_text' => [
                'after_submit' => [
                    'message' => 'Thank you! We\'d love a Google review once your arrangement is ready: '
                        . (string) Config::get('GOOGLE_REVIEW_URL', 'https://flowers.cresswell.org'),
                ],
            ],
        ];

        if ($customerEmail !== '') {
            $params['customer_email'] = $customerEmail;
        }

        $session = self::client()->checkout->sessions->create($params);

        return ['id' => $session->id, 'url' => $session->url];
    }

    /**
     * Retrieves a Stripe Checkout Session by ID.
     *
     * The caller should check $session->payment_status === 'paid' before
     * treating the payment as confirmed.
     *
     * @throws \Stripe\Exception\ApiErrorException On Stripe API failure.
     */
    public static function retrieveCheckoutSession(string $sessionId): Session
    {
        return self::client()->checkout->sessions->retrieve($sessionId);
    }

    /**
     * Constructs and verifies a Stripe webhook Event from the raw request body
     * and the Stripe-Signature header value.
     *
     * @param string $payload   Raw php://input body (must not be parsed first).
     * @param string $sigHeader Value of the HTTP Stripe-Signature header.
     *
     * @return Event  The verified Stripe event object.
     *
     * @throws \Stripe\Exception\SignatureVerificationException On bad signature.
     * @throws \UnexpectedValueException On malformed payload.
     */
    public static function constructWebhookEvent(string $payload, string $sigHeader): Event
    {
        $secret = (string) Config::get('STRIPE_WEBHOOK_SECRET', '');
        return Webhook::constructEvent($payload, $sigHeader, $secret);
    }
}
