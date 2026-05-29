<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Static accessor for the `site_settings` database table.
 *
 * Settings are stored as key/value string pairs and serve as runtime
 * configuration that the site owner can change through the admin UI without
 * deploying code (e.g. site name, phone number, social media links).
 *
 * Rows are loaded lazily on first access and cached in a static array for the
 * remainder of the request to avoid repeated database round-trips. Call
 * {@see reload()} after a batch update to refresh the cache.
 *
 * Expected table schema:
 * ```sql
 * CREATE TABLE site_settings (
 *     id         INT AUTO_INCREMENT PRIMARY KEY,
 *     `key`      VARCHAR(100) NOT NULL,
 *     value      TEXT NOT NULL DEFAULT '',
 *     updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
 *                             ON UPDATE CURRENT_TIMESTAMP,
 *     UNIQUE KEY uq_key (`key`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ```
 *
 * Read operations use the read-only database connection; writes use the
 * read-write connection. The class degrades gracefully when the table does not
 * yet exist (e.g. before migrations have run) by returning $default values.
 *
 * All methods are static; this class is never instantiated.
 *
 * @see \App\Core\Database   Supplies the PDO connections used for queries.
 *
 * @example
 *   $shopName  = Settings::get('shop_name', "Perla's Flowers");
 *   $phone     = Settings::get('phone_number');
 *   Settings::set('shop_name', "Rosa's Blooms");
 */
final class Settings
{
    /**
     * In-request cache.  Null means "not yet loaded from the database."
     *
     * @var array<string, string>|null
     */
    private static ?array $cache = null;

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Return the value of a setting, or $default when the key is absent.
     *
     * Triggers a lazy load of all settings on the first call.
     *
     * @param string $key     The setting key, e.g. 'shop_name'.
     * @param mixed  $default Returned when the key has no row in the database.
     *
     * @return mixed The stored string value, or $default.
     *
     * @example
     *   $name = Settings::get('shop_name', "Perla's Flowers");
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::loadIfNeeded();

        return self::$cache[$key] ?? $default;
    }

    /**
     * Persist a setting value using an INSERT … ON DUPLICATE KEY UPDATE statement.
     *
     * The in-request cache is updated immediately so subsequent reads within the
     * same request see the new value without a database round-trip.
     *
     * @param string $key   The setting key (max 100 characters, must match the column).
     * @param string $value The value to store.
     *
     * @throws \PDOException When the write fails due to a database error.
     *
     * @example
     *   Settings::set('phone_number', '(512) 555-0199');
     */
    public static function set(string $key, string $value): void
    {
        $sql = <<<SQL
            INSERT INTO site_settings (`key`, value)
            VALUES (:key, :value)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
            SQL;

        $stmt = Database::rw()->prepare($sql);
        $stmt->execute([':key' => $key, ':value' => $value]);

        // Keep the cache consistent without forcing a full reload.
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    /**
     * Return all settings as a key => value map.
     *
     * Triggers a lazy load on the first call. Returns an empty array when the
     * table does not yet exist.
     *
     * @return array<string, string> All stored settings.
     *
     * @example
     *   $all = Settings::all();
     *   // ['shop_name' => "Perla's Flowers", 'phone_number' => '(512) 555-0199', …]
     */
    public static function all(): array
    {
        self::loadIfNeeded();

        return self::$cache ?? [];
    }

    /**
     * Clear the in-request cache and re-read all settings from the database.
     *
     * Call this after a batch update (e.g. the admin Settings form saves multiple
     * values at once) to ensure the rest of the request sees the fresh values.
     *
     * @return void
     *
     * @example
     *   foreach ($updates as $key => $value) { Settings::set($key, $value); }
     *   Settings::reload();
     */
    public static function reload(): void
    {
        self::$cache = null;
        self::loadIfNeeded();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Populate the static cache from the database if it has not been loaded yet.
     *
     * Uses the read-only connection. Silently sets the cache to an empty array
     * when the site_settings table does not exist, so the application does not
     * crash before migrations have run.
     *
     * @return void
     */
    private static function loadIfNeeded(): void
    {
        if (self::$cache !== null) {
            return;
        }

        try {
            $stmt = Database::ro()->query('SELECT `key`, value FROM site_settings');
            $rows = $stmt->fetchAll();

            self::$cache = [];
            foreach ($rows as $row) {
                self::$cache[$row['key']] = $row['value'];
            }

        } catch (\PDOException $e) {
            // Gracefully degrade when the table does not yet exist.
            // SQLSTATE 42S02 = "Base table or view not found" (MySQL error 1146).
            if (str_contains($e->getMessage(), '1146')
                || str_contains($e->getCode(), '42S02')
            ) {
                self::$cache = [];
                return;
            }

            // Re-throw unexpected database errors.
            throw $e;
        }
    }
}
