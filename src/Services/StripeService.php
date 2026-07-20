<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Support\StripeLineItems;
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
     * receipt mirrors the quote exactly. When tax_amount > 0.00, the reusable
     * Stripe Tax Rate object named by STRIPE_TAX_RATE_ID is attached to every
     * merchandise line item so Stripe itself computes and *classifies* the
     * amount as sales tax — making it appear in Stripe's Tax reports. This
     * mirrors the quote's tax base, which taxes merchandise only — delivery is
     * never taxed (see {@see \App\Support\QuotePricing}). If STRIPE_TAX_RATE_ID
     * is not configured, tax falls back to a plain "Sales Tax" line item so
     * tax is still collected (though not reported as tax). When
     * $deliveryFee > 0.00, a separate untaxed "Delivery" line is appended.
     *
     * Sets metadata['quote_token'] on the session so the webhook handler can
     * look up the quote without trusting URL parameters.
     *
     * @param int    $quoteId       Quote primary key (stored in metadata).
     * @param string $token         Quote opaque token (used in return URLs and metadata).
     * @param array<int, array{description: string, qty: int, unit_price: float}> $items
     *        Decoded quote items from QuoteService::decodeItems().
     * @param float  $taxAmount     Pre-computed tax in dollars from quote['tax_amount'];
     *                              used only as the signal for whether tax applies
     *                              (> 0.00). When a tax rate object is configured,
     *                              Stripe recomputes the exact cents from the rate,
     *                              which may differ by a cent from this figure.
     * @param string $customerEmail Pre-fills the email field on Stripe's hosted page.
     * @param float  $deliveryFee   Delivery fee in dollars from quote['delivery_fee'];
     *                              adds a (never-taxed) Delivery line when > 0.00.
     *
     * @return array{id: string, url: string}
     *
     * @throws \Stripe\Exception\ApiErrorException On Stripe API failure.
     *
     * @see \App\Support\StripeLineItems::fromQuoteItems()  Pure line-item builder (unit-tested).
     *
     * @example
     *   $result = StripeService::createQuoteCheckoutSession(
     *       $quote['id'], $quote['token'], $items, (float)$quote['tax_amount'], $email,
     *       (float)$quote['delivery_fee'],
     *   );
     *   header('Location: ' . $result['url']);
     */
    public static function createQuoteCheckoutSession(
        int    $quoteId,
        string $token,
        array  $items,
        float  $taxAmount = 0.00,
        string $customerEmail = '',
        float  $deliveryFee = 0.00,
    ): array {
        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');

        $lineItems = StripeLineItems::fromQuoteItems(
            $items,
            $taxAmount,
            (string) Config::get('STRIPE_TAX_RATE_ID', ''),
            $deliveryFee,
        );

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
     * Creates a Stripe Checkout Session for a shop-cart order.
     *
     * The line items array must be pre-built by {@see \App\Support\StripeLineItems::fromCart()}
     * so this method stays thin and does not re-implement pricing logic.
     *
     * Sets metadata['shop_order_id'] so the webhook handler can distinguish
     * shop-cart sessions from quote sessions and look up the order row without
     * trusting URL parameters.
     *
     * @param int     $orderId       Shop order primary key (stored in metadata).
     * @param array[] $lineItems     Pre-built line_items array from StripeLineItems::fromCart().
     * @param string  $customerEmail Pre-fills the email field on Stripe's hosted page;
     *                               omitted from the request when empty.
     * @param array<string, scalar> $extraMetadata Additional key/value pairs merged
     *                               into session metadata (e.g. delivery_type,
     *                               fulfill_at). shop_order_id always takes precedence.
     *
     * @return array{id: string, url: string}
     *
     * @throws \Stripe\Exception\ApiErrorException On Stripe API failure.
     *
     * @see \App\Support\StripeLineItems::fromCart()
     *
     * @example
     *   $result = StripeService::createCartCheckoutSession($orderId, $lineItems, $email, [
     *       'delivery_type' => 'pickup', 'fulfill_at' => '2026-06-20 14:00:00',
     *   ]);
     *   header('Location: ' . $result['url']);
     */
    public static function createCartCheckoutSession(
        int    $orderId,
        array  $lineItems,
        string $customerEmail = '',
        array  $extraMetadata = [],
    ): array {
        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');

        $params = [
            'mode'        => 'payment',
            'line_items'  => $lineItems,
            'success_url' => $appUrl . '/checkout/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $appUrl . '/cart',
            'metadata'    => array_merge($extraMetadata, [
                'shop_order_id' => $orderId,
            ]),
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
