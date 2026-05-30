<?php
declare(strict_types=1);

/**
 * Front controller — the single entry point for all web requests.
 *
 * Apache rewrites every request that does not map to a real file or directory
 * to this file via .htaccess. It bootstraps the application, starts the
 * session, captures UTM attribution data, and delegates routing to Router.
 */

// Autoloader (Composer PSR-4)
require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap: load .env and initialise Config
require_once __DIR__ . '/config/app.php';

// Start session (admin auth, language preference)
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => true,
        'cookie_samesite' => 'Lax',
    ]);
}

use App\Core\Lang;
use App\Core\Request;
use App\Core\Router;
use App\Models\PageView;

// Capture UTM parameters for ad attribution tracking.
// Stored in the session so attribution survives across page navigation.
if (!empty($_GET['utm_source'])) {
    $_SESSION['utm'] = [
        'source'   => $_GET['utm_source']   ?? '',
        'medium'   => $_GET['utm_medium']   ?? '',
        'campaign' => $_GET['utm_campaign'] ?? '',
        'content'  => $_GET['utm_content']  ?? '',
        'term'     => $_GET['utm_term']     ?? '',
    ];
}

// --- Page view & ad session tracking ---
$_sessionToken = session_id() ?: bin2hex(random_bytes(16));
$_ipRaw        = $_SERVER['REMOTE_ADDR'] ?? '';
$_ipHash       = hash('sha256', $_ipRaw . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$_pageUrl      = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$_referrer     = $_SERVER['HTTP_REFERER'] ?? '';
$_utmSession   = $_SESSION['utm'] ?? [];

// Record page view (silently ignores errors).
PageView::record($_sessionToken, $_pageUrl, $_referrer, $_utmSession, $_ipHash);

// If UTM params are present in this request, record the ad session.
if (!empty($_GET['utm_source'])) {
    PageView::recordAdSession($_sessionToken, $_utmSession, $_ipHash);
}

// ── Language-prefix routing ───────────────────────────────────────────────
// Public pages are served under /en/ and /es/ so both language versions
// are indexable by search engines. Strip the prefix here so the Router
// still sees plain paths (/products, /order, etc.) without any changes.

$_rawPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

if (preg_match('#^/(en|es)(/.*)?$#', $_rawPath, $_langMatch)) {
    // Valid lang prefix — set language and rewrite REQUEST_URI for the router.
    $_langCode     = $_langMatch[1];
    $_strippedPath = isset($_langMatch[2]) ? $_langMatch[2] : '/';
    Lang::set($_langCode);
    $_qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
        ? '?' . $_SERVER['QUERY_STRING']
        : '';
    // Store stripped path for canonical/hreflang construction in the layout.
    $_SERVER['LANG_STRIPPED_PATH'] = $_strippedPath;
    $_SERVER['REQUEST_URI']        = $_strippedPath . $_qs;
} elseif (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && !str_starts_with($_rawPath, '/admin')
    && !str_starts_with($_rawPath, '/lang/')
    && !str_starts_with($_rawPath, '/quote/')
    && $_rawPath !== '/robots.txt'
    && $_rawPath !== '/sitemap.xml'
    && !str_starts_with($_rawPath, '/public/')
) {
    // GET request with no lang prefix — redirect to language-prefixed URL.
    // Use session language if set, otherwise detect from Accept-Language header.
    $_detectedLang = isset($_SESSION['lang']) && in_array($_SESSION['lang'], ['en', 'es'], true)
        ? $_SESSION['lang']
        : Lang::fromAcceptHeader();
    $_qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
        ? '?' . $_SERVER['QUERY_STRING']
        : '';
    header('Location: /' . $_detectedLang . $_rawPath . $_qs, true, 302);
    exit;
}
// ── End language-prefix routing ───────────────────────────────────────────

// Build request AFTER lang-prefix rewrite so Router sees the stripped path.
$request = Request::fromGlobals();

// Route and dispatch
$routes = require __DIR__ . '/config/routes.php';
$router = new Router($routes);
$response = $router->dispatch($request);
$response->send();
