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

use App\Core\Request;
use App\Core\Router;
use App\Models\PageView;

$request = Request::fromGlobals();

// Capture UTM parameters for ad attribution tracking.
// Stored in the session so attribution survives across page navigation.
if ($request->query('utm_source')) {
    $_SESSION['utm'] = [
        'source'   => $request->query('utm_source', ''),
        'medium'   => $request->query('utm_medium', ''),
        'campaign' => $request->query('utm_campaign', ''),
        'content'  => $request->query('utm_content', ''),
        'term'     => $request->query('utm_term', ''),
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

// Route and dispatch
$routes = require __DIR__ . '/config/routes.php';
$router = new Router($routes);
$response = $router->dispatch($request);
$response->send();
