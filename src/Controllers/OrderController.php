<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\Customer;
use App\Models\Order;
use App\Services\GeocodingService;
use App\Services\MailService;

/**
 * Handles the public custom bouquet request form.
 *
 * GET  /order               — renders the order form.
 * POST /order               — validates and persists the submission, returns JSON.
 * POST /order/delivery-fee  — geocodes an address and returns the delivery fee as JSON.
 *
 * @see \App\Models\Customer
 * @see \App\Models\Order
 */
final class OrderController extends BaseController
{
    /**
     * Renders the custom bouquet request form.
     *
     * Generates a CSRF token and optionally pre-fills the arrangement hint
     * from the `arrangement` query parameter so product cards that link here
     * can pre-populate the occasion field.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response Rendered HTML response.
     *
     * @example
     *   // GET /order?arrangement=Eternal+Roses
     */
    public function form(Request $request, array $params = []): Response
    {
        $lang             = Lang::current();
        $csrfToken        = $request->csrfToken();
        $arrangementHint  = $request->query('arrangement', '');
        $pageTitle        = (string) Settings::get('order_page_title_' . $lang, 'Request a Custom Bouquet');

        $html = $this->render('public/order', [
            'csrfToken'       => $csrfToken,
            'arrangementHint' => $arrangementHint,
            'lang'            => $lang,
            'pageTitle'       => $pageTitle,
        ]);

        return Response::html($html);
    }

    /**
     * Processes the custom bouquet order form submission.
     *
     * Validates CSRF, sanitises input, requires at least one of email or phone,
     * upserts the customer, creates the order, and returns a JSON response.
     * The client-side Alpine.js component reads `success` or `error` from the
     * JSON body.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response JSON response with `success: true` or `error: string`.
     *
     * @example
     *   // POST /order — returns {"success":true,"message":"Thank you! …"}
     *   // POST /order — returns {"success":false,"error":"…"} with 422 on invalid input
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

        // Upsert customer.
        $customerId = Customer::upsert([
            'name'           => $name,
            'email'          => $email,
            'phone'          => $phone,
            'source'         => 'order_form',
            'opted_in_email' => 0,
            'opted_in_sms'   => 0,
        ]);

        // Create the order.
        Order::create([
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
        ]);

        $this->sendOrderNotification(
            $name, $email, $phone, $eventDate,
            $occasion, $arrangementStyle, $colorPreferences, $budgetRange, $notes,
            $deliveryType, $deliveryAddress, $deliveryFee
        );

        return Response::json([
            'success' => true,
            'message' => __t('order.success'),
        ]);
    }

    /**
     * Calculates the delivery fee for a given address.
     *
     * Geocodes the address via Nominatim, computes the straight-line distance
     * from the business pickup location, and applies the configured fee schedule.
     * Returns JSON: {success:true, distance:float, fee:float} or {success:false, error:string}
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response JSON response.
     *
     * @example
     *   // POST /order/delivery-fee — returns {"success":true,"distance":3.2,"fee":10.00}
     *   // POST /order/delivery-fee — returns {"success":false,"error":"Address not found…"}
     */
    public function calculateDelivery(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(['success' => false, 'error' => 'Invalid security token.'], 422);
        }

        $address = trim((string) $request->post('address', ''));

        if ($address === '') {
            return Response::json(['success' => false, 'error' => 'Please enter a delivery address.']);
        }

        $coords = GeocodingService::geocode($address);

        if ($coords === null) {
            return Response::json(['success' => false, 'error' => 'Address not found. Please check and try again.']);
        }

        $businessLat = (float) Config::get('BUSINESS_LAT', '36.0814');
        $businessLng = (float) Config::get('BUSINESS_LNG', '-95.9987');

        $distance = GeocodingService::haversineDistance(
            $businessLat,
            $businessLng,
            $coords['lat'],
            $coords['lng']
        );

        if ($distance > 30) {
            return Response::json(['success' => false, 'error' => 'Sorry, we only deliver within 30 miles of our location.']);
        }

        $baseMiles = (float) Config::get('BUSINESS_DELIVERY_BASE_MILES', 5);
        $baseFee   = (float) Config::get('BUSINESS_DELIVERY_BASE_FEE', 10);
        $perMile   = (float) Config::get('BUSINESS_DELIVERY_PER_MILE_FEE', 1);

        $fee = $baseFee + max(0, $distance - $baseMiles) * $perMile;

        return Response::json([
            'success'  => true,
            'distance' => round($distance, 1),
            'fee'      => round($fee, 2),
        ]);
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
    ): void {
        $to = (string) Config::get('BUSINESS_EMAIL', '');
        if ($to === '' || !MailService::isConfigured()) {
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

        $html = MailService::buildHtml(
            '<h2 style="margin:0 0 1rem;font-size:1.2rem">New Bouquet Request</h2>'
            . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
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
