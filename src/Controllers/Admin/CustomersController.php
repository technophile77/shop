<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\Customer;

/**
 * Admin controller for the Customers CRM section.
 *
 * Provides listing with filters, detail view with full history, CSV export,
 * manual customer creation, and inline notes editing.
 *
 * All routes are protected by the 'auth' middleware — the Router enforces
 * this before any method here is invoked.
 *
 * Flash messages are stored in $_SESSION['flash'] as
 * ['type' => 'success'|'error', 'message' => '…'] and read/cleared by views.
 *
 * @see \App\Models\Customer        All customer database operations.
 * @see \App\Controllers\BaseController  Provides render(), redirect(), json().
 */
final class CustomersController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /admin/customers — list with filters
    // -------------------------------------------------------------------------

    /**
     * Render the customer list with optional filter and segment controls.
     *
     * Accepted query parameters:
     *   - source       Filter by customer source string.
     *   - opted_in_sms Filter to SMS-opted-in customers (value '1').
     *   - min_spend    Filter by minimum lifetime spend.
     *   - segment      When 'vip', uses Customer::vip() instead of Customer::all().
     *
     * Passes to the view: customers, totalCount, vipCount, smsCount,
     * vipThreshold, sources (distinct values from DB), filters, csrfToken.
     *
     * @param Request              $request HTTP request; query params supply filters.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/customers/list.
     *
     * @example
     *   // Dispatched by GET /admin/customers?segment=vip
     *   (new CustomersController())->index($request, []);
     */
    public function index(Request $request, array $params = []): Response
    {
        $segment    = $request->query('segment', '');
        $source     = $request->query('source', '');
        $optedInSms = $request->query('opted_in_sms', '');
        $minSpend   = $request->query('min_spend', '');

        $filters = [];
        if ($source !== '') {
            $filters['source'] = $source;
        }
        if ($optedInSms === '1') {
            $filters['opted_in_sms'] = 1;
        }
        if ($minSpend !== '') {
            $filters['min_spend'] = (float) $minSpend;
        }

        // VIP segment bypasses other filters and uses the threshold-based query.
        if ($segment === 'vip') {
            $customers = Customer::vip();
        } else {
            $customers = Customer::all($filters);
        }

        // Counts for the stats bar — always query all customers for accuracy.
        $allCustomers = Customer::all();
        $vipCustomers = Customer::vip();
        $smsCount     = count(Customer::all(['opted_in_sms' => 1]));
        $totalCount   = count($allCustomers);
        $vipCount     = count($vipCustomers);

        $vipThreshold = (float) Settings::get('vip_spend_threshold', '200');

        // Distinct source values for the filter dropdown.
        $stmt = Database::ro()->prepare(
            'SELECT DISTINCT source FROM customers ORDER BY source'
        );
        $stmt->execute();
        $sources = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/customers/list', [
                'customers'    => $customers,
                'totalCount'   => $totalCount,
                'vipCount'     => $vipCount,
                'smsCount'     => $smsCount,
                'vipThreshold' => $vipThreshold,
                'sources'      => $sources,
                'filters'      => [
                    'source'       => $source,
                    'opted_in_sms' => $optedInSms,
                    'min_spend'    => $minSpend,
                    'segment'      => $segment,
                ],
                'csrfToken'    => $csrfToken,
                'pageTitle'    => 'Customers',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/customers/{id} — detail
    // -------------------------------------------------------------------------

    /**
     * Render the customer detail page including quote, order, and signup history.
     *
     * Returns a 404 response when no customer with the given ID exists.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Rendered HTML for admin/customers/detail, or 404.
     *
     * @example
     *   (new CustomersController())->show($request, ['id' => '42']);
     */
    public function show(Request $request, array $params = []): Response
    {
        $id       = (int) ($params['id'] ?? 0);
        $customer = Customer::find($id);

        if ($customer === null) {
            return Response::notFound();
        }

        $db = Database::ro();

        $stmtQuotes = $db->prepare(
            'SELECT id, token, status, subtotal, deposit_amount, event_date, created_at
             FROM quotes
             WHERE customer_id = ?
             ORDER BY created_at DESC'
        );
        $stmtQuotes->execute([$id]);
        $quotes = $stmtQuotes->fetchAll();

        $stmtOrders = $db->prepare(
            'SELECT id, status, event_date, occasion, created_at
             FROM orders
             WHERE customer_id = ?
             ORDER BY created_at DESC'
        );
        $stmtOrders->execute([$id]);
        $orders = $stmtOrders->fetchAll();

        $stmtSignups = $db->prepare(
            'SELECT * FROM promotion_signups WHERE customer_id = ? ORDER BY created_at DESC'
        );
        $stmtSignups->execute([$id]);
        $signups = $stmtSignups->fetchAll();

        $vipThreshold = (float) Settings::get('vip_spend_threshold', '200');
        $csrfToken    = $request->csrfToken();

        return Response::html(
            $this->render('admin/customers/detail', [
                'customer'     => $customer,
                'quotes'       => $quotes,
                'orders'       => $orders,
                'signups'      => $signups,
                'vipThreshold' => $vipThreshold,
                'csrfToken'    => $csrfToken,
                'pageTitle'    => $customer['name'] ?? 'Customer Detail',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/customers/export — CSV download
    // -------------------------------------------------------------------------

    /**
     * Stream all customers (or only opted-in ones) as a downloadable CSV file.
     *
     * When the query parameter `all=1` is present, every customer is included.
     * Otherwise only customers who have opted in via email or SMS are exported.
     *
     * CSV columns: id, name, email, phone, source, opted_in_email,
     * opted_in_sms, lifetime_spend, created_at.
     *
     * @param Request              $request HTTP request; optional query param 'all'.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response CSV response with Content-Disposition attachment header.
     *
     * @example
     *   // All customers: GET /admin/customers/export?all=1
     *   // Opted-in only: GET /admin/customers/export
     */
    public function export(Request $request, array $params = []): Response
    {
        $includeAll = $request->query('all', '') === '1';

        if ($includeAll) {
            $rows = Customer::all();
        } else {
            // Opted-in to at least one channel.
            $stmt = Database::ro()->prepare(
                'SELECT * FROM customers
                 WHERE opted_in_email = 1 OR opted_in_sms = 1
                 ORDER BY lifetime_spend DESC'
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
        }

        $filename = 'customers-' . date('Y-m-d') . '.csv';

        ob_start();
        $fh = fopen('php://output', 'w');

        fputcsv($fh, [
            'id', 'name', 'email', 'phone', 'source',
            'opted_in_email', 'opted_in_sms', 'lifetime_spend', 'created_at',
        ]);

        foreach ($rows as $row) {
            fputcsv($fh, [
                $row['id']             ?? '',
                $row['name']           ?? '',
                $row['email']          ?? '',
                $row['phone']          ?? '',
                $row['source']         ?? '',
                $row['opted_in_email'] ?? 0,
                $row['opted_in_sms']   ?? 0,
                $row['lifetime_spend'] ?? '0.00',
                $row['created_at']     ?? '',
            ]);
        }

        fclose($fh);
        $csv = (string) ob_get_clean();

        return (new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]));
    }

    // -------------------------------------------------------------------------
    // POST /admin/customers — manual create
    // -------------------------------------------------------------------------

    /**
     * Handle the quick-add customer form submission.
     *
     * Validates the CSRF token, upserts the customer from POST data, then
     * redirects to the customer list with a flash message.
     *
     * POST fields: name, email, phone, source, notes.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/customers.
     *
     * @example
     *   (new CustomersController())->create($request, []);
     */
    public function create(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/customers');
        }

        $data = [
            'name'           => strip_tags(trim((string) $request->post('name', ''))),
            'email'          => trim((string) $request->post('email', '')),
            'phone'          => trim((string) $request->post('phone', '')),
            'source'         => trim((string) $request->post('source', 'other')),
            'notes'          => trim((string) $request->post('notes', '')),
            'opted_in_email' => 0,
            'opted_in_sms'   => 0,
        ];

        if ($data['name'] === '' && $data['email'] === '' && $data['phone'] === '') {
            $this->setFlash('error', 'At least a name, email, or phone is required.');
            return $this->redirect('/admin/customers');
        }

        Customer::upsert($data);

        $this->setFlash('success', 'Customer added successfully.');
        return $this->redirect('/admin/customers');
    }

    // -------------------------------------------------------------------------
    // POST /admin/customers/{id}/notes — inline notes update
    // -------------------------------------------------------------------------

    /**
     * Handle the inline notes form on the customer detail page.
     *
     * Validates CSRF, overwrites the notes field via Customer::updateNotes(),
     * then redirects back to the detail page.
     *
     * @param Request              $request HTTP request; POST field 'notes'.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/customers/{id}.
     *
     * @example
     *   (new CustomersController())->updateNotes($request, ['id' => '42']);
     */
    public function updateNotes(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect("/admin/customers/{$id}");
        }

        $notes = trim((string) $request->post('notes', ''));
        Customer::updateNotes($id, $notes);

        $this->setFlash('success', 'Notes updated.');
        return $this->redirect("/admin/customers/{$id}");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Store a flash message in the session for the next page render.
     *
     * The admin layout and list views read $_SESSION['flash'] and clear it
     * immediately after display.
     *
     * @param 'success'|'error' $type    Severity level; controls CSS class selection.
     * @param string            $message Human-readable message to display.
     *
     * @return void
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}
