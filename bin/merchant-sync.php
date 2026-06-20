<?php

declare(strict_types=1);

/**
 * Bulk-syncs all eligible active products to Google Merchant Center.
 *
 * Loads every active product (see {@see \App\Models\Product::allActive()}),
 * filters to those eligible for the feed (see
 * {@see \App\Support\MerchantProduct::eligible()}), and for each eligible
 * product emits one productInput per content language ('en' and 'es') via
 * {@see \App\Services\GoogleMerchantService::upsert()}.
 *
 * With --dry-run (or -n) NO network call is made and NO Merchant credentials are
 * required: the script prints the offerId, language, and the pretty-printed JSON
 * payload that would be sent — letting you inspect the exact feed body before any
 * credentials exist. A real run requires
 * {@see \App\Services\GoogleMerchantService::isConfigured()} and exits non-zero
 * with a clear message when the client is not configured.
 *
 * A summary line reports the counts of products, attempts, successes, and
 * failures. Exit status is 0 on full success and non-zero if any attempt failed
 * (or, in a real run, when the client is not configured).
 *
 * Usage:
 *   php bin/merchant-sync.php --dry-run   # print payloads, no network, no creds
 *   php bin/merchant-sync.php -n          # same as --dry-run
 *   php bin/merchant-sync.php             # real sync (requires credentials)
 *
 * @see \App\Services\GoogleMerchantService
 * @see \App\Support\MerchantProduct
 * @see \App\Models\Product::allActive()
 */

use App\Models\Product;
use App\Services\GoogleMerchantService;
use App\Support\MerchantProduct;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

/** Content languages to publish each eligible product under. */
const LANGS = ['en', 'es'];

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true) || in_array('-n', $args, true);

// In a real run, refuse to start unless the client is fully configured.
if (!$dryRun && !GoogleMerchantService::isConfigured()) {
    fwrite(STDERR, "Google Merchant client is not configured.\n");
    fwrite(STDERR, "Set GOOGLE_MERCHANT_ENABLED=true, GOOGLE_MERCHANT_ID, GOOGLE_MERCHANT_DATASOURCE,\n");
    fwrite(STDERR, "and a readable GOOGLE_SERVICE_ACCOUNT_KEY_PATH, or run with --dry-run.\n");
    exit(2);
}

$products = array_values(array_filter(Product::allActive(), [MerchantProduct::class, 'eligible']));
$cfg      = GoogleMerchantService::configForDryRun();

$attempted = 0;
$succeeded = 0;
$failed    = 0;

foreach ($products as $product) {
    foreach (LANGS as $lang) {
        if ($dryRun) {
            $body    = MerchantProduct::toProductInput($product, $lang, $cfg);
            $offerId = (string) ($body['offerId'] ?? '');
            fwrite(STDOUT, "── {$offerId} [{$lang}]\n");
            fwrite(STDOUT, json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n");
            continue;
        }

        $attempted++;
        $result  = GoogleMerchantService::upsert($product, $lang);
        $offerId = (string) ($result['offerId'] ?? '');

        if ($result['success']) {
            $succeeded++;
            fwrite(STDOUT, "  ✓ {$offerId} [{$lang}] HTTP {$result['http']}\n");
        } else {
            $failed++;
            fwrite(STDERR, "  ✗ {$offerId} [{$lang}] HTTP {$result['http']}: {$result['error']}\n");
        }
    }
}

$productCount = count($products);

if ($dryRun) {
    fwrite(STDOUT, sprintf(
        "Dry run: %d eligible product(s) × %d language(s) = %d payload(s). No network calls made.\n",
        $productCount,
        count(LANGS),
        $productCount * count(LANGS)
    ));
    exit(0);
}

fwrite(STDOUT, sprintf(
    "Done. %d product(s), %d attempted, %d succeeded, %d failed.\n",
    $productCount,
    $attempted,
    $succeeded,
    $failed
));

exit($failed > 0 ? 1 : 0);
