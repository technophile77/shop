<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Serves the admin dashboard overview page.
 *
 * Executes seven read-only queries in sequence to collect KPI counters,
 * revenue figures, and the two activity lists (recent quotes + upcoming events)
 * shown on the dashboard. All queries use Database::ro() — no writes occur
 * during a dashboard load.
 *
 * The dashboard is protected by the Router's 'auth' middleware; this controller
 * does not repeat the session check.
 *
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 * @see \App\Core\Database               Supplies the read-only PDO connection.
 */
final class DashboardController extends BaseController
{
    /**
     * Render the admin dashboard.
     *
     * Queries collected:
     *   1. Open quotes count       — status IN ('draft','sent','accepted').
     *   2. Deposit-confirmed count — status = 'deposit_confirmed'.
     *   3. New customers this month.
     *   4. Total customers.
     *   5. Revenue this month      — SUM(subtotal) for confirmed/completed quotes.
     *   6. Recent activity         — last 10 quotes with customer name.
     *   7. Upcoming events         — quotes with event_date in next 30 days, not cancelled.
     *
     * @param Request              $request Current HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response HTML response for the dashboard page.
     *
     * @example
     *   // Dispatched by the router for GET /admin
     *   $response = (new DashboardController())->index($request, []);
     */
    public function index(Request $request, array $params = []): Response
    {
        $db = Database::ro();

        // 1. Open quotes (not yet resolved or cancelled).
        $openQuotes = (int) $db
            ->query("SELECT COUNT(*) FROM quotes WHERE status IN ('draft','sent','accepted')")
            ->fetchColumn();

        // 2. Quotes awaiting deposit confirmation.
        $pendingDeposits = (int) $db
            ->query("SELECT COUNT(*) FROM quotes WHERE status = 'deposit_confirmed'")
            ->fetchColumn();

        // 3. Customers acquired this calendar month.
        $customersThisMonth = (int) $db
            ->query(
                'SELECT COUNT(*) FROM customers'
                . ' WHERE MONTH(created_at) = MONTH(NOW())'
                . ' AND YEAR(created_at) = YEAR(NOW())'
            )
            ->fetchColumn();

        // 4. All-time customer count.
        $totalCustomers = (int) $db
            ->query('SELECT COUNT(*) FROM customers')
            ->fetchColumn();

        // 5. Revenue this month from confirmed + completed quotes.
        $revenueThisMonth = (float) $db
            ->query(
                "SELECT COALESCE(SUM(subtotal), 0) FROM quotes"
                . " WHERE status IN ('deposit_confirmed','completed')"
                . ' AND MONTH(created_at) = MONTH(NOW())'
                . ' AND YEAR(created_at) = YEAR(NOW())'
            )
            ->fetchColumn();

        // 6. Last 10 quotes with customer name for the activity table.
        $recentQuotes = $db
            ->query(
                'SELECT q.id, q.status, q.subtotal, q.deposit_amount, q.created_at,'
                . ' c.name AS customer_name'
                . ' FROM quotes q'
                . ' LEFT JOIN customers c ON c.id = q.customer_id'
                . ' ORDER BY q.created_at DESC'
                . ' LIMIT 10'
            )
            ->fetchAll(\PDO::FETCH_ASSOC);

        // 7. Quotes with an event_date in the next 30 days, excluding cancelled ones.
        $upcomingEvents = $db
            ->query(
                'SELECT q.id, q.status, q.subtotal, q.event_date,'
                . ' c.name AS customer_name'
                . ' FROM quotes q'
                . ' LEFT JOIN customers c ON c.id = q.customer_id'
                . " WHERE q.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
                . " AND q.status != 'cancelled'"
                . ' ORDER BY q.event_date ASC'
            )
            ->fetchAll(\PDO::FETCH_ASSOC);

        return Response::html(
            $this->render('admin/dashboard', [
                'pageTitle'          => 'Dashboard',
                'openQuotes'         => $openQuotes,
                'pendingDeposits'    => $pendingDeposits,
                'customersThisMonth' => $customersThisMonth,
                'totalCustomers'     => $totalCustomers,
                'revenueThisMonth'   => $revenueThisMonth,
                'recentQuotes'       => $recentQuotes,
                'upcomingEvents'     => $upcomingEvents,
            ], 'admin')
        );
    }
}
