<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\StoreClosure;
use App\Support\Closures;
use DateTimeImmutable;

/**
 * Admin controller for the Store Closures calendar section.
 *
 * Renders a month-grid calendar plus a table of existing closures, and
 * handles creating/deleting closure date ranges. All range validation and
 * formatting is delegated to the pure {@see \App\Support\Closures} class;
 * this controller only wires together the request, the data layer, and
 * flash-message feedback, matching the pattern used by
 * {@see \App\Controllers\Admin\FlowerColorsController}.
 *
 * Flash messages are stored in $_SESSION['flash'] so the shared admin layout
 * can display them. Unlike most admin CRUD controllers, create() can also
 * emit a 'warning' flash (in addition to 'success'/'error') when a newly
 * added closure overlaps existing orders.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Support\Closures            Pure overlap/validation/formatting logic.
 * @see \App\Models\StoreClosure         Data-access layer for the store_closures table.
 * @see \App\Controllers\BaseController  Provides render(), redirect(), and closureStrings().
 */
final class ClosuresController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /admin/closures — calendar + list
    // -------------------------------------------------------------------------

    /**
     * Render the store closures calendar page and the existing-closures table.
     *
     * Loads every closure (past, current, and future) so the admin can see
     * the full history on the same page.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/closures/list.
     *
     * @example
     *   (new ClosuresController())->index($request, []);
     */
    public function index(Request $request, array $_params = []): Response
    {
        $closures = StoreClosure::all();
        $months   = $this->closureStrings('en')['months'];

        $closuresJson = json_encode(
            array_map(
                static fn (array $c): array => [
                    'id'         => (int) $c['id'],
                    'start_date' => $c['start_date'],
                    'end_date'   => $c['end_date'],
                    'reason'     => $c['reason'] ?? '',
                ],
                $closures
            ),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );

        return Response::html(
            $this->render('admin/closures/list', [
                'closures'     => $closures,
                'closuresJson' => $closuresJson,
                'todayYmd'     => (new DateTimeImmutable('now'))->format('Y-m-d'),
                'months'       => $months,
                'csrfToken'    => $request->csrfToken(),
                'pageTitle'    => 'Store Closures',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/closures — create
    // -------------------------------------------------------------------------

    /**
     * Create a new store closure.
     *
     * Validates CSRF and the date range (via {@see Closures::validateRange()})
     * before persisting, then re-checks for overlap immediately before the
     * insert to close the time-of-check/time-of-use window between
     * validation and the write. After a successful insert, warns the admin
     * when existing orders fall inside the new closure's range.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/closures.
     *
     * @example
     *   (new ClosuresController())->create($request, []);
     */
    public function create(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/closures');
        }

        $start  = (string) $request->post('start_date', '');
        $end    = (string) $request->post('end_date', '');
        $reason = strip_tags(trim((string) $request->post('reason', '')));

        $months = $this->closureStrings('en')['months'];
        $errors = Closures::validateRange($start, $end, StoreClosure::all(), $months);

        if ($errors !== []) {
            $this->setFlash('error', $errors[0]);
            return $this->redirect('/admin/closures');
        }

        // Re-check immediately before the insert to close the TOCTOU window
        // between validateRange()'s read above and this write.
        if (StoreClosure::overlapping($start, $end) !== []) {
            $this->setFlash('error', 'That range overlaps an existing closure.');
            return $this->redirect('/admin/closures');
        }

        StoreClosure::create([
            'start_date' => $start,
            'end_date'   => $end,
            'reason'     => $reason !== '' ? $reason : null,
        ]);

        $affectedOrders = StoreClosure::countOrdersInRange($start, $end);

        if ($affectedOrders > 0) {
            $this->setFlash(
                'warning',
                sprintf(
                    'Closure added. Heads up: %d existing order%s fall on these dates — you may need to contact those customers.',
                    $affectedOrders,
                    $affectedOrders === 1 ? '' : 's'
                )
            );
        } else {
            $this->setFlash('success', 'Closure added. Customers can no longer book those dates.');
        }

        return $this->redirect('/admin/closures');
    }

    // -------------------------------------------------------------------------
    // POST /admin/closures/{id}/delete — delete
    // -------------------------------------------------------------------------

    /**
     * Permanently delete a store closure.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/closures.
     *
     * @example
     *   (new ClosuresController())->delete($request, ['id' => '3']);
     */
    public function delete(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/closures');
        }

        $id = (int) ($params['id'] ?? 0);
        StoreClosure::delete($id);

        $this->setFlash('success', 'Closure deleted. Customers can book those dates again.');
        return $this->redirect('/admin/closures');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Store a flash message in the session for the next page render.
     *
     * @param 'success'|'warning'|'error' $type    Severity level.
     * @param string                      $message Human-readable message.
     *
     * @return void
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}
