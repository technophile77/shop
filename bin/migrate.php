<?php

declare(strict_types=1);

/**
 * Minimal forward-only migration runner for the SQL files in migrations/.
 *
 * Applies one migration file against the full-access (DDL) database connection.
 * There is no local dev database, so migrations are applied on the server right
 * after deploying the changed code (see CLAUDE.md). This runner keeps that step
 * reproducible instead of pasting ALTER statements by hand.
 *
 * It reads the file, strips `--` line comments, splits on `;` statement
 * terminators, and runs each statement via Database::full()->exec(). It does
 * NOT track which migrations have already run — apply each file exactly once.
 * Re-running a migration whose columns/tables already exist will error (which
 * is the intended safety signal).
 *
 * Usage:
 *   php bin/migrate.php 014_order_fulfillment.sql
 *   php bin/migrate.php migrations/014_order_fulfillment.sql
 *
 * @see \App\Core\Database::full()
 */

use App\Core\Database;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

$arg = $argv[1] ?? '';
if ($arg === '') {
    fwrite(STDERR, "Usage: php bin/migrate.php <migration-file.sql>\n");
    exit(2);
}

// Accept a bare filename or a path; resolve against migrations/ when bare.
$path = is_file($arg) ? $arg : dirname(__DIR__) . '/migrations/' . ltrim($arg, '/');
if (!is_file($path)) {
    fwrite(STDERR, "Migration file not found: {$arg}\n");
    exit(2);
}

$sql = (string) file_get_contents($path);

// Strip full-line `--` comments, then split into individual statements.
$lines = array_filter(
    explode("\n", $sql),
    static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
);
$statements = array_filter(
    array_map('trim', explode(';', implode("\n", $lines))),
    static fn (string $stmt): bool => $stmt !== '',
);

$pdo = Database::full();
$count = 0;
foreach ($statements as $statement) {
    $pdo->exec($statement);
    $count++;
}

fwrite(STDOUT, "Applied " . basename($path) . " ({$count} statement(s)).\n");
exit(0);
