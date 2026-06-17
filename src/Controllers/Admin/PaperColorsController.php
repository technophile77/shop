<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\PaperColor;

/**
 * Admin controller for the Paper Colors CRUD section.
 *
 * Renders a combined list + inline "Add Paper Color" form on a single page.
 * The hex field is optional; when provided it must match the pattern
 * #[0-9A-Fa-f]{6} (a six-digit HTML color code).
 *
 * Flash messages are stored in $_SESSION['flash'] as in other admin controllers
 * so the shared admin layout can display them.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 * @see \App\Models\PaperColor           Provides all database read/write operations.
 */
final class PaperColorsController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /admin/paper-colors — list + inline create form
    // -------------------------------------------------------------------------

    /**
     * Render the paper colors list page including the inline Add form.
     *
     * Loads all paper colors (active and inactive) ordered by sort_order then ID.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/paper-colors/list.
     *
     * @example
     *   (new PaperColorsController())->index($request, []);
     */
    public function index(Request $request, array $_params = []): Response
    {
        $paperColors = PaperColor::all();
        $csrfToken   = $request->csrfToken();

        return Response::html(
            $this->render('admin/paper-colors/list', [
                'paperColors' => $paperColors,
                'csrfToken'   => $csrfToken,
                'pageTitle'   => 'Paper Colors',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/paper-colors — create
    // -------------------------------------------------------------------------

    /**
     * Create a new paper color.
     *
     * Validates CSRF, name_en (required), and hex (optional but must match
     * #[0-9A-Fa-f]{6} when provided). On success redirects to the list with
     * a flash success message.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/paper-colors.
     *
     * @example
     *   (new PaperColorsController())->create($request, []);
     */
    public function create(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/paper-colors');
        }

        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));
        $hex    = trim((string) $request->post('hex', ''));

        if ($nameEn === '') {
            $this->setFlash('error', 'Color name (English) is required.');
            return $this->redirect('/admin/paper-colors');
        }

        if ($hex !== '' && !$this->isValidHex($hex)) {
            $this->setFlash('error', 'Hex must be a valid color code, e.g. #C8A06A.');
            return $this->redirect('/admin/paper-colors');
        }

        $sortOrder = (int) $request->post('sort_order', 0);
        $activeRaw = $request->post('active', '0');
        $active    = ($activeRaw === '1' || $activeRaw === 'on') ? 1 : 0;

        PaperColor::create([
            'name_en'    => $nameEn,
            'name_es'    => $nameEs !== '' ? $nameEs : null,
            'hex'        => $hex !== '' ? $hex : null,
            'active'     => $active,
            'sort_order' => $sortOrder,
        ]);

        $this->setFlash('success', 'Paper color created successfully.');
        return $this->redirect('/admin/paper-colors');
    }

    // -------------------------------------------------------------------------
    // POST /admin/paper-colors/{id} — update
    // -------------------------------------------------------------------------

    /**
     * Update an existing paper color's name, hex, sort order, and active status.
     *
     * Validates CSRF and the hex format (when provided) before persisting.
     * Does not 404 on a missing ID — a non-matching WHERE clause updates zero rows.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/paper-colors.
     *
     * @example
     *   (new PaperColorsController())->update($request, ['id' => '2']);
     */
    public function update(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/paper-colors');
        }

        $id     = (int) ($params['id'] ?? 0);
        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));
        $hex    = trim((string) $request->post('hex', ''));

        if ($nameEn === '') {
            $this->setFlash('error', 'Color name (English) is required.');
            return $this->redirect('/admin/paper-colors');
        }

        if ($hex !== '' && !$this->isValidHex($hex)) {
            $this->setFlash('error', 'Hex must be a valid color code, e.g. #C8A06A.');
            return $this->redirect('/admin/paper-colors');
        }

        $sortOrder = (int) $request->post('sort_order', 0);
        $active    = $request->post('active') === '1' ? 1 : 0;

        PaperColor::update($id, [
            'name_en'    => $nameEn,
            'name_es'    => $nameEs !== '' ? $nameEs : null,
            'hex'        => $hex !== '' ? $hex : null,
            'active'     => $active,
            'sort_order' => $sortOrder,
        ]);

        $this->setFlash('success', 'Paper color updated successfully.');
        return $this->redirect('/admin/paper-colors');
    }

    // -------------------------------------------------------------------------
    // POST /admin/paper-colors/{id}/delete — delete
    // -------------------------------------------------------------------------

    /**
     * Permanently delete a paper color.
     *
     * Products whose pictured_paper_color_id references this color will have
     * that field set to NULL automatically by the ON DELETE SET NULL constraint.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/paper-colors.
     *
     * @example
     *   (new PaperColorsController())->delete($request, ['id' => '2']);
     */
    public function delete(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/paper-colors');
        }

        $id = (int) ($params['id'] ?? 0);
        PaperColor::delete($id);

        $this->setFlash('success', 'Paper color deleted.');
        return $this->redirect('/admin/paper-colors');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return true when $hex is a valid six-digit HTML color code starting with #.
     *
     * @param string $hex The value to validate.
     *
     * @return bool
     *
     * @example
     *   $this->isValidHex('#C8A06A'); // true
     *   $this->isValidHex('kraft');   // false
     */
    private function isValidHex(string $hex): bool
    {
        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $hex);
    }

    /**
     * Store a flash message in the session for the next page render.
     *
     * @param 'success'|'error' $type    Severity level.
     * @param string            $message Human-readable message.
     *
     * @return void
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}
