<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Models\FlowerColor;
use App\Models\FlowerType;
use App\Models\FlowerTypeColor;
use App\Models\Order;
use App\Models\PageView;
use App\Models\PaperColor;
use App\Models\StoreClosure;
use App\Services\MailService;
use App\Services\StripeService;
use App\Support\Analytics;
use App\Support\CartPricing;
use App\Support\CartSession;
use App\Support\CheckoutPricing;
use App\Support\Closures;
use App\Support\CustomerSource;
use App\Support\Destination;
use App\Support\Fulfillment;
use App\Support\LocalArea;
use App\Support\ShopOrderSnapshot;
use App\Support\StockCheck;
use App\Support\StripeLineItems;
use DateTimeImmutable;

/**
 * Handles the shop-cart checkout: review, Stripe pay-now, and success.
 *
 * Routes served:
 *   GET  /checkout         — review cart, enter sender + card message + delivery address.
 *   POST /checkout         — persist the order and redirect to Stripe Checkout.
 *   GET  /checkout/success — verify payment, mark the order paid, clear the cart.
 *
 * Delivery fee is computed server-side via the Haversine distance from the
 * business location to the customer-selected address (latitude/longitude
 * captured client-side by Google Places, mirroring the order form). Sales tax
 * applies to the merchandise subtotal only; totals come from
 * {@see \App\Support\CheckoutPricing::compute()}.
 *
 * @see \App\Services\StripeService::createCartCheckoutSession()
 * @see \App\Support\StripeLineItems::fromCart()
 * @see \App\Models\Order
 */
final class CheckoutController extends BaseController
{
    /** Maximum delivery radius in miles (mirrors the order form). */
    private const MAX_DELIVERY_MILES = 30.0;

    // -------------------------------------------------------------------------
    // GET /checkout
    // -------------------------------------------------------------------------

    /**
     * Render the checkout review page.
     *
     * Redirects an empty cart back to /{lang}/cart. Pre-estimates the delivery
     * fee when the session destination carries a venue address with cached
     * coordinates; otherwise the fee is computed live as the customer selects an
     * address. Sender contact, card message, and the address are collected here.
     *
     * Also performs a warn-only stock check: passes `outOfStockColors` (a list of
     * localized "{color} {type}" labels for chosen colors whose tracked stock
     * can't cover the cart) and `hasStockWarning` to the view. This only surfaces
     * a notice — it never blocks the order or constrains the date picker.
     *
     * Also passes `closedDates` (every store-closed day in the next 365 days,
     * for the client-side Alpine guard in the view) and `closedLabel` (the
     * same closures formatted as a human-readable list for the notice). The
     * server remains authoritative — see {@see submit()} — this is UX only.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (none).
     *
     * @return Response Rendered checkout page, or a redirect to the cart.
     *
     * @example
     *   (new CheckoutController())->view($request, []);
     */
    public function view(Request $request, array $params = []): Response
    {
        $lang  = Lang::current();
        $items = CartSession::items();

        if ($items === []) {
            return $this->redirect('/' . $lang . '/cart');
        }

        $subtotal    = CartPricing::subtotal($items);
        $taxRate     = (float) Config::get('BUSINESS_SALES_TAX_RATE', 0);
        $destination = Destination::get();

        // Pre-estimate the fee when the venue address has cached coordinates.
        $estimatedFee = null;
        if (!empty($destination['venue_address'])) {
            $coords = LocalArea::coords();
            $venue  = $coords[$destination['venue_address']] ?? null;
            if ($venue !== null) {
                $miles = LocalArea::haversineMiles(
                    (float) Config::get('BUSINESS_LAT', 0),
                    (float) Config::get('BUSINESS_LNG', 0),
                    (float) $venue['lat'],
                    (float) $venue['lng'],
                );
                $estimatedFee = LocalArea::deliveryFee(
                    $miles,
                    (float) Config::get('BUSINESS_DELIVERY_BASE_MILES', 5),
                    (float) Config::get('BUSINESS_DELIVERY_BASE_FEE', 10),
                    (float) Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1),
                );
            }
        }

