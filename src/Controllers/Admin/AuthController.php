<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Handles admin authentication: login form, login submission, and logout.
 *
 * All routes in this controller bypass the 'auth' middleware — the Router
 * applies auth checks before dispatching, so no redundant session checks are
 * needed here except the early-exit on loginForm() when already logged in.
 *
 * Brute-force mitigation: a 1-second sleep is applied on every failed login
 * attempt before re-rendering the form.
 *
 * @see \App\Controllers\BaseController  Provides render() and redirect().
 * @see \App\Core\Database               ro() for SELECT, rw() for last_login UPDATE.
 */
final class AuthController extends BaseController
{
    /**
     * Show the admin login form.
     *
     * Redirects to /admin immediately when the user is already authenticated,
     * avoiding a redundant login page render.
     *
     * Route: GET /admin/login (no auth middleware)
     *
     * @param Request              $request Current HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Redirect to /admin when already logged in; HTML login form otherwise.
     *
     * @example
     *   // Dispatched by the router for GET /admin/login
     *   $response = (new AuthController())->loginForm($request, []);
     */
    public function loginForm(Request $request, array $params = []): Response
    {
        if (!empty($_SESSION['admin_id'])) {
            return $this->redirect('/admin');
        }

        $csrfToken = $request->csrfToken();

        return Response::html(
            $this->render('admin/login', [
                'csrfToken' => $csrfToken,
                'error'     => '',
                'pageTitle' => 'Admin Login',
            ], 'admin-bare')
        );
    }

    /**
     * Handle the login form POST.
     *
     * Validates the CSRF token, looks up the admin user by email, verifies the
     * bcrypt password hash, then either sets session state and redirects to the
     * dashboard or re-renders the form with an error message.
     *
     * Route: POST /admin/login (no auth middleware)
     *
     * On success:
     *   - Sets $_SESSION['admin_id'], 'admin_name', 'admin_email'.
     *   - Updates admin_users.last_login via the rw() connection.
     *   - Redirects to /admin.
     *
     * On failure:
     *   - Sleeps 1 second to slow brute-force attempts.
     *   - Re-renders the login form with a generic error.
     *
     * @param Request              $request Current HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response JSON error on CSRF failure; redirect on success; HTML form on failure.
     *
     * @example
     *   // Dispatched by the router for POST /admin/login
     *   $response = (new AuthController())->login($request, []);
     */
    public function login(Request $request, array $params = []): Response
    {
        if (!$request->validateCsrf()) {
            return Response::json(['error' => 'Invalid CSRF token.'], 403);
        }

        $email    = (string) $request->post('email', '');
        $password = (string) $request->post('password', '');

        $stmt = Database::ro()->prepare(
            'SELECT id, name, email, password_hash FROM admin_users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($user === false || !password_verify($password, (string) $user['password_hash'])) {
            sleep(1);

            return Response::html(
                $this->render('admin/login', [
                    'csrfToken' => $request->csrfToken(),
                    'error'     => 'Invalid email or password.',
                    'pageTitle' => 'Admin Login',
                ], 'admin-bare')
            );
        }

        $_SESSION['admin_id']    = $user['id'];
        $_SESSION['admin_name']  = $user['name'];
        $_SESSION['admin_email'] = $user['email'];

        $update = Database::rw()->prepare(
            'UPDATE admin_users SET last_login = NOW() WHERE id = ?'
        );
        $update->execute([$user['id']]);

        return $this->redirect('/admin');
    }

    /**
     * Destroy the admin session and redirect to the login page.
     *
     * Clears all session data, destroys the server-side session, and issues a
     * redirect. Safe to call even when no session is active.
     *
     * Route: GET /admin/logout
     *
     * @param Request              $request Current HTTP request.
     * @param array<string,string> $params  Route parameters (none for this route).
     *
     * @return Response Redirect to /admin/login.
     *
     * @example
     *   // Dispatched by the router for GET /admin/logout
     *   $response = (new AuthController())->logout($request, []);
     */
    public function logout(Request $request, array $params = []): Response
    {
        $_SESSION = [];
        session_destroy();

        return $this->redirect('/admin/login');
    }
}
