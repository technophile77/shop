<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Product;
use App\Models\ProductCategory;

/**
 * Admin controller for the Products CRUD section.
 *
 * Handles listing, creating, editing, updating, and soft-deleting products.
 * Image uploads are validated (JPEG/PNG/WebP, max 5 MB), given a unique
 * filename, moved to public/uploads/products/, and resized to a max width of
 * 1200 px via the Imagick extension before the database record is saved.
 *
 * Flash messages are stored in $_SESSION['flash'] as
 * ['type' => 'success'|'error', 'message' => '…'] and are read and cleared
 * by the admin/products/list view.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Models\Product          Provides all database read/write operations.
 * @see \App\Models\ProductCategory  Supplies category options for the form select.
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 */
final class ProductsController extends BaseController
{
    /** Absolute path to the product image upload directory. */
    private const UPLOAD_DIR = __DIR__ . '/../../../public/uploads/products/';

    /** Maximum allowed upload size in bytes (5 MB). */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** MIME types accepted for product images. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** Maximum image width after resize (px). Aspect ratio is preserved. */
    private const MAX_WIDTH = 1200;

    // -------------------------------------------------------------------------
    // GET /admin/products — list
    // -------------------------------------------------------------------------

    /**
     * Render the product list page, including inactive products.
     *
     * Runs a direct JOIN query so the category name is available in each row
     * without a secondary lookup. Products are ordered active-first, then by
     * sort_order ascending, then newest-first within the same sort position.
     *
     * @param Request              $request HTTP request (unused for GET).
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/products/list.
     *
     * @example
     *   // Dispatched by GET /admin/products
     *   (new ProductsController())->index($request, []);
     */
    public function index(Request $request, array $_params = []): Response
    {
        $sql = <<<SQL
            SELECT p.*, c.name_en AS category_name
            FROM products p
            JOIN product_categories c ON c.id = p.category_id
            ORDER BY p.active DESC, p.sort_order ASC, p.id DESC
            SQL;

        $stmt     = Database::ro()->prepare($sql);
        $stmt->execute();
        $products = $stmt->fetchAll();

        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/products/list', [
                'products'  => $products,
                'csrfToken' => $csrfToken,
                'pageTitle' => 'Products',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/products/new — create form
    // -------------------------------------------------------------------------

    /**
     * Render the blank product creation form.
     *
     * Loads all active categories for the category select element and generates
     * a fresh CSRF token.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/products/form.
     *
     * @example
     *   (new ProductsController())->newForm($request, []);
     */
    public function newForm(Request $request, array $params = []): Response
    {
        $categories = ProductCategory::allActive();
        $csrfToken  = $request->csrfToken();

        return Response::html(
            $this->render('admin/products/form', [
                'product'    => [],
                'categories' => $categories,
                'isNew'      => true,
                'csrfToken'  => $csrfToken,
                'pageTitle'  => 'New Product',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/products — create
    // -------------------------------------------------------------------------

    /**
     * Handle the product creation form submission.
     *
     * Validates the CSRF token, processes an optional image upload, validates
     * required fields, sanitises all string inputs, and delegates to
     * Product::create(). On success, sets a flash message and redirects to
     * the product list. On validation failure, sets an error flash and redirects
     * back to the create form.
     *
     * @param Request              $request HTTP request containing POST data and
     *                                      an optional multipart file upload under
     *                                      the field name 'image'.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/products or /admin/products/new.
     *
     * @throws \RuntimeException  When the upload directory is not writable.
     * @throws \ImagickException  When Imagick fails to process the image.
     *
     * @example
     *   (new ProductsController())->create($request, []);
     */
    public function create(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/products/new');
        }

        $data = $this->collectProductData($request);

        if (empty($data['name_en'])) {
            $this->setFlash('error', 'Product name (English) is required.');
            return $this->redirect('/admin/products/new');
        }

        if (empty($data['category_id'])) {
            $this->setFlash('error', 'Category is required.');
            return $this->redirect('/admin/products/new');
        }

        $imagePath = $this->handleImageUpload($request);
        if ($imagePath === false) {
            return $this->redirect('/admin/products/new');
        }

        if ($imagePath !== null) {
            $data['image_path'] = $imagePath;
        }

        Product::create($data);

        $this->setFlash('success', 'Product created successfully.');
        return $this->redirect('/admin/products');
    }

    // -------------------------------------------------------------------------
    // GET /admin/products/{id}/edit — edit form
    // -------------------------------------------------------------------------

    /**
     * Render the product edit form pre-populated with the existing product data.
     *
     * Returns a 404 response when no product with the given ID exists.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Rendered HTML for admin/products/form, or 404.
     *
     * @example
     *   (new ProductsController())->editForm($request, ['id' => '42']);
     */
    public function editForm(Request $request, array $params = []): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $product = Product::find($id);

        if ($product === null) {
            return Response::notFound();
        }

        $categories = ProductCategory::allActive();
        $csrfToken  = $request->csrfToken();

        return Response::html(
            $this->render('admin/products/form', [
                'product'    => $product,
                'categories' => $categories,
                'isNew'      => false,
                'csrfToken'  => $csrfToken,
                'pageTitle'  => 'Edit Product',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/products/{id} — update
    // -------------------------------------------------------------------------

    /**
     * Handle the product update form submission.
     *
     * If a new image is uploaded, the old image file is deleted from disk
     * (when the filename differs) before the new path is saved. Validates CSRF,
     * required fields, and sanitises inputs the same way as create().
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/products.
     *
     * @example
     *   (new ProductsController())->update($request, ['id' => '42']);
     */
    public function update(Request $request, array $params = []): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $product = Product::find($id);

        if ($product === null) {
            return Response::notFound();
        }

        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect("/admin/products/{$id}/edit");
        }

        $data = $this->collectProductData($request);

        if (empty($data['name_en'])) {
            $this->setFlash('error', 'Product name (English) is required.');
            return $this->redirect("/admin/products/{$id}/edit");
        }

        if (empty($data['category_id'])) {
            $this->setFlash('error', 'Category is required.');
            return $this->redirect("/admin/products/{$id}/edit");
        }

        $imagePath = $this->handleImageUpload($request);
        if ($imagePath === false) {
            return $this->redirect("/admin/products/{$id}/edit");
        }

        if ($imagePath !== null) {
            // Delete the old image from disk when it differs from the new one.
            $oldPath = $product['image_path'] ?? null;
            if ($oldPath !== null && $oldPath !== $imagePath) {
                $fullOld = self::UPLOAD_DIR . $oldPath;
                if (is_file($fullOld)) {
                    unlink($fullOld);
                }
            }
            $data['image_path'] = $imagePath;
        }

        Product::update($id, $data);

        $this->setFlash('success', 'Product updated successfully.');
        return $this->redirect('/admin/products');
    }

    // -------------------------------------------------------------------------
    // POST /admin/products/{id}/delete — soft-delete
    // -------------------------------------------------------------------------

    /**
     * Soft-delete a product by setting its active flag to 0.
     *
     * The product row is preserved in the database. Validates CSRF before
     * acting.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/products.
     *
     * @example
     *   (new ProductsController())->delete($request, ['id' => '42']);
     */
    public function delete(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/products');
        }

        $id = (int) ($params['id'] ?? 0);
        Product::delete($id);

        $this->setFlash('success', 'Product hidden successfully.');
        return $this->redirect('/admin/products');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Collect, sanitise, and type-coerce product fields from the POST body.
     *
     * All text fields are stripped of leading/trailing whitespace and run
     * through htmlspecialchars_decode so views can re-escape freely. Numeric
     * fields are cast to their appropriate types; checkbox fields default to 0
     * when absent.
     *
     * @param Request $request The HTTP request containing POST data.
     *
     * @return array<string, mixed> Sanitised product field map ready for
     *                              Product::create() or Product::update().
     */
    private function collectProductData(Request $request): array
    {
        $str = fn (string $key): string =>
            strip_tags(trim((string) $request->post($key, '')));

        $nullable = fn (string $key): ?string =>
            ($v = strip_tags(trim((string) $request->post($key, '')))) !== '' ? $v : null;

        $decimal = fn (string $key): ?float =>
            ($v = trim((string) $request->post($key, ''))) !== '' ? (float) $v : null;

        return [
            'category_id'    => (int) $request->post('category_id', 0),
            'name_en'        => $str('name_en'),
            'name_es'        => $nullable('name_es'),
            'description_en' => $nullable('description_en'),
            'description_es' => $nullable('description_es'),
            'price_from'     => $decimal('price_from'),
            'price_to'       => $decimal('price_to'),
            'sort_order'     => (int) $request->post('sort_order', 0),
            'featured'       => $request->post('featured') !== null ? 1 : 0,
            'active'         => $request->post('active') !== null ? 1 : 0,
        ];
    }

    /**
     * Validate and persist an uploaded product image.
     *
     * Validates MIME type (JPEG, PNG, WebP only) and file size (max 5 MB).
     * Generates a collision-resistant filename with uniqid(), moves the temp
     * file to the upload directory, then resizes the image to at most
     * MAX_WIDTH pixels wide while preserving aspect ratio using Imagick.
     *
     * Returns null when no file was uploaded (not an error), the new filename
     * string on success, or false when validation fails (the flash message is
     * set before returning false).
     *
     * @param Request $request The HTTP request; the file field name is 'image'.
     *
     * @return string|false|null
     *   - string  → the saved filename (basename only, no directory).
     *   - null    → no file was uploaded; caller should leave image_path unchanged.
     *   - false   → validation failed; flash error already set; caller should redirect.
     */
    private function handleImageUpload(Request $request): string|false|null
    {
        $file = $request->file('image');

        if ($file === null) {
            return null;
        }

        // Validate upload error code.
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->setFlash('error', 'Image upload failed (error code ' . $file['error'] . ').');
            return false;
        }

        // Validate file size.
        if ($file['size'] > self::MAX_BYTES) {
            $this->setFlash('error', 'Image must be smaller than 5 MB.');
            return false;
        }

        // Validate MIME type using the actual file content, not the browser header.
        $mime = mime_content_type($file['tmp_name']);
        if ($mime === false || !in_array($mime, self::ALLOWED_MIME, true)) {
            $this->setFlash('error', 'Only JPEG, PNG, and WebP images are allowed.');
            return false;
        }

        // Derive a safe extension from the validated MIME type.
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        };

        $filename = uniqid('product_', true) . '.' . $ext;
        $dest     = self::UPLOAD_DIR . $filename;

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->setFlash('error', 'Failed to save the uploaded image.');
            return false;
        }

        // Resize to MAX_WIDTH if wider, preserving aspect ratio.
        $this->resizeImage($dest);

        return $filename;
    }

    /**
     * Resize an image file in-place so its width does not exceed MAX_WIDTH.
     *
     * Uses the Imagick extension when available. Falls back silently when
     * Imagick is not installed — the unresized image is still usable.
     * The file is written back to the same path after resizing.
     *
     * @param string $path Absolute filesystem path to the image file.
     *
     * @return void
     *
     * @see self::MAX_WIDTH
     */
    private function resizeImage(string $path): void
    {
        if (!class_exists(\Imagick::class)) {
            return;
        }

        try {
            $img = new \Imagick($path);

            if ($img->getImageWidth() > self::MAX_WIDTH) {
                $img->resizeImage(self::MAX_WIDTH, 0, \Imagick::FILTER_LANCZOS, 1);
            }

            $img->writeImage($path);
            $img->clear();
            $img->destroy();
        } catch (\ImagickException) {
            // Non-fatal — log and continue with the unresized file.
            error_log("[ProductsController] Imagick resize failed for {$path}");
        }
    }

    /**
     * Store a flash message in the session for the next page render.
     *
     * The admin layout / list view reads $_SESSION['flash'] and clears it
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
