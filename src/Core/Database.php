<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Manages three lazily-created PDO connection singletons.
 *
 * Three separate MySQL users are used to enforce least-privilege access:
 *
 *   RO   (DB_USER_RO / DB_PASS_RO)     — SELECT only; used for all reads.
 *   RW   (DB_USER_RW / DB_PASS_RW)     — INSERT, UPDATE, DELETE; used for
 *                                         writes from application code.
 *   FULL (DB_USER_FULL / DB_PASS_FULL) — DDL; used only by migration scripts.
 *
 * Connections are created on first access and reused for the remainder of the
 * request. All connections share the same PDO options: exception error mode,
 * associative fetch mode, native prepares, and utf8mb4 charset.
 *
 * Connection string: mysql:host={DB_HOST};dbname={DB_NAME};charset=utf8mb4
 *
 * All methods are static; this class is never instantiated.
 *
 * @see \App\Core\Config   Supplies DB_HOST, DB_NAME, and credential values.
 * @see \App\Core\Settings Uses ro() for reads and rw() for writes.
 */
final class Database
{
    /** @var PDO|null Cached read-only connection. */
    private static ?PDO $ro = null;

    /** @var PDO|null Cached read-write connection. */
    private static ?PDO $rw = null;

    /** @var PDO|null Cached full-access connection. */
    private static ?PDO $full = null;

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Return the singleton read-only PDO connection.
     *
     * Creates the connection on first call using DB_USER_RO / DB_PASS_RO
     * credentials. Intended exclusively for SELECT queries.
     *
     * @return PDO The shared read-only connection.
     *
     * @throws PDOException When the database is unreachable or credentials fail.
     *
     * @example
     *   $stmt = Database::ro()->prepare('SELECT * FROM products WHERE slug = ?');
     *   $stmt->execute([$slug]);
     */
    public static function ro(): PDO
    {
        if (self::$ro === null) {
            self::$ro = self::connect(
                Config::get('DB_USER_RO'),
                Config::get('DB_PASS_RO'),
            );
        }

        return self::$ro;
    }

    /**
     * Return the singleton read-write PDO connection.
     *
     * Creates the connection on first call using DB_USER_RW / DB_PASS_RW
     * credentials. Intended for INSERT, UPDATE, and DELETE statements issued
     * by application code.
     *
     * @return PDO The shared read-write connection.
     *
     * @throws PDOException When the database is unreachable or credentials fail.
     *
     * @example
     *   $stmt = Database::rw()->prepare('UPDATE quotes SET status = ? WHERE id = ?');
     *   $stmt->execute([$status, $id]);
     */
    public static function rw(): PDO
    {
        if (self::$rw === null) {
            self::$rw = self::connect(
                Config::get('DB_USER_RW'),
                Config::get('DB_PASS_RW'),
            );
        }

        return self::$rw;
    }

    /**
     * Return the singleton full-access PDO connection.
     *
     * Creates the connection on first call using DB_USER_FULL / DB_PASS_FULL
     * credentials. Intended only for migration scripts that need DDL access
     * (CREATE TABLE, ALTER TABLE, etc.). Never use in application request code.
     *
     * @return PDO The shared full-access connection.
     *
     * @throws PDOException When the database is unreachable or credentials fail.
     *
     * @example
     *   Database::full()->exec('CREATE TABLE IF NOT EXISTS site_settings (...)');
     */
    public static function full(): PDO
    {
        if (self::$full === null) {
            self::$full = self::connect(
                Config::get('DB_USER_FULL'),
                Config::get('DB_PASS_FULL'),
            );
        }

        return self::$full;
    }

    /**
     * Open and configure a PDO connection for the given credentials.
     *
     * Uses DB_HOST and DB_NAME from Config to build the DSN. All connections
     * share the same options: ERRMODE_EXCEPTION, FETCH_ASSOC, native prepares,
     * and utf8mb4 charset.
     *
     * @param string|null $user MySQL username.
     * @param string|null $pass MySQL password.
     *
     * @return PDO A configured, connected PDO instance.
     *
     * @throws PDOException When the connection cannot be established.
     * @throws \RuntimeException When DB_HOST, DB_NAME, or credentials are missing.
     */
    private static function connect(?string $user, ?string $pass): PDO
    {
        $host   = Config::get('DB_HOST', '127.0.0.1');
        $name   = Config::get('DB_NAME');
        $port   = Config::get('DB_PORT', '3306');

        if ($name === null) {
            throw new \RuntimeException('DB_NAME is not configured.');
        }
        if ($user === null) {
            throw new \RuntimeException('Database username is not configured.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
