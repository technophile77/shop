<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Wraps PHP superglobals and provides a clean, sanitised request interface.
 *
 * All scalar values read through this class are trimmed of surrounding
 * whitespace. The class does not modify the underlying superglobals.
 *
 * CSRF protection is provided via {@see csrfToken()} and {@see validateCsrf()}.
 * A token is generated once per session and stored under the '_csrf' session key.
 *
 * @example
 *   $req = new Request();
 *   $slug = $req->query('slug', 'roses');
 *   $name = $req->post('customer_name');
 */
final class Request
{
    /**
     * Normalise and store a copy of the relevant superglobals.
     *
     * We copy at construction time so that later modifications to $_GET/$_POST
     * during the request lifecycle do not affect this object.
     *
     * @param array<string, mixed>        $get     Defaults to $_GET.
     * @param array<string, mixed>        $post    Defaults to $_POST.
     * @param array<string, string>       $server  Defaults to $_SERVER.
     * @param array<string, array<mixed>> $files   Defaults to $_FILES.
     */
    public function __construct(
        private readonly array $get    = [],
        private readonly array $post   = [],
        private readonly array $server = [],
        private readonly array $files  = [],
    ) {
        // Empty arrays signal "use the superglobals at runtime" (see the resolve*
        // helpers below). Explicit arrays supplied by callers — including tests —
        // take priority over the PHP superglobals.
    }