        $cutoff = (string) Config::get('BUSINESS_SAMEDAY_CUTOFF', '13:00');
        $now    = new DateTimeImmutable('now');

        // Store closures: closedDatesBetween() returns [] for windows over
        // 366 days, so +365 (not +366 or more) is deliberate — do not widen
        // this. Used to warn the customer and drive the client-side guard in
        // the view; submit() re-checks authoritatively via Closures::isClosed().
        $closures    = StoreClosure::upcoming($now->format('Y-m-d'));
        $closedDates = Closures::closedDatesBetween(
            $now->format('Y-m-d'),
            $now->modify('+365 days')->format('Y-m-d'),
            $closures,
        );
        ['months' => $closureMonths] = $this->closureStrings($lang);
        $closedLabel = Closures::formatList($closures, $closureMonths);

        // Warn-only stock check: gather localized labels for chosen colors whose
        // tracked stock can't cover cart demand. Never blocks the order.
        $stockMap     = FlowerTypeColor::stockMap();
        $insufficient = StockCheck::insufficientColors($items, $stockMap);

        $nameOf = static function (array $rows) use ($lang): array {
            $map = [];
            foreach ($rows as $row) {
                $map[(int) $row['id']] = $row['name_' . $lang] ?? $row['name_en'] ?? '';
            }
            return $map;
        };
        $typeNames  = $nameOf(FlowerType::allActive());
        $colorNames = $nameOf(FlowerColor::allActive());

        $outOfStockColors = [];
        foreach ($insufficient as $pair) {
            $colorName = (string) ($colorNames[$pair['color_id']] ?? '');
            $typeName  = (string) ($typeNames[$pair['flower_type_id']] ?? '');
            $label     = trim($colorName . ' ' . $typeName);
            if ($label !== '' && !in_array($label, $outOfStockColors, true)) {
                $outOfStockColors[] = $label;
            }
        }

        $html = $this->render('public/checkout', [
            'items'        => $items,
            'subtotal'     => $subtotal,
            'taxRate'      => $taxRate,
            'destination'  => $destination,
            'estimatedFee' => $estimatedFee,
            'lang'         => $lang,
            'csrfToken'    => $request->csrfToken(),
            'earliestDate' => Fulfillment::earliestDate($now, $cutoff),
            'samedayCutoff' => $cutoff,
            'pickupAddress' => (string) Config::get('BUSINESS_ADDRESS', ''),
            'outOfStockColors' => $outOfStockColors,
            'hasStockWarning'  => $outOfStockColors !== [],
            'closedDates'  => $closedDates,
            'closedLabel'  => $closedLabel,
            'analyticsEvents' => [Analytics::beginCheckout($items, $subtotal)],
            'pageTitle'    => __t('checkout.heading'),
            'metaDesc'     => '',
        ]);

