<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Models\PageView;
use App\Support\CustomerSource;

/**
 * Handles promotion / newsletter signup form submissions.
 *
 * Route: POST /signup
 *
 * Creates or updates a customer record and inserts a row into
 * `promotion_signups` so marketing lists can be filtered by source page
 * and consent flags independently of the customer table.
 *
 * @see \App\Models\Customer
 */
final class SignupController extends BaseController
{
    /**
     * Processes the promotion signup form.
     *
     * Validates CSRF, requires at least one of email or phone, upserts the
     * customer (source resolved from the session's UTM attribution, falling
     * back to 'promotion_signup'), records the signup event, marks the
     * visitor's ad session as converted, and returns JSON.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response JSON `{success: true}` or `{success: false, error: string}` with 422.
     *
     * @example
     *   // POST /signup — returns {"success":true}
     */
    public function submit(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(['success' => false, 'error' => 'Invalid security token.'], 422);
        }

        $email         = trim((string) $request->post('email', ''));
        $phone         = trim((string) $request->post('phone', ''));
        $optedInEmail  = $request->post('opted_in_email') ? 1 : 0;
        $optedInSms    = $request->post('opted_in_sms')   ? 1 : 0;

        if ($email === '' && $phone === '') {
            return Response::json(
                ['success' => false, 'error' => 'Please provide an email address or phone number.'],
                422
            );
        }

        $customerId = Customer::upsert([
            'email'          => $email,
            'phone'          => $phone,
            'source'         => CustomerSource::resolve($_SESSION['utm'] ?? [], 'promotion_signup'),
            'opted_in_email' => $optedInEmail,
            'opted_in_sms'   => $optedInSms,
        ]);

        // Record the individual signup event so campaign attribution is preserved.
        $sourcePage = $_SERVER['HTTP_REFERER'] ?? '/';

        $stmt = Database::rw()->prepare(
            'INSERT INTO promotion_signups
                (customer_id, email, phone, opted_in_email, opted_in_sms, source_page)
             VALUES
                (:customer_id, :email, :phone, :opted_in_email, :opted_in_sms, :source_page)'
        );

        $stmt->execute([
            ':customer_id'    => $customerId,
            ':email'          => $email   !== '' ? $email   : null,
            ':phone'          => $phone   !== '' ? $phone   : null,
            ':opted_in_email' => $optedInEmail,
            ':opted_in_sms'   => $optedInSms,
            ':source_page'    => $sourcePage,
        ]);

        // Mark the visitor's ad session as converted now that the signup is recorded.
        PageView::markConversion(session_id(), 'signup');

        return Response::json(['success' => true]);
    }
}
