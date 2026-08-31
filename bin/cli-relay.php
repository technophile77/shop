<?php

declare(strict_types=1);

/**
 * Runs one allowlisted static method call in a genuine CLI process and
 * prints its result as JSON on stdout — the subprocess side of
 * {@see \App\Support\CliRelay::run()}.
 *
 * On this host, PHP's cURL extension has no SSL backend at all under the
 * Apache SAPI, so any code that makes an outbound HTTPS call via cURL
 * crashes the connection instead of failing gracefully when invoked from a
 * web request. This script lets that code run under CLI instead, where
 * cURL's SSL backend works. See docs/stripe-cli-relay.md.
 *
 * Protocol: reads a single JSON object `{"class": string, "method": string,
 * "args": array}` from stdin, calls `$class::$method(...$args)` (both
 * checked against the allowlist below), and writes a single JSON line to
 * stdout:
 *   - {"ok": true, "result": <value>} on success.
 *   - {"ok": false, "class": string, "message": string, "httpStatus": ?int,
 *      "stripeCode": ?string} when a \Stripe\Exception\ApiErrorException
 *      subclass was thrown, so the parent process can reconstruct the same
 *      exception type.
 *   - {"ok": false, "class": null, "message": string} for any other Throwable.
 *
 * Never invoked directly by a human; always spawned by CliRelay::run().
 */

// Refuse to run under a web SAPI, before loading anything. The site's docroot
// is the project root and .htaccess serves any existing file directly, so
// this script is reachable at https://…/bin/cli-relay.php. It can create live
// Stripe Checkout Sessions and send real SMS/emails, and must never be
// reachable by an anonymous HTTP request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

/** @var array<class-string, list<string>> $allowedMethods */
$allowedMethods = [
    \App\Services\StripeService::class => [
        'createQuoteCheckoutSession',
        'createCartCheckoutSession',
        'retrieveCheckoutSession',
    ],
    \App\Services\TwilioService::class => [
        'send',
        'sendBulk',
    ],
    \App\Services\QuoteService::class => [
        'notifyOwner',
    ],
];

$raw     = (string) file_get_contents('php://stdin');
$request = json_decode($raw, true);

if (!is_array($request) || !isset($request['class'], $request['method'], $request['args']) || !is_array($request['args'])) {
    fwrite(STDOUT, json_encode(['ok' => false, 'class' => null, 'message' => 'Malformed relay request.']) . "\n");
    exit(1);
}

$class  = (string) $request['class'];
$method = (string) $request['method'];
$args   = $request['args'];

if (!isset($allowedMethods[$class]) || !in_array($method, $allowedMethods[$class], true)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'class' => null, 'message' => "Method not allowed: {$class}::{$method}"]) . "\n");
    exit(1);
}

try {
    $result = $class::$method(...$args);
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
