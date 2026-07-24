<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\Customer;
use App\Models\Addon;
use App\Models\Order;
use App\Models\PageView;
use App\Models\Quote;
use App\Models\StoreClosure;
use App\Services\MailService;
use App\Support\Closures;
use App\Support\CustomerSource;
use App\Support\QuoteDraft;
use DateTimeImmutable;

/**
 * Handles the public custom bouquet request form.
 *
 * GET  /order — renders the order form.
 * POST /order — validates and persists the submission, returns JSON.
 *
 * Delivery fee is calculated client-side via the Google Places Autocomplete widget.
 *
 * @see \App\Models\Customer
 * @see \App\Models\Order
 */
final class OrderController extends BaseController
{
    /**
     * Renders the custom bouquet request form.
     *
     * Generates a CSRF token and optionally pre-fills fields from query
     * parameters so product cards on other pages can link here with context:
     *
     *   ?arrangement= — pre-fills the arrangement_style field (legacy param,
     *                   kept for backwards compatibility with product cards).
     *   ?product=     — pre-fills the arrangement_style field; takes precedence
     *                   over ?arrangement= when both are present.
     *   ?occasion=    — pre-fills the occasion field with a human-readable label.
     *
     * Also feeds the view everything it needs to enforce store closures on
     * the client side: today's date (server-rendered, so the date picker's
     * `min` isn't computed from the browser's UTC clock — see
     * {@see Closures}'s class doc for why that matters) and the list of
     * closed dates over the next year, plus a human-readable label of the
     * underlying closure ranges for the notice banner.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response Rendered HTML response.
     *
     * @see \App\Models\Addon::allActive()
     * @see \App\Support\Closures::closedDatesBetween()
     * @see \App\Support\Closures::formatList()
     *
     * @example
     *   // GET /order?product=Eternal+Roses&occasion=Birthday
     *   // GET /order?arrangement=Eternal+Roses
     */
    public function form(Request $request, array $params = []): Response
    {
        $lang  = Lang::current();
        $addons = Addon::allActive();

        // ?product= takes precedence over legacy ?arrangement= when both present.
        $productHint      = $request->query('product', '');
        $arrangementHint  = $productHint !== ''
            ? $productHint
            : $request->query('arrangement', '');

        $occasionHint = $request->query('occasion', '');
        $pageTitle    = (string) Settings::get('order_page_title_' . $lang, 'Request a Custom Bouquet');

        $today   = (new DateTimeImmutable('now'))->format('Y-m-d');
        // closedDatesBetween() returns [] for windows over 366 days, so +365
        // (not +366 or more) is deliberate — do not widen this.
        $horizon = (new DateTimeImmutable('now'))->modify('+365 days')->format('Y-m-d');
        $closures = StoreClosure::upcoming($today);
        ['months' => $months] = $this->closureStrings($lang);

        $html = $this->render('public/order', [
            'csrfToken'       => $request->csrfToken(),
            'arrangementHint' => $arrangementHint,
            'occasionHint'    => $occasionHint,
            'lang'            => $lang,
            'pageTitle'       => $pageTitle,
            'addons'          => $addons,
            'todayYmd'        => $today,
            'closedDates'     => Closures::closedDatesBetween($today, $horizon, $closures),
            'closedLabel'     => Closures::formatList($closures, $months),
        ]);

        return Response::html($html);
    }

    /**
     * Processes the custom bouquet order form submission.
     *
     * Validates CSRF, sanitises input, requires at least one of email or phone,
     * rejects the request outright when the (optional) event_date falls on a
     * store closure, upserts the customer (source resolved from the session's
     * UTM attribution, falling back to 'order_form'), creates the order
     * (recording the session token and marking the visitor's ad session as
     * converted), and returns a JSON response. The closure check runs before
     * any record is created, so a rejected request leaves no customer, order,
     * draft quote, or owner notification behind. The client-side Alpine.js
     * component reads `success` or `error` from the JSON body.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response JSON response with `success: true` or `error: string`.
     *
     * @see \App\Support\Closures::isClosed()
     * @see \App\Support\Closures::rejectionMessage()
     *
     * @example
     *   // POST /order — returns {"success":true,"message":"Thank you! …"}
     *   // POST /order — returns {"success":false,"error":"…"} with 422 on invalid input
     *   //   or when event_date falls on a store closure
     */
    public function submit(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(['success' => false, 'error' => 'Invalid security token.'], 422);
        }

