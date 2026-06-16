<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\Addon;

/**
 * Admin CRUD controller for the add-ons feature.
 *
 * Add-ons are global extras (e.g. ribbon, vase, greeting card) that appear
 * as image+checkbox pairs on the public "Request a Custom Bouquet" form.
 * Each add-on thumbnail is produced by cropping an existing media-library
 * image client-side (Cropper.js) and uploading the result via the standard
 * /admin/media/upload endpoint before the addon form is submitted.
 *
 * Flash messages follow the same session pattern as ProductsController:
 * ['type' => 'success'|'error', 'message' => '…'] in $_SESSION['flash'].
 *
 * Auth is enforced by the Router's 'auth' middleware on every /admin/* route
 * before this controller is invoked.
 *
 * @see \App\Models\Addon
 * @see \App\Controllers\Admin\MediaController  Handles crop-blob upload.
 * @see \App\Controllers\BaseController         Provides render() and redirect().
 */
final class AddonsController extends BaseController
{
    /** Absolute path to the shared product-image upload directory. */
    private const UPLOAD_DIR = __DIR__ . '/../../../public/uploads/products/';

    // -------------------------------------------------------------------------
    // GET /admin/addons
    // -------------------------------------------------------------------------

    /**
     * Render the add-on list page showing all active and inactive add-ons.
     *
     * @param Request              $request HTTP request (unused for GET).
     * @param array<string,string> $params  Route parameters (none).
     * @return Response Rendered HTML.
     *
     * @example
     *   // GET /admin/addons
     */
    public function index(Request $request, array $params = []): Response
    {
        $addons    = Addon::all();
        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/addons/list', [
                'addons'    => $addons,
                'csrfToken' => $csrfToken,
                'pageTitle' => 'Add-Ons',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/addons/new
    // -------------------------------------------------------------------------

    /**
     * Render the blank add-on creation form.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters (none).
     * @return Response Rendered HTML.
     *
     * @example
     *   // GET /admin/addons/new
     */
    public function newForm(Request $request, array $params = []): Response
    {
        return Response::html(
            $this->render('admin/addons/form', [
                'addon'     => [],
                'isNew'     => true,
                'csrfToken' => $request->csrfToken(),
                'pageTitle' => 'New Add-On',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/addons
    // -------------------------------------------------------------------------

    /**
     * Handle the add-on creation form submission.
     *
     * Validates CSRF and required fields, sanitises inputs, validates the
     * image_path basename against UPLOAD_DIR, then delegates to Addon::create().
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $params  Route parameters (none).
     * @return Response Redirect to /admin/addons or /admin/addons/new on error.
     *
     * @example
     *   // POST /admin/addons
     */
    public function create(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/addons/new');
        }

        $data = $this->collectAddonData($request);

        if (empty($data['name_en'])) {
            $this->setFlash('error', 'Add-on name (English) is required.');
            return $this->redirect('/admin/addons/new');
        }

        $data['image_path'] = $this->resolveImagePath($request);

        Addon::create($data);

        $this->setFlash('success', 'Add-on created successfully.');
        return $this->redirect('/admin/addons');
    }

    // -------------------------------------------------------------------------
    // GET /admin/addons/{id}/edit
    // -------------------------------------------------------------------------

    /**
     * Render the add-on edit form pre-populated with existing data.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     * @return Response Rendered HTML, or a 404 response when not found.
     *
     * @example
     *   // GET /admin/addons/3/edit
     */
    public function editForm(Request $request, array $params = []): Response
    {
        $id    = (int) ($params['id'] ?? 0);
        $addon = Addon::find($id);

        if ($addon === null) {
            return Response::notFound();
        }

        return Response::html(
            $this->render('admin/addons/form', [
                'addon'     => $addon,
                'isNew'     => false,
                'csrfToken' => $request->csrfToken(),
                'pageTitle' => 'Edit Add-On',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/addons/{id}
    // -------------------------------------------------------------------------

    /**
     * Handle the add-on update form submission.
     *
     * Applies the same validation as create(). An empty image_path clears the
     * existing image association (sets image_path to null in the DB); a valid
     * basename replaces it; an invalid/missing path retains the existing value
     * by omitting image_path from the update array.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     * @return Response Redirect to /admin/addons.
     *
     * @example
     *   // POST /admin/addons/3
     */
    public function update(Request $request, array $params = []): Response
    {
        $id    = (int) ($params['id'] ?? 0);
        $addon = Addon::find($id);

        if ($addon === null) {
            return Response::notFound();
        }

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect("/admin/addons/{$id}/edit");
        }

        $data = $this->collectAddonData($request);

        if (empty($data['name_en'])) {
            $this->setFlash('error', 'Add-on name (English) is required.');
            return $this->redirect("/admin/addons/{$id}/edit");
        }

        $rawBasename = basename(trim((string) $request->post('image_path', '')));
        if ($rawBasename === '') {
            $data['image_path'] = null;
        } elseif (is_file(self::UPLOAD_DIR . $rawBasename)) {
            $data['image_path'] = $rawBasename;
        }
        // If $rawBasename is non-empty but the file doesn't exist, omit
        // image_path from $data so the existing value is retained.

        Addon::update($id, $data);

        $this->setFlash('success', 'Add-on updated successfully.');
        return $this->redirect('/admin/addons');
    }

    // -------------------------------------------------------------------------
    // POST /admin/addons/{id}/delete
    // -------------------------------------------------------------------------

    /**
     * Soft-delete an add-on by setting its active flag to 0.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     * @return Response Redirect to /admin/addons.
     *
     * @example
     *   // POST /admin/addons/3/delete
     */
    public function delete(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/addons');
        }

        Addon::delete((int) ($params['id'] ?? 0));

        $this->setFlash('success', 'Add-on hidden successfully.');
        return $this->redirect('/admin/addons');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Collect and sanitise add-on fields from the POST body.
     *
     * Does not handle image_path — that is resolved separately so create() and
     * update() can apply different fallback logic.
     *
     * price is parsed as a non-negative float and clamped to 0 when negative.
     * has_custom_text is derived from the checkbox: 1 when the field is present,
     * 0 when absent (unchecked checkboxes are not submitted by browsers).
     *
     * @param Request $request The current HTTP request.
     * @return array<string, mixed> Sanitised field map for Addon::create()/update().
     *
     * @see \App\Support\Shop::normalizePrice()
     */
    private function collectAddonData(Request $request): array
    {
        $str      = fn (string $key): string =>
            strip_tags(trim((string) $request->post($key, '')));
        $nullable = fn (string $key): ?string =>
            ($v = strip_tags(trim((string) $request->post($key, '')))) !== '' ? $v : null;

        $rawPrice = $request->post('price', 0);
        $price    = max(0.0, round((float) $rawPrice, 2));

        return [
            'name_en'         => $str('name_en'),
            'name_es'         => $nullable('name_es'),
            'sort_order'      => (int) $request->post('sort_order', 0),
            'active'          => $request->post('active') !== null ? 1 : 0,
            'price'           => $price,
            'has_custom_text' => $request->post('has_custom_text') !== null ? 1 : 0,
        ];
    }

    /**
     * Resolve the submitted image_path into a validated basename or null.
     *
     * Used by create() where an empty or invalid submission results in null
     * rather than retaining a previous value (there is none).
     *
     * @param Request $request The current HTTP request.
     * @return string|null Validated basename, or null when absent/invalid.
     */
    private function resolveImagePath(Request $request): ?string
    {
        $basename = basename(trim((string) $request->post('image_path', '')));
        if ($basename !== '' && is_file(self::UPLOAD_DIR . $basename)) {
            return $basename;
        }
        return null;
    }

    /**
     * Store a flash message in the session for the next page render.
     *
     * @param 'success'|'error' $type    Severity level.
     * @param string            $message Human-readable message.
     * @return void
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}
