<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Admin controller for the Categories CRUD section.
 *
 * The index action renders both the category list and an inline "Add Category"
 * form on a single page, so no separate create-form route is needed.
 *
 * Slugs must contain only lowercase alphanumeric characters and hyphens.
 * Deleting a category that still has products attached is blocked.
 *
 * Flash messages are stored in $_SESSION['flash'] exactly as in
 * ProductsController so the shared admin layout can display them.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 * @see \App\Core\Database               Direct queries for categories with product counts.
 */
final class CategoriesController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /admin/categories — list + inline create form
    // -------------------------------------------------------------------------

    /**
     * Render the category list page including product counts per category.
     *
     * Loads all categories (active and inactive) ordered by sort_order then
     * ID, and attaches the number of products in each category via a subquery
     * so the view can warn before deletion.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/categories/list.
     *
     * @example
     *   (new CategoriesController())->index($request, []);
     */
    public function index(Request $request, array $_params = []): Response
    {
        $sql = <<<SQL
            SELECT c.*,
                   COUNT(p.id) AS product_count
            FROM product_categories c
            LEFT JOIN products p ON p.category_id = c.id
            GROUP BY c.id
            ORDER BY c.sort_order ASC, c.id ASC
            SQL;

        $stmt       = Database::ro()->prepare($sql);
        $stmt->execute();
        $categories = $stmt->fetchAll();

        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/categories/list', [
                'categories' => $categories,
                'csrfToken'  => $csrfToken,
                'pageTitle'  => 'Categories',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/categories — create
    // -------------------------------------------------------------------------

    /**
     * Create a new product category.
     *
     * Validates the CSRF token, name_en (required), and slug (required,
     * lowercase alphanumeric + hyphens only). On success, redirects to the
     * category list with a flash success message.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/categories.
     *
     * @example
     *   (new CategoriesController())->create($request, []);
     */
    public function create(Request $request, array $_params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/categories');
        }

        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));
        $slug   = trim((string) $request->post('slug', ''));

        if ($nameEn === '') {
            $this->setFlash('error', 'Category name (English) is required.');
            return $this->redirect('/admin/categories');
        }

        if (!$this->isValidSlug($slug)) {
            $this->setFlash('error', 'Slug must contain only lowercase letters, numbers, and hyphens.');
            return $this->redirect('/admin/categories');
        }

        $sortOrder  = (int) $request->post('sort_order', 0);
        $activeRaw  = $request->post('active', '0');
        // Accept '1' (from the Alpine hidden field) or any truthy checkbox value.
        $active     = ($activeRaw === '1' || $activeRaw === 'on') ? 1 : 0;

        $db   = Database::rw();
        $stmt = $db->prepare(
            'INSERT INTO product_categories (name_en, name_es, slug, sort_order, active)
             VALUES (:name_en, :name_es, :slug, :sort_order, :active)'
        );

        $stmt->execute([
            ':name_en'    => $nameEn,
            ':name_es'    => $nameEs !== '' ? $nameEs : null,
            ':slug'       => $slug,
            ':sort_order' => $sortOrder,
            ':active'     => $active,
        ]);

        $this->setFlash('success', 'Category created successfully.');
        return $this->redirect('/admin/categories');
    }

    // -------------------------------------------------------------------------
    // POST /admin/categories/{id} — update
    // -------------------------------------------------------------------------

    /**
     * Update an existing category's name, slug, sort order, and active status.
     *
     * Validates CSRF and the slug format before persisting. Does not 404 on a
     * missing ID — a non-matching WHERE clause simply updates zero rows.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/categories.
     *
     * @example
     *   (new CategoriesController())->update($request, ['id' => '3']);
     */
    public function update(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/categories');
        }

        $id     = (int) ($params['id'] ?? 0);
        $nameEn = strip_tags(trim((string) $request->post('name_en', '')));
        $nameEs = strip_tags(trim((string) $request->post('name_es', '')));
        $slug   = trim((string) $request->post('slug', ''));

        if ($nameEn === '') {
            $this->setFlash('error', 'Category name (English) is required.');
            return $this->redirect('/admin/categories');
        }

        if (!$this->isValidSlug($slug)) {
            $this->setFlash('error', 'Slug must contain only lowercase letters, numbers, and hyphens.');
            return $this->redirect('/admin/categories');
        }

        $sortOrder = (int) $request->post('sort_order', 0);
        $active    = $request->post('active') === '1' ? 1 : 0;

        $stmt = Database::rw()->prepare(
            'UPDATE product_categories
             SET name_en = :name_en, name_es = :name_es, slug = :slug,
                 sort_order = :sort_order, active = :active
             WHERE id = :id'
        );

        $stmt->execute([
            ':name_en'    => $nameEn,
            ':name_es'    => $nameEs !== '' ? $nameEs : null,
            ':slug'       => $slug,
            ':sort_order' => $sortOrder,
            ':active'     => $active,
            ':id'         => $id,
        ]);

        $this->setFlash('success', 'Category updated successfully.');
        return $this->redirect('/admin/categories');
    }

    // -------------------------------------------------------------------------
    // POST /admin/categories/{id}/delete — delete
    // -------------------------------------------------------------------------

    /**
     * Permanently delete a category, provided no products reference it.
     *
     * Checks for referencing products before deleting. If any products belong
     * to this category, the delete is refused and an error flash is set instead.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response Redirect to /admin/categories.
     *
     * @example
     *   (new CategoriesController())->delete($request, ['id' => '3']);
     */
    public function delete(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect('/admin/categories');
        }

        $id = (int) ($params['id'] ?? 0);

        // Block deletion when products still reference this category.
        $countStmt = Database::ro()->prepare(
            'SELECT COUNT(*) FROM products WHERE category_id = ?'
        );
        $countStmt->execute([$id]);
        $count = (int) $countStmt->fetchColumn();

        if ($count > 0) {
            $this->setFlash(
                'error',
                "Cannot delete: {$count} product(s) still belong to this category. "
                . 'Re-assign or delete those products first.'
            );
            return $this->redirect('/admin/categories');
        }

        Database::rw()
            ->prepare('DELETE FROM product_categories WHERE id = ?')
            ->execute([$id]);

        $this->setFlash('success', 'Category deleted.');
        return $this->redirect('/admin/categories');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return true when $slug contains only lowercase letters, digits, and hyphens
     * and is not empty.
     *
     * @param string $slug The value to validate.
     *
     * @return bool
     *
     * @example
     *   $this->isValidSlug('fresh-bouquets'); // true
     *   $this->isValidSlug('Fresh Bouquets'); // false
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