        return Response::html($html);
    }

    // -------------------------------------------------------------------------
    // POST /checkout
    // -------------------------------------------------------------------------

    /**
     * Validate the checkout form, persist the order, and redirect to Stripe.
     *
     * Recomputes the delivery fee server-side from the posted latitude/longitude
     * (never trusting a client-sent fee), rejects addresses beyond the delivery
     * radius, snapshots the cart into items_json with resolved names, creates the
     * order as unpaid, and starts a Stripe Checkout Session. The customer's
     * source is resolved from the session's UTM attribution (falling back to
     * 'shop_checkout'), and the order records the session token for later
     * webhook-side conversion tracking.
     *
     * Also hard-rejects any requested date that falls inside a store closure
     * ({@see \App\Support\Closures::isClosed()}), for both delivery and
     * pickup — a closure means nobody is there to fulfill either. The check
     * runs immediately after {@see \App\Support\Fulfillment::validate()} and
     * before anything is persisted or Stripe is contacted, so a closed date
     * never creates an order row or a Stripe session.
     *
     * @param Request              $request HTTP request with the checkout form body.
     * @param array<string, mixed> $params  Route parameters (none).
     *
     * @return Response Redirect to Stripe's hosted page, or back to checkout/cart on error.
     *
     * @example
     *   (new CheckoutController())->submit($request, []);
     */
    public function submit(Request $request, array $params = []): Response
    {
        $lang = Lang::current();

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/' . $lang . '/checkout');
        }

        $items = CartSession::items();
        if ($items === []) {
            return $this->redirect('/' . $lang . '/cart');
        }

        $name         = trim((string) $request->post('name', ''));
        $email        = trim((string) $request->post('email', ''));
        $phone        = trim((string) $request->post('phone', ''));
        $cardMessage  = trim((string) $request->post('card_message', ''));
        $address      = trim((string) $request->post('delivery_address', ''));
        $latRaw       = $request->post('latitude', '');
        $lngRaw       = $request->post('longitude', '');

        $fulfillType  = $request->post('delivery_type', 'delivery') === 'pickup' ? 'pickup' : 'delivery';
        $fulfillDate  = trim((string) $request->post('fulfill_date', ''));
        $fulfillTime  = trim((string) $request->post('fulfill_time', ''));

        if ($name === '' || $email === '') {
            $this->setFlash('error', 'Please provide your name and email.');
            return $this->redirect('/' . $lang . '/checkout');
        }

        // Validate the requested fulfillment date/time (no past dates; same-day
        // only before the cutoff). Pure logic in App\Support\Fulfillment.
        $cutoff = (string) Config::get('BUSINESS_SAMEDAY_CUTOFF', '13:00');
        $when   = new DateTimeImmutable('now');
        $scheduleErrors = Fulfillment::validate($fulfillType, $fulfillDate, $fulfillTime, $when, $cutoff);
        if ($scheduleErrors !== []) {
            $this->setFlash('error', $scheduleErrors[0]);
            return $this->redirect('/' . $lang . '/checkout');
        }

        // Hard reject: a store closure blocks both delivery and pickup, so
        // this does not branch on $fulfillType. Nothing is persisted and
        // Stripe is never contacted past this point.
        $closures = StoreClosure::upcoming($when->format('Y-m-d'));
        if (Closures::isClosed($fulfillDate, $closures)) {
            ['months' => $months, 'strings' => $strings] = $this->closureStrings($lang);
            $this->setFlash('error', Closures::rejectionMessage($fulfillDate, $closures, $strings, $months));
            return $this->redirect('/' . $lang . '/checkout');
        }

        $fulfillAt = Fulfillment::parse($fulfillDate, $fulfillTime)?->format('Y-m-d H:i:s');

        // Delivery needs a geocoded in-range address + a computed fee; pickup
        // needs neither (the studio address is fixed) and carries no fee.
        $deliveryFee = 0.0;
        if ($fulfillType === 'delivery') {
            if ($address === '' || $latRaw === '' || $lngRaw === '') {
                $this->setFlash('error', 'Please select your delivery address from the suggestions.');
                return $this->redirect('/' . $lang . '/checkout');
            }

            // Recompute the delivery fee server-side from the selected coordinates.
            $miles = LocalArea::haversineMiles(
                (float) Config::get('BUSINESS_LAT', 0),
                (float) Config::get('BUSINESS_LNG', 0),
                (float) $latRaw,
                (float) $lngRaw,
            );

            if ($miles > self::MAX_DELIVERY_MILES) {
                $this->setFlash('error', __t('order.delivery_outside_range'));
                return $this->redirect('/' . $lang . '/checkout');
            }

            $deliveryFee = LocalArea::deliveryFee(
                $miles,
                (float) Config::get('BUSINESS_DELIVERY_BASE_MILES', 5),
                (float) Config::get('BUSINESS_DELIVERY_BASE_FEE', 10),
                (float) Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1),
            );
        } else {
            // Pickup: no delivery address is stored.
            $address = '';
        }

        $subtotal = CartPricing::subtotal($items);
        $totals   = CheckoutPricing::compute(
            $subtotal,
            $deliveryFee,
            (float) Config::get('BUSINESS_SALES_TAX_RATE', 0),
        );

        // Snapshot the cart with resolved names for a self-contained order record.
        $nameOf = static function (array $rows) use ($lang): array {
            $map = [];
            foreach ($rows as $row) {
                $map[(int) $row['id']] = $row['name_' . $lang] ?? $row['name_en'] ?? '';
            }
            return $map;
        };
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            $items,
            $nameOf(FlowerType::allActive()),
            $nameOf(FlowerColor::allActive()),
            $nameOf(PaperColor::allActive()),
            $lang,
        );

        $destination = Destination::get();

        $customerId = Customer::upsert([
            'name'           => $name,
            'email'          => $email,
            'phone'          => $phone,
            'source'         => CustomerSource::resolve($_SESSION['utm'] ?? [], 'shop_checkout'),
            'opted_in_email' => 0,
            'opted_in_sms'   => 0,
        ]);

        $orderId = Order::createShopOrder([
            'customer_id'         => $customerId,
            'delivery_type'       => $fulfillType,
            'delivery_address'    => $address !== '' ? $address : null,
            'delivery_fee'        => $totals['delivery_fee'],
            'fulfill_at'          => $fulfillAt,
            'items_json'          => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'card_message'        => $cardMessage !== '' ? $cardMessage : null,
            'delivery_venue_name' => $fulfillType === 'delivery' ? ($destination['venue_name'] ?? null) : null,
            'occasion_type'       => $destination['occasion'] ?? null,
            'subtotal'            => $totals['subtotal'],
            'tax_amount'          => $totals['tax_amount'],
            'total'               => $totals['total'],
            'session_token'       => session_id() ?: null,
        ]);

        if (!StripeService::isConfigured()) {
            $this->setFlash('error', 'Card payment is not available right now. Please contact us.');
            return $this->redirect('/' . $lang . '/cart');
        }

        try {
            $lineItems = StripeLineItems::fromCart(
                $items,
                $totals['delivery_fee'],
                $totals['tax_amount'],
                (string) Config::get('STRIPE_TAX_RATE_ID', ''),
            );
            $session   = StripeService::createCartCheckoutSession($orderId, $lineItems, $email, [
                'delivery_type' => $fulfillType,
                'fulfill_at'    => $fulfillAt ?? '',
            ]);
            Order::setStripeCheckoutSession($orderId, $session['id']);
            // Remember this order so the success page fires `purchase` exactly once.
            $_SESSION['pending_purchase_order_id'] = $orderId;
            return $this->redirect($session['url']);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('[CheckoutController] Stripe API error: ' . $e->getMessage());
            $this->setFlash('error', 'Payment service error. Please try again.');
            return $this->redirect('/' . $lang . '/cart');
        } catch (\Throwable $e) {
            $detail = sprintf(
                '[CheckoutController] Unexpected error: %s (%s:%d)' . PHP_EOL . '%s',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            );
            error_log($detail);
            @file_put_contents(__DIR__ . '/../../logs/php_errors.log', '[' . date('c') . '] ' . $detail . PHP_EOL, FILE_APPEND);
            $this->setFlash('error', 'An unexpected error occurred. Please try again.');
            return $this->redirect('/' . $lang . '/cart');
        }
    }

    // -------------------------------------------------------------------------
    // GET /checkout/success
    // -------------------------------------------------------------------------

    /**
     * Verify the Stripe payment, mark the order paid, and clear the cart.
     *
     * Idempotent: when the order is already paid the cart/destination are simply
     * cleared and the thank-you page is shown without re-notifying. The owner is
     * notified once, on the first transition to paid (the webhook also confirms
     * the order as a backstop).
     *
     * On the first transition to paid, the order row is re-read after
     * {@see \App\Models\Order::markPaid()} so {@see renderSuccess()} receives the
     * post-payment snapshot (payment_status = 'paid', etc.) rather than the stale
     * row fetched before markPaid() ran — otherwise the customer's first render of
     * the receipt would be missing the Total block.
     *
     * @param Request              $request HTTP request with ?session_id=.
     * @param array<string, mixed> $params  Route parameters (none).
     *
     * @return Response Thank-you page, or a redirect to the cart on failure.
     *
     * @example
     *   (new CheckoutController())->success($request, []);
     */
    public function success(Request $request, array $params = []): Response
    {
        $lang      = Lang::current();
        $sessionId = (string) $request->query('session_id', '');

        if ($sessionId === '') {
            return $this->redirect('/' . $lang . '/cart');
        }

        $order = Order::findByStripeSessionId($sessionId);
        if ($order === null) {
            return $this->redirect('/' . $lang . '/cart');
        }

        // Already paid — clear and show thank-you without re-processing.
        if (($order['payment_status'] ?? '') === 'paid') {
            CartSession::clear();
            Destination::clear();
            return $this->renderSuccess($order, $lang, true);
        }

        $paid = false;
        try {
            $session = StripeService::retrieveCheckoutSession($sessionId);

            $metaOrderId = (int) ($session->metadata->shop_order_id ?? 0);
            if ($metaOrderId === (int) $order['id'] && ($session->payment_status ?? '') === 'paid') {
                $paymentIntentId = is_string($session->payment_intent)
                    ? $session->payment_intent
                    : (string) ($session->payment_intent->id ?? '');

                Order::markPaid((int) $order['id'], $paymentIntentId);
                $this->notifyOwner($order);

                // Re-read so renderSuccess() gets the post-markPaid row (payment_status
                // = 'paid' and any other fields markPaid() touched) instead of the stale
                // pre-payment snapshot fetched above. Fall back to the stale row if the
                // re-read somehow comes back empty, so the receipt still renders.
                $order = Order::findByStripeSessionId($sessionId) ?? $order;

                CartSession::clear();
                Destination::clear();
                $paid = true;
            }
        } catch (\Throwable $e) {
            $detail = sprintf(
                '[CheckoutController] Error verifying session %s: %s (%s:%d)',
                $sessionId,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            );
            error_log($detail);
            @file_put_contents(__DIR__ . '/../../logs/php_errors.log', '[' . date('c') . '] ' . $detail . PHP_EOL, FILE_APPEND);
        }

        return $this->renderSuccess($order, $lang, $paid);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Render the order thank-you page.
     *
     * Fires the GA4 `purchase` / Pixel `Purchase` event exactly once: only when
     * the order is paid AND its id matches the `pending_purchase_order_id` set at
     * submit time, then clears that flag. This avoids a double count on refresh
     * and when the Stripe webhook confirmed the order before this redirect. The
     * same fire-once block also marks the visitor's ad session as converted.
     *
     * @param array<string, mixed> $order The order row.
     * @param string               $lang  Active locale.
     * @param bool                 $paid  Whether the order is confirmed paid.
     *
     * @return Response Rendered HTML.
     */
    private function renderSuccess(array $order, string $lang, bool $paid = false): Response
    {
        $events  = [];
        $pending = (int) ($_SESSION['pending_purchase_order_id'] ?? 0);
        if ($paid && $pending !== 0 && $pending === (int) ($order['id'] ?? 0)) {
            $decoded = json_decode((string) ($order['items_json'] ?? ''), true);
            $items   = is_array($decoded) ? $decoded : [];
            $events[] = Analytics::purchase($order, $items);
            // Marks the visitor's ad session as converted; fire-once semantics
            // shared with the analytics event above.
            PageView::markConversion(session_id(), 'order');
            unset($_SESSION['pending_purchase_order_id']);
        }

        return Response::html(
            $this->render('public/checkout-success', [
                'order'           => $order,
                'lang'            => $lang,
                'analyticsEvents' => $events,
                'pageTitle'       => __t('checkout.success_heading'),
                'metaDesc'        => '',
            ])
        );
    }

    /**
     * Email the business owner that a shop order has been paid.
     *
     * Failures are logged and swallowed so a mail misconfiguration never blocks
     * the customer's success page.
     *
     * @param array<string, mixed> $order The paid order row (with customer_name).
     *
     * @return void
     */
    private function notifyOwner(array $order): void
    {
        $to = (string) Config::get('BUSINESS_EMAIL', '');
        if ($to === '' || !MailService::canSend()) {
            return;
        }

        $name  = (string) ($order['customer_name'] ?? 'Customer');
        $total = '$' . number_format((float) ($order['total'] ?? 0), 2);
        $venue = (string) ($order['delivery_venue_name'] ?? '');
        $addr  = (string) ($order['delivery_address'] ?? '');
        $type  = ($order['delivery_type'] ?? 'delivery') === 'pickup' ? 'Pickup' : 'Delivery';

        // "Sat Jun 20, 2026 at 2:00 PM" from the stored 'Y-m-d H:i:s', when present.
        $fulfillRaw = (string) ($order['fulfill_at'] ?? '');
        $fulfillAt  = '';
        if ($fulfillRaw !== '') {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $fulfillRaw);
            $fulfillAt = $dt !== false ? $dt->format('D M j, Y \a\t g:i A') : $fulfillRaw;
        }
        $whenLabel = $fulfillAt !== '' ? $type . ' — ' . $fulfillAt : $type;

        $html = MailService::buildHtml(
            '<h2 style="margin:0 0 1rem;font-size:1.2rem">New Paid Order 🌸</h2>'
            . '<table style="border-collapse:collapse;width:100%">'
            . $this->mailRow('Customer', $name)
            . $this->mailRow('Total Paid', $total)
            . $this->mailRow('Fulfillment', $whenLabel)
            . $this->mailRow('Card Message', (string) ($order['card_message'] ?? ''))
            . $this->mailRow($type === 'Pickup' ? 'Pickup' : 'Deliver To',
                $type === 'Pickup'
                    ? 'Customer pickup at the studio'
                    : ($venue !== '' ? $venue . ' — ' . $addr : $addr))
            . '</table>'
            . '<p style="margin:1.5rem 0 0;font-size:0.85rem;color:#999">'
            . 'View order #' . (int) $order['id'] . ' in the '
            . '<a href="' . htmlspecialchars((string) Config::get('APP_URL', '')) . '/admin">admin panel</a>.'
            . '</p>'
        );

        $result = MailService::send($to, '', 'New Paid Order - ' . ($name ?: 'Customer'), $html);
        if (!$result['success']) {
            error_log('[CheckoutController] Owner notification failed: ' . ($result['error'] ?? 'unknown'));
        }
    }

    /**
     * Build one label/value table row for the owner notification email.
     *
     * @param string $label Row label.
     * @param string $value Row value; the row is omitted when empty.
     *
     * @return string HTML table row, or empty string when $value is blank.
     */
    private function mailRow(string $label, string $value): string
    {
        if ($value === '') {
            return '';
        }
        return '<tr>'
            . '<td style="padding:6px 12px;font-weight:600;color:#555;white-space:nowrap">' . htmlspecialchars($label) . '</td>'
            . '<td style="padding:6px 12px;color:#1a1a1a">' . nl2br(htmlspecialchars($value)) . '</td>'
            . '</tr>';
    }

    /**
     * Store a flash message in the session for the next page render.
     *
     * @param 'success'|'error' $type    Severity level.
     * @param string            $message Human-readable message.
     *
     * @return void
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}
