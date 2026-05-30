<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Models\AdminUser;

/**
 * Admin controller for the Admin Users management section.
 *
 * Allows listing, creating, and deleting admin accounts. Self-deletion is
 * prevented — an admin cannot remove their own account. Flash messages are
 * stored in $_SESSION['flash'] and consumed by the list view.
 *
 * No auth checks are performed here — the Router enforces the 'auth'
 * middleware for every /admin/* route before this controller is invoked.
 *
 * @see \App\Models\AdminUser            Provides all database operations.
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 */
final class AdminUsersController extends BaseController
{
    // -------------------------------------------------------------------------
    // GET /admin/admin-users — list
    // -------------------------------------------------------------------------

    /**
     * Render the admin user list page.
     *
     * Fetches all admin accounts and passes the current admin's ID so the view
     * can disable the delete button for the logged-in user's own row.
     *
     * @param Request              $request HTTP request (unused for GET).
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/admin-users/list.
     *
     * @example
     *   (new AdminUsersController())->index($request, []);
     */
    public function index(Request $request, array $params = []): Response
    {
        $admins    = AdminUser::all();
        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/admin-users/list', [
                'admins'    => $admins,
                'csrfToken' => $csrfToken,
                'pageTitle' => 'Admin Users',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // GET /admin/admin-users/new — create form
    // -------------------------------------------------------------------------

    /**
     * Render the blank admin user creation form.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Rendered HTML for admin/admin-users/form.
     *
     * @example
     *   (new AdminUsersController())->newForm($request, []);
     */
    public function newForm(Request $request, array $params = []): Response
    {
        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/admin-users/form', [
                'name'      => '',
                'email'     => '',
                'error'     => '',
                'csrfToken' => $csrfToken,
                'pageTitle' => 'Add Admin User',
            ], 'admin')
        );
    }

    // -------------------------------------------------------------------------
    // POST /admin/admin-users — create
    // -------------------------------------------------------------------------

    /**
     * Handle the admin user creation form submission.
     *
     * Validates the CSRF token, checks all required fields, enforces a minimum
     * password length of 8 characters, confirms the two password fields match,
     * and ensures the email is not already in use. On success, creates the user
     * and redirects to the list page with a flash message. On failure,
     * re-renders the form with an error message and repopulates name/email.
     *
     * @param Request              $request HTTP request containing POST data.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response JSON error on CSRF failure; redirect on success; HTML form on failure.
     *
     * @example
     *   (new AdminUsersController())->create($request, []);
     */
    public function create(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(['error' => 'Invalid CSRF token.'], 403);
        }

        $name            = trim((string) $request->post('name', ''));
        $email           = trim((string) $request->post('email', ''));
        $password        = (string) $request->post('password', '');
        $passwordConfirm = (string) $request->post('password_confirm', '');

        $renderForm = function (string $error) use ($request, $name, $email): Response {
            return Response::html(
                $this->render('admin/admin-users/form', [
                    'name'      => $name,
                    'email'     => $email,
                    'error'     => $error,
                    'csrfToken' => $request->csrfToken(),
                    'pageTitle' => 'Add Admin User',
                ], 'admin')
            );
        };

        if ($name === '') {
            return $renderForm('Full name is required.');
        }

        if ($email === '') {
            return $renderForm('Email address is required.');
        }

        if ($password === '') {
            return $renderForm('Password is required.');
        }

        if (strlen($password) < 8) {
            return $renderForm('Password must be at least 8 characters.');
        }

        if ($password !== $passwordConfirm) {
            return $renderForm('Passwords do not match.');
        }

        if (AdminUser::findByEmail($email) !== null) {
            return $renderForm('An admin with that email address already exists.');
        }

        AdminUser::create($name, $email, $password);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Admin user created successfully.'];
        return $this->redirect('/admin/admin-users');
    }

    // -------------------------------------------------------------------------
    // POST /admin/admin-users/{id}/delete — delete
    // -------------------------------------------------------------------------

    /**
     * Delete an admin user by ID.
     *
     * Validates the CSRF token and prevents self-deletion — an admin cannot
     * remove their own account. On success, sets a flash message and redirects
     * to the list page.
     *
     * @param Request              $request HTTP request.
     * @param array<string,string> $params  Route parameters; expects 'id'.
     *
     * @return Response JSON error on CSRF failure; redirect to /admin/admin-users.
     *
     * @example
     *   (new AdminUsersController())->delete($request, ['id' => '3']);
     */
    public function delete(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(['error' => 'Invalid CSRF token.'], 403);
        }

        $id = (int) ($params['id'] ?? 0);

        if ($id === (int) ($_SESSION['admin_id'] ?? 0)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'You cannot delete your own account.'];
            return $this->redirect('/admin/admin-users');
        }

        AdminUser::delete($id);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Admin user deleted.'];
        return $this->redirect('/admin/admin-users');
    }
}
