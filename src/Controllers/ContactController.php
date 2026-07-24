<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\Customer;
use App\Services\MailService;

/**
 * Handles the public contact page and its form submission.
 *
 * GET  /contact — renders the contact form.
 * POST /contact — saves the message to the customers table, returns JSON.
 *
 * @see \App\Models\Customer
 */
final class ContactController extends BaseController
{
    /**
     * Renders the contact page with CSRF token.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response Rendered HTML response.
     *
     * @example
     *   // GET /contact
     */
    public function index(Request $request, array $params = []): Response
    {
        $lang      = Lang::current();
        $pageTitle = (string) Settings::get('contact_page_title_' . $lang, 'Get in Touch');

        $html = $this->render('public/contact', [
            'lang'       => $lang,
            'pageTitle'  => $pageTitle,
            'csrfToken'  => $request->csrfToken(),
        ]);

        return Response::html($html);
    }

    /**
     * Processes the contact form submission.
     *
     * Validates CSRF, requires a message of at least 10 characters and at
     * least one of email or phone, then upserts the customer record with the
     * message stored in the notes field.  Returns JSON.
     *
     * @param Request              $request HTTP request.
     * @param array<string, mixed> $params  Route parameters (unused).
     *
     * @return Response JSON `{success: true}` or `{success: false, error: string}` with 422.
     *
     * @example
     *   // POST /contact — returns {"success":true}
     */
    public function submit(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(['success' => false, 'error' => 'Invalid security token.'], 422);
        }

        $name    = trim((string) $request->post('name', ''));
        $email   = trim((string) $request->post('email', ''));
        $phone   = trim((string) $request->post('phone', ''));
        $message = trim((string) $request->post('message', ''));

        // Message is required and must be meaningful.
        if (strlen($message) < 10) {
            return Response::json(
                ['success' => false, 'error' => 'Please enter a message of at least 10 characters.'],
                422
            );
        }

        // At least one contact method is required.
        if ($email === '' && $phone === '') {
            return Response::json(
                ['success' => false, 'error' => 'Please provide an email address or phone number.'],
                422
            );
        }

        Customer::upsert([
            'name'   => $name,
            'email'  => $email,
            'phone'  => $phone,
            'source' => 'other',
            'notes'  => $message,
        ]);

        $this->sendContactNotification($name, $email, $phone, $message);

        return Response::json(['success' => true]);
    }

    /**
     * Send a new contact message notification to the business owner.
     *
     * Failures are silently logged so a mail misconfiguration never breaks
     * the contact form submit.
     */
    private function sendContactNotification(
        string $name,
        string $email,
        string $phone,
        string $message,
    ): void {
        $to = (string) Config::get('BUSINESS_EMAIL', '');
        if ($to === '' || !MailService::canSend()) {
            return;
        }

        $rows = '';
        foreach ([
            'Name'    => $name,
            'Email'   => $email,
            'Phone'   => $phone,
            'Message' => $message,
        ] as $label => $value) {
            if ($value === '') {
                continue;
            }
            $rows .= '<tr>'
                . '<td style="padding:6px 12px;font-weight:600;color:#555;white-space:nowrap;vertical-align:top">'
                . htmlspecialchars($label) . '</td>'
                . '<td style="padding:6px 12px;color:#1a1a1a">'
                . nl2br(htmlspecialchars($value)) . '</td>'
                . '</tr>';
        }

        $html = MailService::buildHtml(
            '<h2 style="margin:0 0 1rem;font-size:1.2rem">New Contact Message</h2>'
            . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
            . '<p style="margin:1.5rem 0 0;font-size:0.85rem;color:#999">'
            . 'View all customers in the <a href="' . htmlspecialchars((string) Config::get('APP_URL', '')) . '/admin/customers">admin panel</a>.'
            . '</p>'
        );

        $result = MailService::send($to, '', 'New Message from ' . ($name ?: 'Website Visitor'), $html);

        if (!$result['success']) {
            error_log('[ContactController] Notification email failed: ' . ($result['error'] ?? 'unknown'));
        }
    }
}
