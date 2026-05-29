<?php
declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Loaded by index.php immediately after the Composer autoloader. Responsible
 * for reading the .env file, setting the process timezone, and configuring
 * PHP error reporting to match the APP_DEBUG flag.
 */

// Load .env (silently skip if not present — e.g. during composer install)
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Set timezone
date_default_timezone_set('America/Chicago');

// Set error reporting based on APP_DEBUG
if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
}
