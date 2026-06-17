<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\FlowerColor;

/**
 * Admin controller for the Flower Colors CRUD section.
 *
 * Renders a combined list + inline "Add Flower Color" form on a single page.
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
 * @see \App\Models\FlowerColor          Provides all database read/write operations.
 */
final class FlowerColorsController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /admin/flower-colors — list + inline create form
    // -------------------------------------------------------------------------

    /**
     * Render the flower colors list page including the inline Add form.
     *
     * Loads all flower colors (active and inactive) ordered by sort_order then ID.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/flower-colors/list.
     *
     * @example
     *   (new FlowerColorsController())->index($request, []);
     */
    public function index(Request $request, array $_params = []): Response
    {
        $flowerColors = FlowerColor::all();
        $csrfToken    = $request->csrfToken();

        return Response::html(
            $this->render('admin/flower-colors/list', [
                'flowerColors' => $flowerColors,
                'csrfToken'    => $csrfToken,
                'pageTitle'    => 'Flower Colors',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/flower-colors — create
    // -------------------------------------------------------------------------

    /**
     * Create a new flower color.
     *
     * Validates CSRF, name_en (required), and hex (optional but must match
     * #[0-9A-Fa-f]{6} when provided). On success redirects to the list with
     * a flash success message.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/flower-colors.
     *
     * @example
     *   (new FlowerColorsController())->create($request, []);
     */
    public function create(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/flower-colors');
        }

        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));
        $hex    = trim((string) $request->post('hex', ''));

        if ($nameEn === '') {
            $this->setFlash('error', 'Color name (English) is required.');
            return $this->redirect('/admin/flower-colors');
        }

        if ($hex !== '' && !$this->isValidHex($hex)) {
            $this->setFlash('error', 'Hex must be a valid color code, e.g. #D32F2F.');
            return $this->redirect('/admin/flower-colors');
        }

        $sortOrder = (int) $request->post('sort_order', 0);
        $activeRaw = $request->post('active', '0');
        $active    = ($activeRaw === '1' || $activeRaw === 'on') ? 1 : 0;

        FlowerColor::create([
            'name_en'    => $nameEn,
            'name_es'    => $nameEs !== '' ? $nameEs : null,
            'hex'        => $hex !== '' ? $hex : null,
            'active'     => $active,
            'sort_order' => $sortOrder,
        ]);

        $this->setFlash('success', 'Flower color created successfully.');
        return $this->redirect('/admin/flower-colors');
    }

    // -------------------------------------------------------------------------
    // POST /admin/flower-colors/{id} — update
    // -------------------------------------------------------------------------

    /**
     * Update an existing flower color's name, hex, sort order, and active status.
     *
     * Validates CSRF and the hex format (when provided) before persisting.
     * Does not 404 on a missing ID — a non-matching WHERE clause updates zero rows.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/flower-colors.
     *
     * @example
     *   (new FlowerColorsController())->update($request, ['id' => '2']);
     */
    public function update(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/flower-colors');
        }

        $id     = (int) ($params['id'] ?? 0);
        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));
        $hex    = trim((string) $request->post('hex', ''));

        if ($nameEn === '') {
            $this->setFlash('error', 'Color name (English) is required.');
            return $this->redirect('/admin/flower-colors');
        }

        if ($hex !== '' && !$this->isValidHex($hex)) {
            $this->setFlash('error', 'Hex must be a valid color code, e.g. #D32F2F.');
            return $this->redirect('/admin/flower-colors');
        }

        $sortOrder = (int) $request->post('sort_order', 0);
        $active    = $request->post('active') === '1' ? 1 : 0;

        FlowerColor::update($id, [
            'name_en'    => $nameEn,
            'name_es'    => $nameEs !== '' ? $nameEs : null,
            'hex'        => $hex !== '' ? $hex : null,
            'active'     => $active,
            'sort_order' => $sortOrder,
        ]);

        $this->setFlash('success', 'Flower color updated successfully.');
        return $this->redirect('/admin/flower-colors');
    }

    // -------------------------------------------------------------------------
    // POST /admin/flower-colors/{id}/delete — delete
    // -------------------------------------------------------------------------

    /**
     * Permanently delete a flower color.
     *
     * Associated flower_type_colors and product_flower_type_colors rows are
     * removed automatically by their ON DELETE CASCADE foreign keys.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/flower-colors.
     *
     * @example
     *   (new FlowerColorsController())->delete($request, ['id' => '2']);
     */
    public function delete(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/flower-colors');
        }

        $id = (int) ($params['id'] ?? 0);
        FlowerColor::delete($id);

        $this->setFlash('success', 'Flower color deleted.');
        return $this->redirect('/admin/flower-colors');
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
     *   $this->isValidHex('#D32F2F'); // true
     *   $this->isValidHex('red');     // false
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
