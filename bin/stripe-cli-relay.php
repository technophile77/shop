<?php

declare(strict_types=1);

/**
 * Runs one StripeService call in a genuine CLI process and prints its result
 * as JSON on stdout.
 *
 * On this host, PHP's cURL extension has no SSL backend at all under the
 * Apache SAPI (`curl_version()['ssl_version']` reports "not available" there,
 * vs. a working OpenSSL 3.0.20 under CLI) — every outbound HTTPS call the
 * Stripe SDK makes from a web request severs the connection instead of
 * failing gracefully, which is why {@see \App\Services\StripeService} shells
 * out to this script for its network-calling methods rather than calling the
 * Stripe SDK in-process. See docs/stripe-cli-relay.md.
 *
 * Protocol: reads a single JSON object `{"method": string, "args": array}`
 * from stdin, calls `StripeService::$method(...$args)` (method is checked
 * against an allowlist — see $allowedMethods below), and writes a single JSON
 * line to stdout:
 *   - {"ok": true, "result": <value>} on success.
 *   - {"ok": false, "class": string, "message": string, "httpStatus": ?int,
 *      "stripeCode": ?string} when a \Stripe\Exception\ApiErrorException
 *      subclass was thrown, so the parent process can reconstruct the same
 *      exception type.
 *   - {"ok": false, "class": null, "message": string} for any other Throwable.
 *
 * Never invoked directly by a human; always spawned by StripeService via
 * proc_open().
 */

// Refuse to run under a web SAPI, before loading anything. The site's docroot
// is the project root and .htaccess serves any existing file directly, so this
// script is reachable at https://…/bin/stripe-cli-relay.php. It can create
// live Stripe Checkout Sessions and must never be reachable by an anonymous
// HTTP request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use App\Services\StripeService;

$allowedMethods = [
    'createQuoteCheckoutSession',
    'createCartCheckoutSession',
    'retrieveCheckoutSession',
];

$raw     = (string) file_get_contents('php://stdin');
$request = json_decode($raw, true);

if (!is_array($request) || !isset($request['method'], $request['args']) || !is_array($request['args'])) {
    fwrite(STDOUT, json_encode(['ok' => false, 'class' => null, 'message' => 'Malformed relay request.']) . "\n");
    exit(1);
}

$method = (string) $request['method'];
$args   = $request['args'];

if (!in_array($method, $allowedMethods, true)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'class' => null, 'message' => "Method not allowed: {$method}"]) . "\n");
    exit(1);
}

try {
    $result = StripeService::$method(...$args);
    fwrite(STDOUT, json_encode(['ok' => true, 'result' => $result]) . "\n");
    exit(0);
} catch (\Stripe\Exception\ApiErrorException $e) {
    fwrite(STDOUT, json_encode([
        'ok'         => false,
        'class'      => get_class($e),
        'message'    => $e->getMessage(),
        'httpStatus' => $e->getHttpStatus(),
        'stripeCode' => $e->getStripeCode(),
    ]) . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok'      => false,
        'class'   => null,
        'message' => $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(),
    ]) . "\n");
    exit(1);
}