        // Sanitise all inputs.
        $name               = trim((string) $request->post('name', ''));
        $email              = trim((string) $request->post('email', ''));
        $phone              = trim((string) $request->post('phone', ''));
        $eventDate          = trim((string) $request->post('event_date', ''));
        $occasion           = trim((string) $request->post('occasion', ''));
        $arrangementStyle   = trim((string) $request->post('arrangement_style', ''));
        $colorPreferences   = trim((string) $request->post('color_preferences', ''));
        $budgetRange        = trim((string) $request->post('budget_range', ''));
        $notes              = trim((string) $request->post('notes', ''));
        $deliveryType       = in_array($request->post('delivery_type', 'pickup'), ['pickup', 'delivery'], true)
                              ? $request->post('delivery_type', 'pickup')
                              : 'pickup';
        $deliveryAddress    = trim((string) $request->post('delivery_address', ''));
        $deliveryFee        = $request->post('delivery_fee') !== null ? (float) $request->post('delivery_fee') : null;

        // Sanitise add-ons selection — the client sends form.addons as a JSON array
        // of {id, name_en, name_es} snapshots, already decoded by Request::jsonBody().
        $rawAddons = $request->post('addons', []);
        $addons    = [];
        if (is_array($rawAddons)) {
            foreach ($rawAddons as $item) {
                if (!is_array($item) || !isset($item['id'])) {
                    continue;
                }
                $addons[] = [
                    'id'      => (int) $item['id'],
                    'name_en' => strip_tags(trim((string) ($item['name_en'] ?? ''))),
                    'name_es' => (isset($item['name_es']) && $item['name_es'] !== '')
                                 ? strip_tags(trim((string) $item['name_es'])) : null,
                ];
            }
        }

        // Delivery address is required when delivery type is delivery.
        if ($deliveryType === 'delivery' && $deliveryAddress === '') {
            return Response::json(['success' => false, 'error' => 'Please enter your delivery address.'], 422);
        }

        // At least one contact method is required.
        if ($email === '' && $phone === '') {
            return Response::json(
                ['success' => false, 'error' => 'Please provide an email address or phone number.'],
                422
            );
        }

        // Reject requests for closed dates before any record is created.
        // event_date is optional on this form, so only enforce when supplied.
        if ($eventDate !== '') {
            $closures = StoreClosure::upcoming((new DateTimeImmutable('now'))->format('Y-m-d'));
            if (Closures::isClosed($eventDate, $closures)) {
                ['months' => $months, 'strings' => $strings] = $this->closureStrings(Lang::current());
                return Response::json([
                    'success' => false,
                    'error'   => Closures::rejectionMessage($eventDate, $closures, $strings, $months),
                ], 422);
            }
        }

        // Upsert customer.
        $customerId = Customer::upsert([
            'name'           => $name,
            'email'          => $email,
            'phone'          => $phone,
            'source'         => CustomerSource::resolve($_SESSION['utm'] ?? [], 'order_form'),
            'opted_in_email' => 0,
            'opted_in_sms'   => 0,
        ]);

        // Create the order.
        $orderId = Order::create([
            'customer_id'       => $customerId,
            'event_date'        => $eventDate !== '' ? $eventDate : null,
            'delivery_type'     => $deliveryType,
            'delivery_address'  => $deliveryType === 'delivery' ? $deliveryAddress : null,
            'delivery_fee'      => $deliveryType === 'delivery' ? $deliveryFee : null,
            'occasion'          => $occasion,
            'arrangement_style' => $arrangementStyle,
            'color_preferences' => $colorPreferences,
            'budget_range'      => $budgetRange,
            'notes'             => $notes,
            'addons'            => $addons !== [] ? json_encode($addons) : null,
            'session_token'     => session_id() ?: null,
        ]);

        // Mark the visitor's ad session as converted now that the order exists.
        PageView::markConversion(session_id(), 'order');

        // Pre-build a draft quote from the request so the owner can review and
        // send pricing in one click instead of re-keying everything.
        $quoteId = $this->createDraftQuoteForOrder(
            $orderId, $customerId,
            [
                'event_date'        => $eventDate !== '' ? $eventDate : null,
                'occasion'          => $occasion,
                'arrangement_style' => $arrangementStyle,
                'color_preferences' => $colorPreferences,
                'budget_range'      => $budgetRange,
                'notes'             => $notes,
                'delivery_type'     => $deliveryType,
                'delivery_address'  => $deliveryType === 'delivery' ? $deliveryAddress : '',
                'delivery_fee'      => $deliveryType === 'delivery' ? $deliveryFee : null,
            ],
            $addons,
        );

