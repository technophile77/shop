<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Lang;
use App\Models\Customer;
use App\Models\Order;

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

        return Response::json([
            'success' => true,
            'message' => __t('order.success'),
        ]);
    }
}
