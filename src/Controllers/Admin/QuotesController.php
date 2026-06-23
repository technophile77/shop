<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Models\Quote;
use App\Services\QuoteService;

/**
 * Admin controller for the Quotes section.
 *
 * Handles listing quotes (with optional status filter), rendering the quote
 * builder form, creating new quotes, viewing quote detail, and transitioning
 * quote statuses. The controller delegates all persistence to {@see Quote} and
 * customer lookups/upserts to {@see Customer}; URL generation and subtotal
 * math live in {@see QuoteService}.
 *
 * Flash messages are written to $_SESSION['flash'] as
 * ['type' => 'success'|'error', 'message' => '…'] and are consumed by the
 * corresponding view on the following request.
 *
 * Auth enforcement is handled by the Router's 'auth' middleware — this
 * controller performs no session checks of its own.
 *
 * @see \App\Models\Quote          All quote persistence and status transitions.
 * @see \App\Models\Customer       Customer lookup and upsert for new-customer flow.
 * @see \App\Services\QuoteService URL generation and item decoding.
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 */
final class QuotesController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /admin/quotes — list
    // -------------------------------------------------------------------------

    /**
     * Render the quote list, optionally filtered by status.
     *
     * Loads all quotes with customer name via {@see Quote::all()}, then applies
     * a PHP-side status filter when the ?status= query parameter is present.
     * Passes the raw filter value to the view so the active tab can be
     * highlighted without re-querying.
     *
     * @param Request              $request HTTP request; reads 'status' from query string.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/quotes/list.
     *
     * @example
     *   // GET /admin/quotes?status=sent
     *   (new QuotesController())->index($request, []);
     */
    public function index(Request $request, array $params = []): Response
    {
        $statusFilter = trim((string) $request->query('status', ''));
        if ($statusFilter !== '' && !in_array($statusFilter, Quote::STATUSES, true)) {
            $statusFilter = '';
        }

        $allQuotes = Quote::all();

        $quotes = $statusFilter !== ''
            ? array_values(
                array_filter(
                    $allQuotes,
                    static fn (array $q): bool => $q['status'] === $statusFilter
                )
            )
            : $allQuotes;

        return Response::html(
            $this->render('admin/quotes/list', [
                'quotes'       => $quotes,
                'statusFilter' => $statusFilter,
                'pageTitle'    => 'Quotes',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/quotes/new — builder form
    // -------------------------------------------------------------------------

    /**
     * Render the new-quote builder form.
     *
     * Loads the full customer list so the builder's customer selector can be
     * populated server-side. Generates a CSRF token for the subsequent POST.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/quotes/builder.
     *
     * @example
     *   // GET /admin/quotes/new
     *   (new QuotesController())->newForm($request, []);
     */
    public function newForm(Request $request, array $params = []): Response
    {
        $customers = Customer::all();
        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/quotes/builder', [
                'customers' => $customers,
                'csrfToken' => $csrfToken,
                'pageTitle' => 'New Quote',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/quotes — create
    // -------------------------------------------------------------------------

    /**
     * Create a new quote and redirect to its detail page.
     *
     * Parses POST data for customer info, event details, deposit settings, and
     * line items. When customer_id is 0 (new customer) and at least a name or
     * email is supplied, the customer is upserted via {@see Customer::upsert()}.
     * Items are assembled from parallel arrays (item_description[], item_qty[],
     * item_unit_price[]). At least one item with a description and unit_price > 0
     * is required. On success the quote is immediately transitioned from 'draft'
     * to 'sent' via {@see Quote::markSent()} so the share link is ready.
     *
     * @param Request              $request HTTP request with POST body.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/quotes/{id} on success, or back to the
     *         builder with an error flash on validation failure.
     *
     * @example
     *   // POST /admin/quotes
     *   (new QuotesController())->create($request, []);
     */
    public function create(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/quotes/new');
        }

        // --- Customer resolution ---
        $customerId = $this->resolveCustomerId($request);

        // --- Event and quote settings ---
        $eventDate  = trim((string) $request->post('event_date', ''));
        $depositPct = (int) $request->post('deposit_pct', 50);
        $validDays  = (int) $request->post('valid_days', 14);
        $notes      = trim((string) $request->post('notes', ''));

        // --- Line items ---
        $items = $this->buildItems($request);

        if ($items === []) {
            $this->setFlash('error', 'Please add at least one item with a description and a price greater than zero.');
            return $this->redirect('/admin/quotes/new');
        }

        // --- Persist ---
        $quoteId = Quote::create([
            'customer_id' => $customerId > 0 ? $customerId : null,
            'event_date'  => $eventDate !== '' ? $eventDate : null,
            'items'       => $items,
            'deposit_pct' => $depositPct,
            'notes'       => $notes !== '' ? $notes : null,
            'valid_days'  => $validDays,
        ]);

        // Mark as sent immediately — the admin is creating the quote to share.
        Quote::markSent($quoteId);

        $this->setFlash('success', 'Quote created successfully. Copy the link below to share it.');
        return $this->redirect('/admin/quotes/' . $quoteId);
    }

    // -------------------------------------------------------------------------
    // GET /admin/quotes/{id} — detail
    // -------------------------------------------------------------------------

    /**
     * Render the quote detail page.
     *
     * Loads the quote by primary key and 404s when not found. Decodes the
     * stored items JSON and builds the shareable public URL. Generates a CSRF
     * token for the status-transition forms on the page.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Rendered HTML for admin/quotes/detail, or a 404 response.
     *
     * @example
     *   // GET /admin/quotes/12
     *   (new QuotesController())->show($request, ['id' => '12']);
     */
    public function show(Request $request, array $params = []): Response
    {
        $id    = (int) ($params['id'] ?? 0);
        $quote = Quote::find($id);

        if ($quote === null) {
            return Response::html('<h1>Quote Not Found</h1>', 404);
        }

        $items    = QuoteService::decodeItems((string) $quote['items_json']);
        $quoteUrl = QuoteService::quoteUrl((string) $quote['token']);

        return Response::html(
            $this->render('admin/quotes/detail', [
                'quote'     => $quote,
                'items'     => $items,
                'quoteUrl'  => $quoteUrl,
                'csrfToken' => $request->csrfToken(),
                'pageTitle' => 'Quote #' . $id,
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/quotes/{id}/status — transition
    // -------------------------------------------------------------------------

    /**
     * Transition a quote to a new status.
     *
     * Validates the CSRF token and ensures the requested status is in
     * {@see Quote::STATUSES}. Delegates the actual transition (including its
     * guard logic) to {@see Quote::transition()}. When the new status is
     * 'deposit_confirmed', fires an owner SMS notification via
     * {@see QuoteService::notifyOwner()}. Failures from transition() (invalid
     * transitions) are caught and surfaced as error flash messages so the UI
     * remains functional rather than throwing a 500.
     *
     * @param Request              $request HTTP request with POST body.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/quotes/{id}.
     *
     * @example
     *   // POST /admin/quotes/12/status  body: new_status=accepted&_csrf_token=…
     *   (new QuotesController())->updateStatus($request, ['id' => '12']);
     */
    public function updateStatus(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/quotes/' . $id);
        }

        $newStatus = trim((string) $request->post('new_status', ''));

        if (!in_array($newStatus, Quote::STATUSES, true)) {
            $this->setFlash('error', 'Invalid status value.');
            return $this->redirect('/admin/quotes/' . $id);
        }

        try {
            Quote::transition($id, $newStatus);
        } catch (\RuntimeException $e) {
            $this->setFlash('error', $e->getMessage());
            return $this->redirect('/admin/quotes/' . $id);
        }

        if ($newStatus === 'deposit_confirmed') {
            $quote   = Quote::find($id);
            $name    = (string) ($quote['customer_name'] ?? 'Unknown customer');
            $amount  = isset($quote['deposit_amount'])
                ? '$' . number_format((float) $quote['deposit_amount'], 2)
                : '';
            $message = "Deposit confirmed — {$name} — {$amount} — Quote #{$id}";
            QuoteService::notifyOwner($message);
        }

        return $this->redirect('/admin/quotes/' . $id);
    }

    // -------------------------------------------------------------------------
    // GET /admin/quotes/{id}/edit — edit form
    // -------------------------------------------------------------------------

    /**
     * Render the quote builder pre-filled for editing an existing quote.
     *
     * Loads the quote and 404s when not found. Quotes past the editable window
     * ({@see Quote::isEditable()}) are refused with an error flash and a redirect
     * to the detail page. Otherwise the shared {@see views/admin/quotes/builder}
     * view is rendered in edit mode: items are decoded, the customer list is
     * loaded for the selector, the validity window is recovered from the stored
     * dates, and an $initialState blob seeds the Alpine component.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Rendered builder, a 404, or a redirect for locked quotes.
     *
     * @example
     *   // GET /admin/quotes/12/edit
     *   (new QuotesController())->editForm($request, ['id' => '12']);
     */
    public function editForm(Request $request, array $params = []): Response
    {
        $id    = (int) ($params['id'] ?? 0);
        $quote = Quote::find($id);

        if ($quote === null) {
            return Response::html('<h1>Quote Not Found</h1>', 404);
        }

        if (!Quote::isEditable((string) $quote['status'])) {
            $this->setFlash('error', 'This quote can no longer be edited.');
            return $this->redirect('/admin/quotes/' . $id);
        }

        $items = QuoteService::decodeItems((string) $quote['items_json']);

        $initialState = [
            'customerId'    => (int) ($quote['customer_id'] ?? 0),
            'customerName'  => (string) ($quote['customer_name']  ?? ''),
            'customerEmail' => (string) ($quote['customer_email'] ?? ''),
            'customerPhone' => (string) ($quote['customer_phone'] ?? ''),
            'eventDate'     => (string) ($quote['event_date'] ?? ''),
            'validDays'     => $this->validityDays($quote),
            'depositPct'    => (int) $quote['deposit_pct'],
            'notes'         => (string) ($quote['notes'] ?? ''),
            'items'         => $items,
        ];

        return Response::html(
            $this->render('admin/quotes/builder', [
                'customers'    => Customer::all(),
                'csrfToken'    => $request->csrfToken(),
                'formAction'   => '/admin/quotes/' . $id . '/edit',
                'submitLabel'  => 'Save Changes',
                'initialState' => $initialState,
                'pageTitle'    => 'Edit Quote #' . $id,
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/quotes/{id}/edit — update
    // -------------------------------------------------------------------------

    /**
     * Apply edits to an existing quote and redirect to its detail page.
     *
     * Validates CSRF, re-checks that the quote exists and is still editable
     * (guarding against a status change between load and submit), then resolves
     * the customer and rebuilds the line items the same way {@see create()} does.
     * Requires at least one valid item. Persists via {@see Quote::update()},
     * which recomputes the totals and refreshes the validity window. The quote's
     * status and share token are left untouched.
     *
     * @param Request              $request HTTP request with POST body.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/quotes/{id} on success, back to the
     *         edit form on validation failure, or a redirect to detail when the
     *         quote is no longer editable.
     *
     * @example
     *   // POST /admin/quotes/12/edit
     *   (new QuotesController())->update($request, ['id' => '12']);
     */
    public function update(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/quotes/' . $id . '/edit');
        }

        $quote = Quote::find($id);
        if ($quote === null) {
            return Response::html('<h1>Quote Not Found</h1>', 404);
        }

        if (!Quote::isEditable((string) $quote['status'])) {
            $this->setFlash('error', 'This quote can no longer be edited.');
            return $this->redirect('/admin/quotes/' . $id);
        }

        $items = $this->buildItems($request);
        if ($items === []) {
            $this->setFlash('error', 'Please add at least one item with a description and a price greater than zero.');
            return $this->redirect('/admin/quotes/' . $id . '/edit');
        }

        $customerId = $this->resolveCustomerId($request);
        $eventDate  = trim((string) $request->post('event_date', ''));
        $notes      = trim((string) $request->post('notes', ''));

        Quote::update($id, [
            'customer_id' => $customerId > 0 ? $customerId : null,
            'event_date'  => $eventDate !== '' ? $eventDate : null,
            'items'       => $items,
            'deposit_pct' => (int) $request->post('deposit_pct', 50),
            'notes'       => $notes !== '' ? $notes : null,
            'valid_days'  => (int) $request->post('valid_days', 14),
        ]);

        $this->setFlash('success', 'Quote updated successfully.');
        return $this->redirect('/admin/quotes/' . $id);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the customer ID from the request, upserting a new customer when needed.
     *
     * Returns the posted customer_id when an existing customer is selected. When
     * customer_id is 0 (new customer) and at least one of name/email/phone is
     * supplied, upserts the customer via {@see Customer::upsert()} and returns
     * the new ID. Returns 0 when no customer is selected and no details are given.
     *
     * @param Request $request The current HTTP request.
     *
     * @return int The resolved customer ID, or 0 when there is no customer.
     *
     * @example
     *   $customerId = $this->resolveCustomerId($request); // 7, or 0 for none
     */
    private function resolveCustomerId(Request $request): int
    {
        $customerId    = (int) $request->post('customer_id', 0);
        $customerName  = strip_tags(trim((string) $request->post('customer_name', '')));
        $customerEmail = trim((string) $request->post('customer_email', ''));
        $customerPhone = trim((string) $request->post('customer_phone', ''));

        if ($customerId === 0 && ($customerName !== '' || $customerEmail !== '' || $customerPhone !== '')) {
            $customerId = Customer::upsert([
                'name'   => $customerName !== '' ? $customerName : null,
                'email'  => $customerEmail !== '' ? $customerEmail : null,
                'phone'  => $customerPhone !== '' ? $customerPhone : null,
                'source' => 'admin_quote',
            ]);
        }

        return $customerId;
    }

    /**
     * Recover the original validity window (in days) from a stored quote.
     *
     * The quote stores `valid_until` (an absolute date) rather than the number
     * of days chosen at creation, so this derives the span from `created_at` to
     * `valid_until` and snaps it to the builder's allowed options (7, 14, 30),
     * falling back to 14 when the value is missing or off-grid. This seeds the
     * edit form's "Quote Valid For" selector sensibly.
     *
     * @param array<string,mixed> $quote A quote row with created_at and valid_until.
     *
     * @return int One of 7, 14, or 30.
     *
     * @example
     *   $days = $this->validityDays($quote); // 14
     */
    private function validityDays(array $quote): int
    {
        $allowed = [7, 14, 30];
        $created = strtotime((string) ($quote['created_at'] ?? ''));
        $until   = strtotime((string) ($quote['valid_until'] ?? ''));

        if ($created === false || $until === false || $until < $created) {
            return 14;
        }

        $days = (int) round(($until - $created) / 86400);

        return in_array($days, $allowed, true) ? $days : 14;
    }

    /**
     * Build the validated items array from POST parallel arrays.
     *
     * Reads item_description[], item_qty[], and item_unit_price[] from the
     * request. Rows where the description is blank or the unit_price is zero
     * or below are silently skipped; the remaining rows are returned as a
     * typed array ready for {@see Quote::create()}.
     *
     * @param Request $request The current HTTP request.
     *
     * @return array<int, array{description: string, qty: int, unit_price: float}>
     *         Validated, non-empty line items.
     *
     * @example
     *   $items = $this->buildItems($request);
     *   // [['description' => 'Red Rose Bouquet', 'qty' => 2, 'unit_price' => 45.00]]
     */
    private function buildItems(Request $request): array
    {
        /** @var list<string> $descriptions */
        $descriptions = (array) ($request->post('item_description') ?? []);
        /** @var list<string> $qtys */
        $qtys         = (array) ($request->post('item_qty') ?? []);
        /** @var list<string> $prices */
        $prices       = (array) ($request->post('item_unit_price') ?? []);

        $items = [];
        $count = count($descriptions);

        for ($i = 0; $i < $count; $i++) {
            $description = trim((string) ($descriptions[$i] ?? ''));
            $qty         = max(1, (int) ($qtys[$i] ?? 1));
            $unitPrice   = (float) ($prices[$i] ?? 0);

            if ($description === '' || $unitPrice <= 0) {
                continue;
            }

            $items[] = [
                'description' => $description,
                'qty'         => $qty,
                'unit_price'  => $unitPrice,
            ];
        }

        return $items;
    }

    /**
     * Store a flash message in the session for the next page render.
     *
     * @param 'success'|'error' $type    Severity level.
     * @param string            $message Human-readable message text.
     *
     * @return void
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}
