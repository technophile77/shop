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

    /**
     * Lists every Charge on the account, normalized to plain arrays of
     * dollar amounts so {@see \App\Support\StripeReconciler} stays DB- and
     * SDK-free.
     *
     * Uses {@see \Stripe\Collection::autoPagingIterator()} rather than a bare
     * `limit`, which silently truncates at 100 results and would drop older
     * charges — for a reconciliation tool that is exactly the kind of silent
     * under-report this codebase refuses to produce.
     *
     * @param int $createdGte Unix timestamp; when > 0, only charges created
     *        on or after this instant are returned (passed as Stripe's
     *        `created[gte]` filter). 0 means no lower bound.
     *
     * @return list<array{id: string, created_utc: string, status: string,
     *              paid: bool, amount: float, amount_refunded: float,
     *              refunded: bool, disputed: bool, payment_intent: string,
     *              billing_name: string, description: string,
     *              metadata: array<string, string>}>
     *         One entry per charge, oldest-filter-bound through newest, with
     *         `amount`/`amount_refunded` converted from Stripe's integer
     *         cents to decimal dollars and `created_utc` formatted
     *         `'Y-m-d H:i:s'` in UTC.
     *
     * @throws \Stripe\Exception\ApiErrorException On Stripe API failure.
     *
     * @example
     *   $charges = StripeService::listCharges(strtotime('2026-05-01 00:00:00 UTC'));
     *   // [['id' => 'ch_123', 'created_utc' => '2026-07-24 00:13:55', 'status' => 'succeeded',
     *   //   'paid' => true, 'amount' => 107.67, 'amount_refunded' => 0.00, 'refunded' => false,
     *   //   'disputed' => false, 'payment_intent' => 'pi_123', 'billing_name' => 'Jane Doe',
     *   //   'description' => '', 'metadata' => []], ...]
     *
     * @see \App\Support\StripeReconciler Consumes this list to match charges to local quotes/orders.
     */
    public static function listCharges(int $createdGte = 0): array
    {
        $params = ['limit' => 100];
        if ($createdGte > 0) {
            $params['created'] = ['gte' => $createdGte];
        }

        $charges = [];
        foreach (self::client()->charges->all($params)->autoPagingIterator() as $charge) {
            $charges[] = [
                'id'              => $charge->id,
                'created_utc'     => gmdate('Y-m-d H:i:s', $charge->created),
                'status'          => $charge->status,
                'paid'            => (bool) $charge->paid,
                'amount'          => round($charge->amount / 100, 2),
                'amount_refunded' => round($charge->amount_refunded / 100, 2),
                'refunded'        => (bool) $charge->refunded,
                'disputed'        => (bool) $charge->disputed,
                'payment_intent'  => (string) ($charge->payment_intent ?? ''),
                'billing_name'    => (string) ($charge->billing_details?->name ?? ''),
                'description'     => (string) ($charge->description ?? ''),
                'metadata'        => $charge->metadata->toArray(),
            ];
        }

        return $charges;
    }

    /**
     * Lists every Checkout Session on the account, normalized to plain
     * arrays of dollar amounts so {@see \App\Support\StripeReconciler} stays
     * DB- and SDK-free.
     *
     * Uses {@see \Stripe\Collection::autoPagingIterator()} rather than a bare
     * `limit`, which silently truncates at 100 results and would drop older
     * sessions — the same reason {@see self::listCharges()} does.
     *
     * @param int $createdGte Unix timestamp; when > 0, only sessions created
     *        on or after this instant are returned (passed as Stripe's
     *        `created[gte]` filter). 0 means no lower bound.
     *
     * @return list<array{id: string, created_utc: string, status: string,
     *              payment_status: string, amount_total: float,
     *              amount_subtotal: float, amount_tax: float,
     *              payment_intent: string, metadata: array<string, string>,
     *              email: string}>
     *         One entry per Checkout Session, with every money field
     *         converted from Stripe's integer cents to decimal dollars and
     *         `created_utc` formatted `'Y-m-d H:i:s'` in UTC.
     *
     * @throws \Stripe\Exception\ApiErrorException On Stripe API failure.
     *
     * @example
     *   $sessions = StripeService::listCheckoutSessions(strtotime('2026-05-01 00:00:00 UTC'));
     *   // [['id' => 'cs_123', 'created_utc' => '2026-06-06 20:37:20', 'status' => 'complete',
     *   //   'payment_status' => 'paid', 'amount_total' => 189.90, 'amount_subtotal' => 175.00,
     *   //   'amount_tax' => 14.90, 'payment_intent' => 'pi_456', 'metadata' => [],
     *   //   'email' => 'jane@example.com'], ...]
     *
     * @see \App\Support\StripeReconciler Matches quote 5 to its charge via this list (rule 2, 'session').
     */
    public static function listCheckoutSessions(int $createdGte = 0): array
    {
        $params = ['limit' => 100];
        if ($createdGte > 0) {
            $params['created'] = ['gte' => $createdGte];
        }

        $sessions = [];
        foreach (self::client()->checkout->sessions->all($params)->autoPagingIterator() as $session) {
            $sessions[] = [
                'id'               => $session->id,
                'created_utc'      => gmdate('Y-m-d H:i:s', $session->created),
                'status'           => (string) ($session->status ?? ''),
                'payment_status'   => (string) $session->payment_status,
                'amount_total'     => round(($session->amount_total ?? 0) / 100, 2),
                'amount_subtotal'  => round(($session->amount_subtotal ?? 0) / 100, 2),
                'amount_tax'       => round(($session->total_details?->amount_tax ?? 0) / 100, 2),
                'payment_intent'   => (string) ($session->payment_intent ?? ''),
                'metadata'         => $session->metadata->toArray(),
                'email'            => (string) ($session->customer_email ?? ($session->customer_details?->email ?? '')),
            ];
        }

        return $sessions;
    }
}
