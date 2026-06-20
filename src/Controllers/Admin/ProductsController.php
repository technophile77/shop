<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFlowerType;
use App\Models\ProductFlowerTypeColor;
use App\Models\ProductOccasion;
use App\Models\FlowerColor;
use App\Models\FlowerType;
use App\Models\FlowerTypeColor;
use App\Models\PaperColor;
use App\Support\FlowerColorResolver;

/**
 * Admin controller for the Products CRUD section.
 *
 * Handles listing, creating, editing, updating, and soft-deleting products.
 * Product images are selected from the media library; upload and resize are
 * handled by MediaController.
 *
 * Flash messages are stored in $_SESSION['flash'] as
 * ['type' => 'success'|'error', 'message' => '…'] and are read and cleared
 * by the admin/products/list view.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Models\Product                      Provides all database read/write operations.
 * @see \App\Models\ProductCategory              Supplies category options for the form select.
 * @see \App\Controllers\BaseController          Provides render() and redirect().
 * @see \App\Controllers\Admin\MediaController   Handles image upload and resize.
 */
final class ProductsController extends BaseController
{
    /** Absolute path to the product image upload directory. */
    private const UPLOAD_DIR = __DIR__ . '/../../../public/uploads/products/';

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
                'product'               => [],
                'categories'            => $categories,
                'isNew'                 => true,
                'csrfToken'             => $csrfToken,
                'pageTitle'             => 'New Product',
                'occasions'             => Occasion::allActive(),
                'flowerTypes'           => FlowerType::allActive(),
                'flowerColors'          => FlowerColor::allActive(),
                'paperColors'           => PaperColor::allActive(),
                'selectedOccasionIds'   => [],
                'selectedFlowerTypeIds' => [],
                'flowerTypeColorOptions' => $this->flowerTypeColorOptions(),
                'picturedColorsByType'  => [],
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/products — create
    // -------------------------------------------------------------------------

    /**
     * Handle the product creation form submission.
     *
     * Validates the CSRF token, validates required fields, sanitises all string
     * inputs, and delegates to Product::create(). If the POST field 'image_path'
     * contains a basename that resolves to an existing file in the upload
     * directory, that filename is stored as the product's image. On success,
     * sets a flash message and redirects to the product list. On validation
     * failure, sets an error flash and redirects back to the create form.
     *
     * Side effect: after the product is persisted, best-effort syncs it to Google
     * Merchant Center via {@see syncToMerchant()} (no-op until configured; never
     * blocks the save).
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/products or /admin/products/new.
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

        $imagePath = basename(trim((string) $request->post('image_path', '')));
        if ($imagePath !== '' && is_file(self::UPLOAD_DIR . $imagePath)) {
            $data['image_path'] = $imagePath;
        }

        $newId         = Product::create($data);
        $occasionIds   = FlowerColorResolver::normalizeIdList((array) $request->post('occasion_ids', []));
        $flowerTypeIds = FlowerColorResolver::normalizeIdList((array) $request->post('flower_type_ids', []));
        ProductOccasion::setForProduct($newId, $occasionIds);
        ProductFlowerType::setForProduct($newId, $flowerTypeIds);
        ProductFlowerTypeColor::setForProduct($newId, $this->collectPicturedColors($request, $flowerTypeIds));

        $this->syncToMerchant($newId);

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
                'product'               => $product,
                'categories'            => $categories,
                'isNew'                 => false,
                'csrfToken'             => $csrfToken,
                'pageTitle'             => 'Edit Product',
                'occasions'             => Occasion::allActive(),
                'flowerTypes'           => FlowerType::allActive(),
                'flowerColors'          => FlowerColor::allActive(),
                'paperColors'           => PaperColor::allActive(),
                'selectedOccasionIds'   => ProductOccasion::occasionIdsForProduct($id),
                'selectedFlowerTypeIds' => ProductFlowerType::flowerTypeIdsForProduct($id),
                'flowerTypeColorOptions' => $this->flowerTypeColorOptions(),
                'picturedColorsByType'  => ProductFlowerTypeColor::mapForProduct($id),
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/products/{id} — update
    // -------------------------------------------------------------------------

    /**
     * Handle the product update form submission.
     *
     * Validates CSRF, required fields, and sanitises inputs the same way as
     * create(). If 'image_path' is a non-empty basename pointing to an existing
     * file in the upload directory, it is saved as the product's image. If
     * 'image_path' is an empty string, image_path is cleared to null (removes
     * the image association without deleting the file from disk).
     *
     * Side effect: after the update is persisted, best-effort syncs the product to
     * Google Merchant Center via {@see syncToMerchant()} (no-op until configured;
     * never blocks the save).
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

        $imagePath = basename(trim((string) $request->post('image_path', '')));
        if ($imagePath !== '' && is_file(self::UPLOAD_DIR . $imagePath)) {
            $data['image_path'] = $imagePath;
        } elseif ($imagePath === '') {
            $data['image_path'] = null;
        }

        Product::update($id, $data);
        $occasionIds   = FlowerColorResolver::normalizeIdList((array) $request->post('occasion_ids', []));
        $flowerTypeIds = FlowerColorResolver::normalizeIdList((array) $request->post('flower_type_ids', []));
        ProductOccasion::setForProduct($id, $occasionIds);
        ProductFlowerType::setForProduct($id, $flowerTypeIds);
        ProductFlowerTypeColor::setForProduct($id, $this->collectPicturedColors($request, $flowerTypeIds));

        $this->syncToMerchant($id);

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
     * Side effect: because the soft-delete makes the product ineligible, the
     * follow-up best-effort {@see syncToMerchant()} removes it from the Google
     * Merchant feed in both languages (no-op until configured; never blocks the
     * delete).
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

        $this->syncToMerchant($id);

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
            'category_id'               => (int) $request->post('category_id', 0),
            'name_en'                   => $str('name_en'),
            'name_es'                   => $nullable('name_es'),
            'description_en'            => $nullable('description_en'),
            'description_es'            => $nullable('description_es'),
            'price_from'                => $decimal('price_from'),
            'price_to'                  => $decimal('price_to'),
            'sort_order'                => (int) $request->post('sort_order', 0),
            'featured'                  => $request->post('featured') !== null ? 1 : 0,
            'active'                    => $request->post('active') !== null ? 1 : 0,
            'flower_count'              => ($v = trim((string) $request->post('flower_count', ''))) !== '' ? (int) $v : null,
            'pictured_paper_color_id'   => ($v = trim((string) $request->post('pictured_paper_color_id', ''))) !== '' ? (int) $v : null,
        ];
    }

    /**
     * Build the per-flower-type available-color options for the product form.
     *
     * Returns, for each flower type, the full color rows it is available in
     * (from the flower_type_colors matrix), so the form can render per-type
     * pictured-color checkboxes.
     *
     * @return array<int, array<int, array<string, mixed>>> Map of flower_type_id => list of color rows.
     */
    private function flowerTypeColorOptions(): array
    {
        $colorsById = [];
        foreach (FlowerColor::allActive() as $color) {
            $colorsById[(int) $color['id']] = $color;
        }

        $options = [];
        foreach (FlowerTypeColor::map() as $typeId => $colorIds) {
            $rows = [];
            foreach ((array) $colorIds as $cid) {
                if (isset($colorsById[(int) $cid])) {
                    $rows[] = $colorsById[(int) $cid];
                }
            }
            $options[(int) $typeId] = $rows;
        }

        return $options;
    }