        $this->sendOrderNotification(
            $name, $email, $phone, $eventDate,
            $occasion, $arrangementStyle, $colorPreferences, $budgetRange, $notes,
            $deliveryType, $deliveryAddress, $deliveryFee,
            $addons, $quoteId
        );

        return Response::json([
            'success' => true,
            'message' => __t('order.success'),
        ]);
    }

    /**
     * Build and persist a draft quote pre-filled from a bouquet request.
     *
     * Resolves each selected add-on to its catalogue price via {@see Addon::find()},
     * maps the request into line items and notes through {@see QuoteDraft}, creates
     * the quote in `draft` status (the owner reviews/sends it), and links it back
     * to the order via {@see Order::setQuoteId()}. The bouquet line is seeded at
     * the midpoint of the stated budget range. $fields['delivery_fee'] is passed
     * straight through to {@see Quote::create()}'s separately-stated, untaxed
     * `delivery_fee` field (null/pickup collapses to 0.0) rather than folded into
     * the note text as a dollar figure.
     *
     * Best-effort: any failure is logged and swallowed so a quote-building problem
     * never blocks the customer's form submission (mirrors the notification email).
     *
     * @param int   $orderId    The order the quote responds to.
     * @param int   $customerId The customer the quote belongs to (0 when unknown).
     * @param array{event_date: ?string, occasion: string, arrangement_style: string,
     *              color_preferences: string, budget_range: string, notes: string,
     *              delivery_type: string, delivery_address: string,
     *              delivery_fee: ?float} $fields Sanitised request fields.
     * @param list<array{id: int, name_en: string, name_es: ?string}> $addons
     *        Add-on snapshots as submitted (price is looked up from the catalogue).
     *
     * @return int|null The new quote ID, or null when creation failed.
     */
    private function createDraftQuoteForOrder(
        int $orderId,
        int $customerId,
        array $fields,
        array $addons,
    ): ?int {
        try {
            $pricedAddons = [];
            foreach ($addons as $addon) {
                $row = Addon::find((int) $addon['id']);
                if ($row === null) {
                    continue;
                }
                $pricedAddons[] = [
                    'name'  => (string) ($addon['name_en'] ?? $row['name_en']),
                    'price' => (float) $row['price'],
                ];
            }

            $items = QuoteDraft::lineItems(
                [
                    'arrangement_style' => $fields['arrangement_style'],
                    'budget_range'      => $fields['budget_range'],
                ],
                $pricedAddons,
            );

            $notes = QuoteDraft::notes(
                $fields['occasion'],
                $fields['color_preferences'],
                $fields['notes'],
                $this->deliveryNoteLine(
                    $fields['delivery_type'],
                    $fields['delivery_address'],
                ),
            );

            $quoteId = Quote::create([
                'customer_id'  => $customerId > 0 ? $customerId : null,
                'event_date'   => $fields['event_date'],
                'items'        => $items,
                // Delivery is a separately-stated, untaxed field on the quote
                // (mirrors the shop-cart checkout path) — no longer folded into
                // notes as a dollar figure. Pickup orders carry null here.
                'delivery_fee' => $fields['delivery_fee'] ?? 0.0,
                'notes'        => $notes !== '' ? $notes : null,
            ]);

            Order::setQuoteId($orderId, $quoteId);

            return $quoteId;
        } catch (\Throwable $e) {
            error_log('[OrderController] Draft quote build failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Format a one-line delivery/pickup address summary for the draft quote notes.
     *
     * The delivery *fee* is no longer part of this line — it is passed straight
     * to {@see Quote::create()}'s `delivery_fee` field (a separately-stated,
     * untaxed amount), so this only records the address for the owner's
     * reference. Returns '' for pickup orders so the line is omitted entirely.
     *
     * @param string $deliveryType    'pickup' or 'delivery'.
     * @param string $deliveryAddress Destination address; empty for pickup.
     *
     * @return string e.g. 'Delivery: 123 Main St', or '' for pickup.
     */
    private function deliveryNoteLine(
        string $deliveryType,
        string $deliveryAddress,
    ): string {
        if ($deliveryType !== 'delivery') {
            return '';
        }

        return 'Delivery: ' . ($deliveryAddress !== '' ? $deliveryAddress : '(address pending)');
    }

    /**
     * Send a new bouquet request notification to the business owner.
     *
     * Fires after the order and customer records are persisted. Failures are
     * silently logged so a mail misconfiguration never breaks the form submit.
     *
     * @param string      $deliveryType    'pickup' or 'delivery'.
     * @param string      $deliveryAddress Customer delivery address; empty for pickup orders.
     * @param float|null  $deliveryFee     Calculated delivery fee; null for pickup orders.
     * @param array  $addons          Selected add-on snapshots; empty array when none were chosen.
     * @param int|null    $quoteId         ID of the draft quote pre-built from this
     *                                     request; null when none was created. When
     *                                     set, the email links straight to it.
     */
    private function sendOrderNotification(
        string $name,
        string $email,
        string $phone,
        string $eventDate,
        string $occasion,
        string $arrangementStyle,
        string $colorPreferences,
        string $budgetRange,
        string $notes,
        string $deliveryType = 'pickup',
        string $deliveryAddress = '',
        ?float $deliveryFee = null,
        array  $addons = [],
        ?int   $quoteId = null,
    ): void {
        $to = (string) Config::get('BUSINESS_EMAIL', '');
        if ($to === '' || !MailService::canSend()) {
            return;
        }

        $rows = '';
        foreach ([
            'Name'              => $name,
            'Email'             => $email,
            'Phone'             => $phone,
            'Event Date'        => $eventDate,
            'Delivery Type'     => ucfirst($deliveryType),
            'Occasion'          => $occasion,
            'Arrangement Style' => $arrangementStyle,
            'Color Preferences' => $colorPreferences,
            'Budget Range'      => $budgetRange,
            'Notes'             => $notes,
        ] as $label => $value) {
            if ($value === '') {
                continue;
            }
            $rows .= '<tr>'
                . '<td style="padding:6px 12px;font-weight:600;color:#555;white-space:nowrap">'
                . htmlspecialchars($label) . '</td>'
                . '<td style="padding:6px 12px;color:#1a1a1a">'
                . nl2br(htmlspecialchars($value)) . '</td>'
                . '</tr>';
        }

        if ($addons !== []) {
            $addonNames = implode(', ', array_map(fn ($a) => $a['name_en'], $addons));
            $rows .= '<tr>'
                . '<td style="padding:6px 12px;font-weight:600;color:#555;white-space:nowrap">Add-Ons</td>'
                . '<td style="padding:6px 12px;color:#1a1a1a">'
                . htmlspecialchars($addonNames) . '</td>'
                . '</tr>';
        }

        if ($deliveryType === 'delivery' && $deliveryAddress !== '') {
            $rows .= '<tr>'
                . '<td style="padding:6px 12px;font-weight:600;color:#555;white-space:nowrap">Delivery Address</td>'
                . '<td style="padding:6px 12px;color:#1a1a1a">' . nl2br(htmlspecialchars($deliveryAddress)) . '</td>'
                . '</tr>';
        }

        if ($deliveryType === 'delivery' && $deliveryFee !== null) {
            $rows .= '<tr>'
                . '<td style="padding:6px 12px;font-weight:600;color:#555;white-space:nowrap">Delivery Fee</td>'
                . '<td style="padding:6px 12px;color:#1a1a1a">$' . number_format($deliveryFee, 2) . '</td>'
                . '</tr>';
        }

        $quoteCallout = '';
        if ($quoteId !== null) {
            $quoteUrl = htmlspecialchars((string) Config::get('APP_URL', '') . '/admin/quotes/' . $quoteId);
            $quoteCallout =
                '<p style="margin:1.5rem 0 0;font-size:0.95rem">'
                . 'A draft quote (#' . (int) $quoteId . ') has been prepared from this request — '
                . '<a href="' . $quoteUrl . '" style="color:#B55AA0;font-weight:600">review &amp; send it</a>. '
                . 'Prices are estimates from the stated budget; adjust before sending.'
                . '</p>';
        }

        $html = MailService::buildHtml(
            '<h2 style="margin:0 0 1rem;font-size:1.2rem">New Bouquet Request</h2>'
            . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
            . $quoteCallout
            . '<p style="margin:1.5rem 0 0;font-size:0.9rem">Enjoyed our service? <a href="https://g.page/r/CXreQ_QPNWNOEBM/review" style="color:#B55AA0">Leave us a Google review</a> — it means the world to us! 🌸</p>'
            . '<p style="margin:1.5rem 0 0;font-size:0.85rem;color:#999">'
            . 'View all orders in the <a href="' . htmlspecialchars((string) Config::get('APP_URL', '')) . '/admin">admin panel</a>.'
            . '</p>'
        );

        $result = MailService::send($to, '', 'New Bouquet Request - ' . ($name ?: 'Anonymous'), $html);

        if (!$result['success']) {
            error_log('[OrderController] Notification email failed: ' . ($result['error'] ?? 'unknown'));
        }
    }
}
