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
use App\Services\MailService;

/**
 * Handles the public custom bouquet request form.
 *
 * GET  /order  — renders the order form.
 * POST /order  — validates and persists the submission, returns JSON.
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
            'delivery_type'     => 'pickup',
            'occasion'          => $occasion,
            'arrangement_style' => $arrangementStyle,
            'color_preferences' => $colorPreferences,
            'budget_range'      => $budgetRange,
            'notes'             => $notes,
        ]);

        $this->sendOrderNotification(
            $name, $email, $phone, $eventDate,
            $occasion, $arrangementStyle, $colorPreferences, $budgetRange, $notes
        );

        return Response::json([
            'success' => true,
            'message' => __t('order.success'),
        ]);
    }

    /**
     * Send a new bouquet request notification to the business owner.
     *
     * Fires after the order and customer records are persisted. Failures are
     * silently logged so a mail misconfiguration never breaks the form submit.
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
