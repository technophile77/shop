<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Prefers Composer's autoloader when vendor/ is installed (the normal
 * `composer test` path). Falls back to a minimal PSR-4 autoloader for the
 * App\ namespace so the pure-logic unit tests can also run on a machine that
 * only has PHP + a phpunit.phar (no Composer), which is how the SEO logic is
 * verified locally before deploy.
 */

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file     = __DIR__ . '/../src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});
