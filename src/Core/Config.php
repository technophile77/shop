<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Static accessor for environment-based configuration values.
 *
 * Reads from $_ENV, $_SERVER, and getenv() in that priority order, and
 * maintains an in-request cache so repeated reads incur no extra overhead.
 * Values are populated by vlucas/phpdotenv during bootstrap (config/app.php).
 *
 * All methods are static; this class is never instantiated.
 *
 * @see \App\Core\Database  Uses Config to retrieve DB credentials.
 */
final class Config
{
    /**
     * In-request cache of resolved configuration values.
     *
     * @var array<string, mixed>
     */
    private static array $cache = [];

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Return a single configuration value by its environment-variable key.
     *
     * Lookup order: in-memory cache → $_ENV → $_SERVER → getenv().
     * The resolved value is cached for the lifetime of the request.
     *
     * @param string $key     The environment variable name, e.g. 'DB_HOST'.
     * @param mixed  $default Returned when the key is absent from all sources.
     *
     * @return mixed The raw string value from the environment, or $default.
     *
     * @example
     *   $host = Config::get('DB_HOST', 'localhost');
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $value = $_ENV[$key]
            ?? $_SERVER[$key]
            ?? (getenv($key) !== false ? getenv($key) : null)
            ?? $default;

        self::$cache[$key] = $value;

        return $value;
    }

    /**
     * Check whether a configuration key is present in any environment source.
     *
     * @param string $key The environment variable name to test.
     *
     * @return bool True when the key exists and is not null.
     *
     * @example
     *   if (Config::has('STRIPE_SECRET')) { ... }
     */
    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * Return every environment variable visible to PHP as a key=>value map.
     *
     * Merges $_ENV and $_SERVER (in that order, so $_ENV wins on collision)
     * and excludes typical HTTP_ headers from $_SERVER to keep the result
     * focused on application-level configuration.
     *
     * @return array<string, mixed> All resolved environment variables.
     *
     * @example
     *   $vars = Config::all();
     *   // ['APP_DEBUG' => 'false', 'DB_HOST' => '127.0.0.1', ...]
     */
    public static function all(): array
    {
        $env = $_ENV;

        foreach ($_SERVER as $key => $value) {
            // Skip HTTP request headers — they are not configuration values.
            if (str_starts_with($key, 'HTTP_')) {
                continue;
            }
            if (!array_key_exists($key, $env)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
