<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\Occasion;

/**
 * Admin controller for the Occasions CRUD section.
 *
 * Renders a combined list + inline "Add Occasion" form on a single page so no
 * separate create-form route is needed. Slugs must contain only lowercase
 * alphanumeric characters and hyphens. Deleting an occasion is a hard delete —
 * the ON DELETE CASCADE constraint on product_occasions removes join-table rows
 * automatically.
 *
 * Flash messages are stored in $_SESSION['flash'] exactly as in
 * CategoriesController so the shared admin layout can display them.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 * @see \App\Models\Occasion             Provides all database read/write operations.
 */
final class OccasionsController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /admin/occasions — list + inline create form
    // -------------------------------------------------------------------------

    /**
     * Render the occasions list page including the inline Add Occasion form.
     *
     * Loads all occasions (active and inactive) ordered by sort_order then ID.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/occasions/list.
     *
     * @example
     *   (new OccasionsController())->index($request, []);
     */
    public function index(Request $request, array $_params = []): Response
    {
        $occasions = Occasion::all();
        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/occasions/list', [
                'occasions' => $occasions,
                'csrfToken' => $csrfToken,
                'pageTitle' => 'Occasions',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/occasions — create
    // -------------------------------------------------------------------------

    /**
     * Create a new occasion.
     *
     * Validates the CSRF token, name_en (required), and slug (required,
     * lowercase alphanumeric + hyphens only). On success redirects to the
     * occasions list with a flash success message.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $_params Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/occasions.
     *
     * @example
     *   (new OccasionsController())->create($request, []);
     */
    public function create(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/occasions');
        }

        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));
        $slug   = trim((string) $request->post('slug', ''));

        if ($nameEn === '') {
            $this->setFlash('error', 'Occasion name (English) is required.');
            return $this->redirect('/admin/occasions');
        }

        if (!$this->isValidSlug($slug)) {
            $this->setFlash('error', 'Slug must contain only lowercase letters, numbers, and hyphens.');
            return $this->redirect('/admin/occasions');
        }

        $sortOrder = (int) $request->post('sort_order', 0);
        $activeRaw = $request->post('active', '0');
        $active    = ($activeRaw === '1' || $activeRaw === 'on') ? 1 : 0;

        Occasion::create([
            'slug'       => $slug,
            'name_en'    => $nameEn,
            'name_es'    => $nameEs !== '' ? $nameEs : null,
            'active'     => $active,
            'sort_order' => $sortOrder,
        ] + $this->collectCopy($request));

        $this->setFlash('success', 'Occasion created successfully.');
        return $this->redirect('/admin/occasions');
    }

    // -------------------------------------------------------------------------
    // POST /admin/occasions/{id} — update
    // -------------------------------------------------------------------------

    /**
     * Update an existing occasion's name, slug, sort order, and active status.
     *
     * Validates CSRF and the slug format before persisting. Does not 404 on a
     * missing ID — a non-matching WHERE clause simply updates zero rows.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/occasions.
     *
     * @example
     *   (new OccasionsController())->update($request, ['id' => '3']);
     */
    public function update(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/occasions');
        }

        $id     = (int) ($params['id'] ?? 0);
        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));
        $slug   = trim((string) $request->post('slug', ''));

        if ($nameEn === '') {
            $this->setFlash('error', 'Occasion name (English) is required.');
            return $this->redirect('/admin/occasions');
        }

        if (!$this->isValidSlug($slug)) {
            $this->setFlash('error', 'Slug must contain only lowercase letters, numbers, and hyphens.');
            return $this->redirect('/admin/occasions');
        }

        $sortOrder = (int) $request->post('sort_order', 0);
        $active    = $request->post('active') === '1' ? 1 : 0;

        Occasion::update($id, [
            'slug'       => $slug,
            'name_en'    => $nameEn,
            'name_es'    => $nameEs !== '' ? $nameEs : null,
            'active'     => $active,
            'sort_order' => $sortOrder,
        ] + $this->collectCopy($request));

        $this->setFlash('success', 'Occasion updated successfully.');
        return $this->redirect('/admin/occasions');
    }

    // -------------------------------------------------------------------------
    // POST /admin/occasions/{id}/delete — delete
    // -------------------------------------------------------------------------

    /**
     * Permanently delete an occasion.
     *
     * Products linked via product_occasions are unlinked automatically by the
     * ON DELETE CASCADE foreign key — no product-count check is needed.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/occasions.
     *
     * @example
     *   (new OccasionsController())->delete($request, ['id' => '3']);
     */
    public function delete(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/occasions');
        }

        $id = (int) ($params['id'] ?? 0);
        Occasion::delete($id);

        $this->setFlash('success', 'Occasion deleted.');
        return $this->redirect('/admin/occasions');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Collect the optional per-occasion display copy (heading + blurb, EN/ES).
     *
     * Empty fields are passed through as '' and stored as NULL by the model,
     * so the occasion page falls back to its name + a generic blurb.
     *
     * @param Request $request The HTTP request containing POST data.
     *
     * @return array{heading_en:string, heading_es:string, blurb_en:string, blurb_es:string}
     */
    private function collectCopy(Request $request): array
    {
        $clean = static fn (string $key): string =>
            strip_tags(trim((string) $request->post($key, '')));

        return [
            'heading_en' => $clean('heading_en'),
            'heading_es' => $clean('heading_es'),
            'blurb_en'   => $clean('blurb_en'),
            'blurb_es'   => $clean('blurb_es'),
        ];
    }

    /**
     * Return true when $slug contains only lowercase letters, digits, and hyphens
     * and is not empty.
     *
     * @param string $slug The value to validate.
     *
     * @return bool
     *
     * @example
     *   $this->isValidSlug('get-well');  // true
     *   $this->isValidSlug('Get Well');  // false
     */
    private function isValidSlug(string $slug): bool
    {
        return $slug !== '' && (bool) preg_match('/^[a-z0-9-]+$/', $slug);
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
