<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\Customer;
use App\Services\TwilioService;

/**
 * Admin controller for the SMS campaign compose and send workflow.
 *
 * Provides a compose page (GET) and a JSON send endpoint (POST) that dispatches
 * SMS campaigns to opted-in customers or a manually entered list via
 * {@see TwilioService}. All routes are protected by the 'auth' middleware.
 *
 * @see \App\Services\TwilioService  Underlying SMS delivery layer.
 * @see \App\Models\Customer         Source of opted-in customer phone numbers.
 * @see \App\Controllers\BaseController  Provides render(), redirect(), json().
 */
final class SmsController extends BaseController
{
    /**
     * Render the SMS campaign compose page.
     *
     * Queries the database for opted-in customer counts and loads all SMS-opted-in
     * customers for the recipient preview. Passes the Twilio configuration state
     * so the view can disable the send button when credentials are missing.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/sms/index using the admin layout.
     *
     * @example
     *   // Dispatched by GET /admin/sms
     *   $response = (new SmsController())->index($request, []);
     */
    public function index(Request $request, array $params = []): Response
    {
        $db = Database::ro();

        $smsOptedIn = (int) $db
            ->query("SELECT COUNT(*) FROM customers WHERE opted_in_sms = 1 AND phone != ''")
            ->fetchColumn();

        $smsCustomers = Customer::all(['opted_in_sms' => 1]);

        $twilioConfigured = TwilioService::isConfigured();
        $csrfToken        = $request->csrfToken();

        return Response::html(
            $this->render('admin/sms/index', [
                'pageTitle'        => 'SMS Campaign',
                'smsOptedIn'       => $smsOptedIn,
                'smsCustomers'     => $smsCustomers,
                'twilioConfigured' => $twilioConfigured,
                'csrfToken'        => $csrfToken,
            ], 'admin')
        );
    }

    /**
     * Handle an SMS campaign send request and return a JSON result.
     *
     * Validates the CSRF token, resolves the recipient list based on the
     * 'recipients' field ('all_sms', 'vip', or 'manual'), then dispatches
     * the message via {@see TwilioService::sendBulk()}. Returns a JSON summary
     * with per-message success/failure detail.
     *
     * POST body (JSON or form-encoded):
     *   - message         (string) The SMS text, 1–160 characters.
     *   - recipients      (string) 'all_sms' | 'vip' | 'manual'.
     *   - manual_numbers  (string) Newline-delimited numbers; used when recipients='manual'.
     *   - _csrf_token     (string) CSRF token.
     *
     * @param Request              $request HTTP request containing POST/JSON data.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response JSON response:
     *         On success: {'success': true, 'sent': N, 'failed': N, 'details': […]}.
     *         On validation/config error: {'success': false, 'error': '…'}.
     *
     * @example
     *   // Dispatched by POST /admin/sms/send (fetch from Alpine.js)
     *   $response = (new SmsController())->send($request, []);
     */
    public function send(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return $this->json(['success' => false, 'error' => 'Invalid security token.'], 403);
        }

        $message        = (string) $request->post('message', '');
        $recipientGroup = (string) $request->post('recipients', 'all_sms');
        $manualRaw      = (string) $request->post('manual_numbers', '');

        $messageLength = strlen($message);
        if ($messageLength === 0) {
            return $this->json(['success' => false, 'error' => 'Message cannot be empty.'], 422);
        }

        if ($messageLength > 160) {
            return $this->json(['success' => false, 'error' => 'Message exceeds 160 characters.'], 422);
        }

        $numbers = $this->resolveNumbers($recipientGroup, $manualRaw);

        if ($numbers === []) {
            return $this->json(['success' => false, 'error' => 'No recipients found.'], 422);
        }

        if (!TwilioService::isConfigured()) {
            return $this->json([
                'success' => false,
                'error'   => 'Twilio is not configured. Add TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, '
                           . 'and TWILIO_FROM_NUMBER to your .env file.',
            ], 503);
        }

        $results = TwilioService::sendBulk($numbers, $message);

        $sent   = 0;
        $failed = 0;

        foreach ($results as $result) {
            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return $this->json([
            'success' => true,
            'sent'    => $sent,
            'failed'  => $failed,
            'details' => $results,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves the phone number list based on the selected recipient group.
     *
     * - 'all_sms'  All customers with opted_in_sms = 1 and a non-empty phone.
     * - 'vip'      VIP customers (lifetime_spend >= threshold) with opted_in_sms = 1.
     * - 'manual'   Numbers parsed from the newline-delimited textarea, one per line.
     *
     * Blank lines and lines containing only whitespace are filtered out.
     * Each value is trimmed before inclusion.
     *
     * @param string $group     One of 'all_sms', 'vip', or 'manual'.
     * @param string $manualRaw Raw textarea content for the 'manual' group.
     *
     * @return string[] Array of phone number strings (digits only for DB-sourced;
     *                  raw trimmed strings for manual entries).
     */
    private function resolveNumbers(string $group, string $manualRaw): array
    {
        if ($group === 'manual') {
            $lines = explode("\n", $manualRaw);
            return array_values(
                array_filter(
                    array_map('trim', $lines),
                    static fn (string $line): bool => $line !== '',
                )
            );
        }

        if ($group === 'vip') {
            $customers = Customer::vip();
            $customers = array_filter(
                $customers,
                static fn (array $c): bool => (int) ($c['opted_in_sms'] ?? 0) === 1
                    && ($c['phone'] ?? '') !== '',
            );
        } else {
            // Default: all opted-in SMS customers with a phone number.
            $stmt = Database::ro()->prepare(
                "SELECT phone FROM customers WHERE opted_in_sms = 1 AND phone != ''"
            );
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        return array_values(
            array_map(
                static fn (array $c): string => (string) $c['phone'],
                $customers,
            )
        );
    }
}
