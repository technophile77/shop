<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Matches incoming HTTP requests to registered route definitions and dispatches
 * to the appropriate controller method.
 *
 * Routes are supplied as an array of tuples from config/routes.php:
 *   [HTTP_METHOD, URI_PATTERN, ControllerClass, controllerMethod, ?middleware[]]
 *
 * URI patterns support {param} named placeholders, e.g. '/products/{slug}'.
 * Each placeholder is compiled into a named regex capture group so that
 * matched values are available to the controller as an associative array.
 *
 * Controllers live under the App\Controllers\ namespace. A route class name of
 * 'Admin\DashboardController' resolves to 'App\Controllers\Admin\DashboardController'.
 *
 * Middleware is evaluated before the controller is invoked:
 *   - 'auth' — redirects to /admin/login if $_SESSION['admin_id'] is not set.
 *
 * In production (APP_DEBUG != 'true'), exceptions thrown by controllers are
 * caught and a 500 response is returned. In debug mode the exception propagates.
 *
 * @see \App\Core\Request   Provides method() and path() used for route matching.
 * @see \App\Core\Response  Returned by dispatch() in all code paths.
 *
 * @example
 *   $routes = require __DIR__ . '/config/routes.php';
 *   $router = new Router($routes);
 *   $response = $router->dispatch(Request::fromGlobals());
 *   $response->send();
 */
final class Router
{
    /** Base namespace prepended to all controller class names. */
    private const CONTROLLER_NAMESPACE = 'App\\Controllers\\';

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: string, 4?: list<string>}> $routes
     *        Route definitions from config/routes.php.
     */
    public function __construct(
        private readonly array $routes,
    ) {}

    /**
     * Match the request against registered routes and invoke the controller.
     *
     * Steps:
     *  1. Read method and decoded path from $request.
     *  2. Iterate routes; compile each pattern and test against the path.
     *  3. On a match, resolve and instantiate the controller class.
     *  4. Run middleware checks; redirect or abort as required.
     *  5. Call the controller method, passing URL parameters as the first argument.
     *  6. Return the Response produced by the controller.
     *
     * Returns a 404 response when no route matches or the controller/method is
     * missing. Returns a 500 response when the controller throws in production.
     *
     * @param Request $request The current HTTP request.
     *
     * @return Response The response to send to the client.
     *
     * @example
     *   $response = $router->dispatch($request);
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path   = $this->normalisePath($request->path());

        foreach ($this->routes as $route) {
            [$routeMethod, $pattern, $controllerName, $action] = $route;
            $middleware = $route[4] ?? [];

            if (strtoupper($routeMethod) !== $method) {
                continue;
            }

            $params = $this->matchPattern($pattern, $path);
            if ($params === null) {
                continue;
            }

            // --- Middleware ---
            $middlewareResponse = $this->runMiddleware($middleware);
            if ($middlewareResponse !== null) {
                return $middlewareResponse;
            }

            // --- Resolve controller ---
            $fqcn = self::CONTROLLER_NAMESPACE . $controllerName;

            if (!class_exists($fqcn)) {
                return Response::notFound();
            }

            if (!method_exists($fqcn, $action)) {
                return Response::notFound();
            }

            // --- Invoke controller ---
            try {
                $controller = new $fqcn();
                $result     = $controller->$action($request, $params);

                // Controllers must return a Response; coerce anything else to 200 HTML.
                if ($result instanceof Response) {
                    return $result;
                }

                return Response::html((string) $result);

            } catch (\Throwable $e) {
                return $this->handleException($e);
            }
        }

        return Response::notFound();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Compile a route pattern into a named-capture regex and test it against a path.
     *
     * Converts each {param} placeholder into a named capture group (?P<param>[^/]+).
     * Returns an associative array of matched parameter values on success, or null
     * on mismatch.
     *
     * @param string $pattern Route pattern, e.g. '/products/{slug}'.
     * @param string $path    Normalised request path, e.g. '/products/fresh-roses'.
     *
     * @return array<string, string>|null Named captures on match, null on miss.
     *
     * @example
     *   $params = $this->matchPattern('/quote/{token}/accept', '/quote/abc123/accept');
     *   // ['token' => 'abc123']
     */
    private function matchPattern(string $pattern, string $path): ?array
    {
        // Delegate pattern compilation to compilePattern(), which handles literal
        // quoting and {param} placeholder substitution in the correct order.
        $regex = $this->compilePattern($pattern);

        if (preg_match($regex, $path, $matches) !== 1) {
            return null;
        }

        // Filter out integer-keyed entries that preg_match includes alongside named ones.
        return array_filter(
            $matches,
            static fn ($key) => is_string($key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Compile a route pattern into a full anchored regex string.
     *
     * Escapes all regex-special characters in the literal parts of the pattern,
     * then substitutes each {param} placeholder with a named capture group that
     * matches one or more non-slash characters.
     *
     * @param string $pattern Route pattern, e.g. '/admin/quotes/{id}/status'.
     *
     * @return string Anchored PCRE pattern, e.g. '#^/admin/quotes/(?P<id>[^/]+)/status$#'.
     *
     * @example
     *   $regex = $this->compilePattern('/products/{slug}');
     *   // '#^/products/(?P<slug>[^/]+)$#'
     */
    private function compilePattern(string $pattern): string
    {
        // Split the pattern on placeholder tokens, keeping the delimiters.
        $parts    = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $compiled = '';

        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $m)) {
                $compiled .= '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $compiled .= preg_quote($part, '#');
            }
        }

        return '#^' . $compiled . '$#';
    }

    /**
     * Strip trailing slashes and collapse multiple consecutive slashes.
     *
     * Always returns at least '/'.
     *
     * @param string $path Raw decoded path from Request::path().
     *
     * @return string Normalised path.
     */
    private function normalisePath(string $path): string
    {
        $clean = '/' . ltrim((string) preg_replace('#/+#', '/', $path), '/');

        // Remove trailing slash unless the path is the root.
        if ($clean !== '/') {
            $clean = rtrim($clean, '/');
        }

        return $clean;
    }

    /**
     * Evaluate a list of middleware tags and return a Response if access is denied.
     *
     * Currently supports:
     *   - 'auth' — requires $_SESSION['admin_id'] to be set; redirects to
     *              /admin/login otherwise.
     *
     * @param list<string> $middleware Middleware tags from the route definition.
     *
     * @return Response|null A redirect/error response to short-circuit dispatch, or
     *                       null when all middleware passes.
     */
    private function runMiddleware(array $middleware): ?Response
    {
        if (in_array('auth', $middleware, true)) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                // Session not started — treat as unauthenticated.
                return Response::redirect('/admin/login');
            }

            if (empty($_SESSION['admin_id'])) {
                return Response::redirect('/admin/login');
            }
        }

        return null;
    }

    /**
     * Handle a Throwable thrown during controller dispatch.
     *
     * In debug mode (APP_DEBUG=true) the exception propagates so it can be
     * displayed by PHP's built-in error handler. In production a generic 500
     * response is returned to avoid leaking stack traces.
     *
     * @param \Throwable $e The exception or error thrown by the controller.
     *
     * @return Response A 500 response (only returned in production mode).
     *
     * @throws \Throwable Re-throws the original exception in debug mode.
     */
    private function handleException(\Throwable $e): Response
    {
        if (Config::get('APP_DEBUG', 'false') === 'true') {
            throw $e;
        }

        error_log('[Router] Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        return Response::serverError();
    }
}
