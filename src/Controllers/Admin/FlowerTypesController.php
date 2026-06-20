<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\FlowerColor;
use App\Models\FlowerType;
use App\Models\FlowerTypeColor;
use App\Support\FlowerColorResolver;

/**
 * Admin controller for the Flower Types CRUD section.
 *
 * Renders a combined list + inline "Add Flower Type" form on a single page,
 * with a per-type color matrix that lets admins assign which colors each flower
 * type comes in. An additional route (POST /admin/flower-types/{id}/colors)
 * handles the color matrix form submission.
 *
 * Flash messages are stored in $_SESSION['flash'] as in other admin controllers
 * so the shared admin layout can display them.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Controllers\BaseController   Provides render() and redirect().
 * @see \App\Models\FlowerType            Provides database read/write for flower types.
 * @see \App\Models\FlowerTypeColor       Provides color matrix read/write.
 * @see \App\Support\FlowerColorResolver  Provides normalizeIdList() for POST data sanitisation.
 */
final class FlowerTypesController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /admin/flower-types — list + inline create form
    // -------------------------------------------------------------------------

    /**
     * Render the flower types list page.
     *
     * Loads all flower types, all active flower colors, the full type-to-color
     * map, and the per-cell stock map so the view can render the color matrix
     * (including per-color stock-count inputs) for each type without issuing
     * additional queries.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/flower-types/list.
     *
     * @example
     *   (new FlowerTypesController())->index($request, []);
     */
    public function index(Request $request, array $_params = []): Response
    {
        $csrfToken   = $request->csrfToken();
        $flowerTypes = FlowerType::all();
        $flowerColors = FlowerColor::allActive();
        $colorMap    = FlowerTypeColor::map();
        $stockMap    = FlowerTypeColor::stockMap();

        return Response::html(
            $this->render('admin/flower-types/list', [
                'flowerTypes'  => $flowerTypes,
                'flowerColors' => $flowerColors,
                'colorMap'     => $colorMap,
                'stockMap'     => $stockMap,
                'csrfToken'    => $csrfToken,
                'pageTitle'    => 'Flower Types',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/flower-types — create
    // -------------------------------------------------------------------------

    /**
     * Create a new flower type.
     *
     * Validates CSRF and that name_en is provided. On success redirects to
     * the flower types list with a flash success message.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/flower-types.
     *
     * @example
     *   (new FlowerTypesController())->create($request, []);
     */
    public function create(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/flower-types');
        }

        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));

        if ($nameEn === '') {
            $this->setFlash('error', 'Flower type name (English) is required.');
            return $this->redirect('/admin/flower-types');
        }

        $sortOrder = (int) $request->post('sort_order', 0);
        $activeRaw = $request->post('active', '0');
        $active    = ($activeRaw === '1' || $activeRaw === 'on') ? 1 : 0;

        FlowerType::create([
            'name_en'    => $nameEn,
            'name_es'    => $nameEs !== '' ? $nameEs : null,
            'active'     => $active,
            'sort_order' => $sortOrder,
        ]);

        $this->setFlash('success', 'Flower type created successfully.');
        return $this->redirect('/admin/flower-types');
    }

    // -------------------------------------------------------------------------
    // POST /admin/flower-types/{id} — update
    // -------------------------------------------------------------------------

    /**
     * Update an existing flower type's name, sort order, and active status.
     *
     * Validates CSRF before persisting. Does not 404 on a missing ID — a
     * non-matching WHERE clause simply updates zero rows.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/flower-types.
     *
     * @example
     *   (new FlowerTypesController())->update($request, ['id' => '2']);
     */
    public function update(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/flower-types');
        }

        $id     = (int) ($params['id'] ?? 0);
        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));

        if ($nameEn === '') {
            $this->setFlash('error', 'Flower type name (English) is required.');
            return $this->redirect('/admin/flower-types');
        }

        $sortOrder = (int) $request->post('sort_order', 0);
        $active    = $request->post('active') === '1' ? 1 : 0;

        FlowerType::update($id, [
            'name_en'    => $nameEn,
            'name_es'    => $nameEs !== '' ? $nameEs : null,
            'active'     => $active,
            'sort_order' => $sortOrder,
        ]);

        $this->setFlash('success', 'Flower type updated successfully.');
        return $this->redirect('/admin/flower-types');
    }

    // -------------------------------------------------------------------------
    // POST /admin/flower-types/{id}/delete — delete
    // -------------------------------------------------------------------------

    /**
     * Permanently delete a flower type.
     *
     * Associated product_flower_types and flower_type_colors rows are removed
     * automatically by ON DELETE CASCADE foreign key constraints.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/flower-types.
     *
     * @example
     *   (new FlowerTypesController())->delete($request, ['id' => '2']);
     */
    public function delete(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/flower-types');
        }

        $id = (int) ($params['id'] ?? 0);
        FlowerType::delete($id);

        $this->setFlash('success', 'Flower type deleted.');
        return $this->redirect('/admin/flower-types');
    }

    // -------------------------------------------------------------------------
    // POST /admin/flower-types/{id}/colors — update color matrix
    // -------------------------------------------------------------------------

    /**
     * Update the color matrix and per-color stock counts for a flower type.
     *
     * Accepts an optional color_ids[] checkbox array from POST, normalises the
     * values via FlowerColorResolver::normalizeIdList(), then reads the optional
     * stock[] map (shape colorId => string) to build a per-color stock count for
     * each checked color. For each checked color, stock[colorId] is interpreted
     * as: unset or empty/whitespace-only string → null (untracked, treated as
     * unlimited); otherwise max(0, (int) value) stems on hand. Only checked
     * colors contribute to the stock map — stock values for unchecked colors are
     * ignored. The result is passed to FlowerTypeColor::setColorStockForType()
     * using replace semantics.
     *
     * Submitting with no color_ids[] checked clears all color associations for
     * the type.
     *
     * @param Request              $request HTTP request; expects color_ids[] and an
     *                                      optional stock[] map (colorId => string)
     *                                      in POST.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/flower-types.
     *
     * @example
     *   // POST color_ids[]=1&color_ids[]=2&stock[1]=24&stock[2]=
     *   (new FlowerTypesController())->updateColors($request, ['id' => '1']);
     *   // Color 1 → 24 stems; Color 2 → null (untracked).
     */
    public function updateColors(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/flower-types');
        }

        $id       = (int) ($params['id'] ?? 0);
        $colorIds = FlowerColorResolver::normalizeIdList(
            (array) $request->post('color_ids', [])
        );

        $stockInput      = (array) $request->post('stock', []);
        $countsByColorId = [];
        foreach ($colorIds as $colorId) {
            $raw = $stockInput[$colorId] ?? null;
            $countsByColorId[$colorId] =
                ($raw === null || trim((string) $raw) === '')
                    ? null
                    : max(0, (int) $raw);
        }

        FlowerTypeColor::setColorStockForType($id, $countsByColorId);

        $this->setFlash('success', 'Color matrix updated.');
        return $this->redirect('/admin/flower-types');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
