<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\Order;

/**
 * Admin controller for the Orders section.
 *
 * Lists and shows shop-cart checkout orders — the purchases customers pay for via
 * Stripe through /checkout — and lets the owner move an order through its
 * fulfillment lifecycle. Custom bouquet requests (from the public /order form) are
 * deliberately excluded here: they surface as auto-drafted quotes under the Quotes
 * section. The scope is enforced by only ever reading rows where `items_json` is
 * set (see {@see Order::allShopOrders()} and the guard in {@see show()}).
 *
 * Flash messages are written to $_SESSION['flash'] as
 * ['type' => 'success'|'error', 'message' => '…'] and consumed by the view on the
 * following request. Auth enforcement is handled by the Router's 'auth' middleware.
 *
 * @see \App\Models\Order                Order persistence, filtering, status writes.
 * @see \App\Support\ShopOrderSnapshot   Item-snapshot decoding/summarising for views.
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 */
final class OrdersController extends BaseController
{
    /** Payment statuses an order row may hold; used to validate the ?payment= filter. */
    private const PAYMENT_STATUSES = ['paid', 'unpaid'];

    // -------------------------------------------------------------------------
    // GET /admin/orders — list
    // -------------------------------------------------------------------------

    /**
     * Render the shop-order list, optionally filtered by status and payment.
     *
     * Reads `status` and `payment` from the query string, validates each against
     * {@see Order::STATUSES} and {@see self::PAYMENT_STATUSES} respectively
     * (invalid values are ignored rather than erroring), and loads the matching
     * rows via {@see Order::allShopOrders()}. The raw filter values are passed back
     * to the view so the active tab can be highlighted without re-querying.
     *
     * @param Request              $request HTTP request; reads 'status' and 'payment' from the query string.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/orders/list.
     *
     * @example
     *   // GET /admin/orders?payment=unpaid
     *   (new OrdersController())->index($request, []);
     */
    public function index(Request $request, array $params = []): Response
    {
        $statusFilter = trim((string) $request->query('status', ''));
        if ($statusFilter !== '' && !in_array($statusFilter, Order::STATUSES, true)) {
            $statusFilter = '';
        }

        $paymentFilter = trim((string) $request->query('payment', ''));
        if ($paymentFilter !== '' && !in_array($paymentFilter, self::PAYMENT_STATUSES, true)) {
            $paymentFilter = '';
        }

        $orders = Order::allShopOrders([
            'status'         => $statusFilter,
            'payment_status' => $paymentFilter,
        ]);

        return Response::html(
            $this->render('admin/orders/list', [
                'orders'        => $orders,
                'statusFilter'  => $statusFilter,
                'paymentFilter' => $paymentFilter,
                'pageTitle'     => 'Orders',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/orders/{id} — detail
    // -------------------------------------------------------------------------

    /**
     * Render the detail page for a single shop order.
     *
     * Loads the order by primary key and 404s when it is missing or is a bouquet
     * request rather than a shop purchase (`items_json` is null). Decodes the item
     * snapshot for display and generates a CSRF token for the status-change form.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Rendered HTML for admin/orders/detail, or a 404 response.
     *
     * @example
     *   // GET /admin/orders/42
     *   (new OrdersController())->show($request, ['id' => '42']);
     */
    public function show(Request $request, array $params = []): Response
    {
        $id    = (int) ($params['id'] ?? 0);
        $order = Order::find($id);

        // Scope guard: only shop-cart purchases are viewable here. A null row or a
        // bouquet request (no items_json) is treated as not found.
        if ($order === null || ($order['items_json'] ?? null) === null) {
            return Response::notFound();
        }

        $items = json_decode((string) $order['items_json'], true);
        if (!is_array($items)) {
            $items = [];
        }

        return Response::html(
            $this->render('admin/orders/detail', [
                'order'     => $order,
                'items'     => $items,
                'csrfToken' => $request->csrfToken(),
                'pageTitle' => 'Order #' . $id,
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/orders/{id}/status — transition
    // -------------------------------------------------------------------------

    /**
     * Update a shop order's fulfillment status.
     *
     * Validates the CSRF token and ensures the requested status is one of
     * {@see Order::STATUSES}, then writes it via {@see Order::updateStatus()}.
     * Follows the Post-Redirect-Get pattern: every outcome flashes a message and
     * redirects back to the order detail page.
     *
     * @param Request              $request HTTP request with POST body.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/orders/{id}.
     *
     * @example
     *   // POST /admin/orders/42/status  body: new_status=ready&_csrf_token=…
     *   (new OrdersController())->updateStatus($request, ['id' => '42']);
     */
    public function updateStatus(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/orders/' . $id);
        }

        $newStatus = trim((string) $request->post('new_status', ''));

        if (!in_array($newStatus, Order::STATUSES, true)) {
            $this->setFlash('error', 'Invalid status value.');
            return $this->redirect('/admin/orders/' . $id);
        }

        Order::updateStatus($id, $newStatus);

        $this->setFlash('success', 'Order status updated.');
        return $this->redirect('/admin/orders/' . $id);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