    /**
     * Create a Request populated from the PHP superglobals.
     *
     * This is the factory used by index.php in production. Keeping it separate
     * from the constructor makes unit tests easier — tests can call new Request()
     * with explicit arrays.
     *
     * @return self Request populated from $_GET, $_POST, $_SERVER, $_FILES.
     *
     * @example
     *   $request = Request::fromGlobals();
     */
    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES);
    }

    /**
     * Return the HTTP method in uppercase (GET, POST, PUT, DELETE, …).
     *
     * @return string The normalised HTTP method.
     *
     * @example
     *   $method = $request->method(); // 'POST'
     */
    public function method(): string
    {
        $server = $this->resolveServer();
        return strtoupper($server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Return the URL path without the query string.
     *
     * Decodes percent-encoding (e.g. %20 → space) and normalises multiple
     * consecutive slashes to a single slash. The returned path always starts
     * with a leading slash.
     *
     * @return string The decoded URL path, e.g. '/products/fresh-roses'.
     *
     * @example
     *   // For URL /products/fresh%20roses?sort=price
     *   $path = $request->path(); // '/products/fresh roses'
     */
    public function path(): string
    {
        $server  = $this->resolveServer();
        $uri     = $server['REQUEST_URI'] ?? '/';
        $path    = parse_url($uri, PHP_URL_PATH) ?? '/';
        $decoded = urldecode($path);

        // Collapse runs of slashes and ensure a leading slash.
        $clean = '/' . ltrim((string) preg_replace('#/+#', '/', $decoded), '/');

        return $clean;
    }

    /**
     * Return a value from the query string ($_GET), or $default when absent.
     *
     * String values are trimmed of surrounding whitespace.
     *
     * @param string $key     Query-string parameter name.
     * @param mixed  $default Returned when the key is not present.
     *
     * @return mixed The trimmed query-string value, or $default.
     *
     * @example
     *   $sort = $request->query('sort', 'name');
     */
    public function query(string $key, mixed $default = null): mixed
    {
        $get   = $this->resolveGet();
        $value = $get[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Return a value from the request body, checking $_POST fields first and
     * then falling back to a parsed JSON body.
     *
     * This allows Alpine.js fetch() components that POST JSON payloads to be
     * read the same way as traditional application/x-www-form-urlencoded form
     * submissions.  String values are trimmed of surrounding whitespace.
     *
     * @param string $key     POST field name or JSON body key.
     * @param mixed  $default Returned when the key is absent from both sources.
     *
     * @return mixed The trimmed value, or $default.
     *
     * @example
     *   $email = $request->post('email');
     */
    public function post(string $key, mixed $default = null): mixed
    {
        $postData = $this->resolvePost();

        if (array_key_exists($key, $postData)) {
            $value = $postData[$key];
            return is_string($value) ? trim($value) : $value;
        }

        // Fall through to parsed JSON body for fetch() requests.
        $json  = $this->jsonBody();
        $value = $json[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Return file upload metadata for a given field name from $_FILES.
     *
     * Returns null when no file was uploaded for the given field or when the
     * upload contains an error (UPLOAD_ERR_NO_FILE).
     *
     * @param string $key $_FILES field name.
     *
     * @return array<string, mixed>|null File metadata array, or null when absent.
     *
     * @example
     *   $photo = $request->file('product_image');
     *   if ($photo !== null && $photo['error'] === UPLOAD_ERR_OK) { ... }
     */
    public function file(string $key): ?array
    {
        $files = $this->resolveFiles();
        $file  = $files[$key] ?? null;

        if ($file === null) {
            return null;
        }

        // Treat "no file selected" uploads as absent.
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    /**
     * Return the value of an HTTP request header.
     *
     * Translates a header name such as 'Content-Type' into the $_SERVER key
     * format 'HTTP_CONTENT_TYPE'. Also checks for 'CONTENT_TYPE' and
     * 'CONTENT_LENGTH' which Apache does not prefix with HTTP_.
     *
     * @param string $name Header name in any capitalisation, e.g. 'Accept-Language'.
     *
     * @return string|null The raw header value, or null when not present.
     *
     * @example
     *   $accept = $request->header('Accept');
     *   $ct     = $request->header('Content-Type');
     */
    public function header(string $name): ?string
    {
        $server     = $this->resolveServer();
        $normalised = strtoupper(str_replace('-', '_', $name));

        // Some headers live without the HTTP_ prefix in $_SERVER.
        foreach (["HTTP_{$normalised}", $normalised] as $key) {
            if (isset($server[$key])) {
                return (string) $server[$key];
            }
        }

        return null;
    }

    /**
     * Return the best-guess client IP address.
     *
     * Checks X-Forwarded-For first (set by load balancers / CDNs). When that
     * header is present the leftmost (client-facing) address is returned.
     * Falls back to REMOTE_ADDR. Always validates that the result looks like
     * an IP address; returns '0.0.0.0' when nothing valid is found.
     *
     * @return string Dotted-decimal or colon-separated IP address string.
     *
     * @example
     *   $ip = $request->ip(); // '203.0.113.42'
     */
    public function ip(): string
    {
        $server = $this->resolveServer();

        // X-Forwarded-For may be a comma-separated list; the leftmost entry
        // is the original client when the header is set by a trusted proxy.
        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        $remote = $server['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($remote, FILTER_VALIDATE_IP) !== false
            ? $remote
            : '0.0.0.0';
    }

    /**
     * Return true when the HTTP method is POST.
     *
     * @return bool True for POST requests, false otherwise.
     *
     * @example
     *   if ($request->isPost()) { $this->handleSubmit($request); }
     */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Return true when the HTTP method is GET.
     *
     * @return bool True for GET requests, false otherwise.
     *
     * @example
     *   if ($request->isGet()) { $this->renderForm(); }
     */
    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    /**
     * Return a merged array of all GET and POST parameters.
     *
     * POST values take precedence over GET values when keys collide.
     *
     * @return array<string, mixed> All query-string and POST parameters.
     *
     * @example
     *   $all = $request->all();
     */
    public function all(): array
    {
        return array_merge($this->resolveGet(), $this->resolvePost());
    }

    /**
     * Return the session CSRF token, generating one if it does not yet exist.
     *
     * The token is stored under the '_csrf' key in $_SESSION and persists for
     * the lifetime of the session. Must be embedded in every form as a hidden
     * field named '_csrf_token'.
     *
     * @return string 64-character hex token.
     *
     * @throws \RuntimeException When a session has not been started.
     *
     * @example
     *   // In a view template:
     *   echo '<input type="hidden" name="_csrf_token" value="' . $request->csrfToken() . '">';
     */
    public function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Session must be started before accessing the CSRF token.');
        }

        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    /**
     * Validate that the submitted CSRF token matches the session token.
     *
     * Checks three locations in priority order so both traditional HTML forms
     * (token in $_POST['_csrf_token']) and fetch()-based Alpine.js forms
     * (token in the X-CSRF-Token request header or JSON body field) work
     * without changes on the client side.
     *
     *   1. X-CSRF-Token request header (Alpine.js / fetch)
     *   2. _csrf_token field from the JSON request body (fetch with JSON body)
     *   3. _csrf_token POST field from application/x-www-form-urlencoded body
     *
     * Uses hash_equals() to prevent timing-based side-channel attacks.
     *
     * @return bool True when the tokens match and the session is active.
     *
     * @example
     *   if (!$request->validateCsrf()) {
     *       return Response::redirect('/order?error=csrf');
     *   }
     */
    public function validateCsrf(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $sessionToken = $_SESSION['_csrf'] ?? '';
        if ($sessionToken === '') {
            return false;
        }

        // 1. X-CSRF-Token header (fetch / XHR).
        $headerToken = $this->header('X-CSRF-Token') ?? '';
        if ($headerToken !== '' && hash_equals($sessionToken, $headerToken)) {
            return true;
        }

        // 2. _csrf_token from a JSON request body.
        $jsonToken = $this->jsonBody()['_csrf_token'] ?? '';
        if (is_string($jsonToken) && $jsonToken !== '' && hash_equals($sessionToken, $jsonToken)) {
            return true;
        }

        // 3. _csrf_token from a traditional POST form.
        $postedToken = $this->post('_csrf_token', '');
        if (is_string($postedToken) && $postedToken !== '' && hash_equals($sessionToken, $postedToken)) {
            return true;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Private helpers — resolve superglobals vs. injected test data
    // -----------------------------------------------------------------------

    /**
     * Parse and cache the raw JSON request body.
     *
     * Returns an empty array when the Content-Type is not application/json,
     * when the body is empty, or when the body is not valid JSON.  Only
     * top-level object keys are returned (arrays or scalars at the root yield
     * an empty array).
     *
     * @return array<string, mixed> Parsed JSON object, or empty array.
     */
    private function jsonBody(): array
    {
        static $parsed  = null;
        static $already = false;

        if ($already) {
            return $parsed ?? [];
        }

        $already = true;
        $ct      = $this->header('Content-Type') ?? '';

        if (!str_contains($ct, 'application/json')) {
            $parsed = [];
            return [];
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            $parsed = [];
            return [];
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $parsed = [];
            return [];
        }

        $parsed = is_array($decoded) ? $decoded : [];
        return $parsed;
    }

    /**
     * Return the GET data, preferring injected test data over $_GET.
     *
     * @return array<string, mixed>
     */
    private function resolveGet(): array
    {
        return $this->get !== [] ? $this->get : $_GET;
    }

    /**
     * Return the POST data, preferring injected test data over $_POST.
     *
     * @return array<string, mixed>
     */
    private function resolvePost(): array
    {
        return $this->post !== [] ? $this->post : $_POST;
    }

    /**
     * Return the SERVER data, preferring injected test data over $_SERVER.
     *
     * @return array<string, string>
     */
    private function resolveServer(): array
    {
        return $this->server !== [] ? $this->server : $_SERVER;
    }

    /**
     * Return the FILES data, preferring injected test data over $_FILES.
     *
     * @return array<string, array<mixed>>
     */
    private function resolveFiles(): array
    {
        return $this->files !== [] ? $this->files : $_FILES;
    }
}