    /**
     * Collect the submitted per-flower-type pictured colors, keeping only types
     * the product actually contains.
     *
     * @param Request $request          The HTTP request (reads pictured_colors[typeId][]).
     * @param int[]   $selectedTypeIds  Flower type IDs the product was tagged with.
     *
     * @return array<int, int[]> Map of flower_type_id => list of flower_color_id.
     */
    private function collectPicturedColors(Request $request, array $selectedTypeIds): array
    {
        $raw = $request->post('pictured_colors', []);
        $raw = is_array($raw) ? $raw : [];

        $out = [];
        foreach ($raw as $typeId => $colorIds) {
            $typeId = (int) $typeId;
            if (!in_array($typeId, $selectedTypeIds, true)) {
                continue;
            }
            $ids = FlowerColorResolver::normalizeIdList(is_array($colorIds) ? $colorIds : []);
            if ($ids !== []) {
                $out[$typeId] = $ids;
            }
        }

        return $out;
    }

    /**
     * Best-effort: push a product's current state to Google Merchant Center for
     * both languages. Eligible (active + priced) → upsert; otherwise → delete so
     * it leaves the feed. Never throws — a Merchant/API failure must not block the
     * admin save (mirrors the owner-email/SMS best-effort pattern).
     *
     * When {@see \App\Services\GoogleMerchantService::isConfigured()} is false this
     * returns immediately, so the wiring stays dormant until credentials exist.
     *
     * @param int $productId The product whose feed entries should be reconciled.
     *
     * @return void
     *
     * @see \App\Services\GoogleMerchantService Owns the Merchant API transport.
     * @see \App\Support\MerchantProduct        Decides feed eligibility.
     */
    private function syncToMerchant(int $productId): void
    {
        if (!\App\Services\GoogleMerchantService::isConfigured()) {
            return;
        }

        try {
            $product = \App\Models\Product::find($productId);
            if ($product === null) {
                return;
            }

            foreach (['en', 'es'] as $lang) {
                if (\App\Support\MerchantProduct::eligible($product)) {
                    \App\Services\GoogleMerchantService::upsert($product, $lang);
                } else {
                    \App\Services\GoogleMerchantService::delete('prod-' . $productId, $lang);
                }
            }
        } catch (\Throwable $e) {
            error_log('[ProductsController] Merchant sync failed: ' . $e->getMessage());
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
