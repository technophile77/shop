<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared filesystem helpers for CLI reports that write financial data to
 * disk on pair.com shared hosting, where the project root IS the site's
 * public docroot and `.htaccess` serves any existing file directly.
 *
 * Every report CLI (`bin/sales-report.php`, `bin/stripe-reconcile.php`, and
 * any future one) needs the same three guarantees: resolve an output
 * directory outside the project by default, refuse to write inside the
 * project even if explicitly told to, and lock down whatever it writes to
 * owner-only permissions. This class exists so that logic is written once.
 *
 * `bin/sales-report.php` predates this class and keeps its own copy of the
 * same logic inline rather than being refactored to depend on it, so its
 * behaviour is guaranteed unchanged; new scripts should use this class
 * instead of copying the logic again.
 *
 * @see bin/sales-report.php     The original inline implementation this was extracted from the pattern of.
 * @see bin/stripe-reconcile.php Uses this class for its `--out`/`--csv` handling.
 */
final class ReportFileOutput
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Expand a leading `~/` (or bare `~`) in a path to the user's home directory.
     *
     * Exists because the shell does not expand `~` when it appears after an
     * `=` (e.g. `--out=~/private/flowers-sales`), so the script must do it itself.
     *
     * @param string $path A path that may start with `~`.
     *
     * @return string The path with a leading `~` replaced by the home
     *         directory, or the original path unchanged when it doesn't
     *         start with `~`, or when no home directory can be determined.
     *
     * @example
     *   ReportFileOutput::expandHome('~/private/flowers-sales'); // '/home/owner/private/flowers-sales'
     *   ReportFileOutput::expandHome('/already/absolute');       // '/already/absolute'
     */
    public static function expandHome(string $path): string
    {
        if ($path !== '~' && !str_starts_with($path, '~/') && !str_starts_with($path, '~\\')) {
            return $path;
        }

        $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: getenv('USERPROFILE'));

        if ($home === false || $home === null || $home === '') {
            return $path;
        }

        return $path === '~' ? $home : $home . substr($path, 1);
    }

    /**
     * Resolve an output directory from an explicit `--out` value, an
     * environment variable, or a home-relative default, in that priority order.
     *
     * The built-in default deliberately lives under the user's home directory
     * rather than anywhere inside the project: the project root IS the site's
     * public docroot, and `.htaccess` serves any existing file directly, so a
     * report file written into the project would be publicly downloadable.
     *
     * @param ?string $outOption           The raw `--out` value, or null when not supplied.
     * @param string  $envVarName          The environment variable to check next, e.g. `'SALES_REPORT_DIR'`.
     * @param string  $defaultRelativePath The path under `$HOME` to use when neither is set, e.g. `'private/flowers-sales'`.
     *
     * @return string The resolved, `~`-expanded output directory (not yet
     *         validated to exist or to be outside the project).
     *
     * @throws never Exits the process directly with code 2 when no home
     *         directory can be determined for the default.
     *
     * @example
     *   ReportFileOutput::resolveOutDir('~/reports', 'SALES_REPORT_DIR', 'private/flowers-sales');
     *   // '/home/owner/reports'
     *   ReportFileOutput::resolveOutDir(null, 'SALES_REPORT_DIR', 'private/flowers-sales');
     *   // e.g. '/home/owner/private/flowers-sales'
     */
    public static function resolveOutDir(?string $outOption, string $envVarName, string $defaultRelativePath): string
    {
        if ($outOption !== null) {
            return self::expandHome($outOption);
        }

        $envDir = getenv($envVarName);
        if ($envDir !== false && $envDir !== '') {
            return self::expandHome($envDir);
        }

        $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: getenv('USERPROFILE'));

        if ($home === false || $home === null || $home === '') {
            fwrite(STDERR, "Cannot determine a default --out directory: neither {$envVarName} nor HOME is set.\n");
            exit(2);
        }

        return rtrim($home, '/\\') . '/' . ltrim($defaultRelativePath, '/\\');
    }

    /**
     * Refuse an output directory that resolves to inside the project root.
     *
     * This is a real security guard, not a nicety: the project root is the
     * site's public docroot, and `.htaccess` serves any existing file
     * directly, so a financial CSV written inside it would be silently
     * publicly downloadable. Walks up from `$outDir` to the nearest ancestor
     * that already exists (since `$outDir` itself is typically created later
     * by {@see self::ensureOutDir()}) and compares its `realpath()` against
     * the project root's `realpath()`.
     *
     * @param string $outDir      The resolved (but not yet created) output directory.
     * @param string $projectRoot The project root to guard against, e.g. `dirname(__DIR__, 2)`.
     *
     * @throws never Exits the process directly with code 2 and an explicit
     *         refusal message when `$outDir` resolves inside `$projectRoot`.
     *
     * @example
     *   ReportFileOutput::assertOutsideProject('/home/owner/private/flowers-sales', '/home/owner/public_html/site');
     *   // returns normally
     *   ReportFileOutput::assertOutsideProject('/home/owner/public_html/site/reports', '/home/owner/public_html/site');
     *   // prints a refusal to STDERR and exits with code 2
     */
    public static function assertOutsideProject(string $outDir, string $projectRoot): void
    {
        $projectReal = realpath($projectRoot);
        if ($projectReal === false) {
            return;
        }

        $probe = $outDir;
        while ($probe !== '' && !is_dir($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }

        $probeReal = is_dir($probe) ? realpath($probe) : false;
        $target    = $probeReal !== false
            ? $probeReal
            : rtrim(str_replace('\\', '/', $outDir), '/');
        $project = rtrim(str_replace('\\', '/', $projectReal), '/');
        $target  = rtrim(str_replace('\\', '/', $target), '/');

        $isInside = $target === $project || str_starts_with($target . '/', $project . '/');

        if ($isInside) {
            fwrite(STDERR, "Refusing to write to \"{$outDir}\": it resolves inside the project root ({$projectReal}).\n");
            fwrite(STDERR, "The project root is the site's public docroot and .htaccess serves any existing file\n");
            fwrite(STDERR, "directly, so a report written there would be publicly downloadable. Choose a directory\n");
            fwrite(STDERR, "outside the project (see --out in --help).\n");
            exit(2);
        }
    }

    /**
     * The last PHP error's message, for turning a suppressed `@`-prefixed
     * filesystem call's failure into an actionable STDERR line.
     *
     * @return string The last error message, or a generic fallback when none was recorded.
     */
    public static function lastErrorMessage(): string
    {
        $error = error_get_last();

        return $error['message'] ?? 'unknown error';
    }

    /**
     * Create the output directory (mode 0700 — it holds financial data on
     * shared hosting) if it doesn't already exist.
     *
     * @param string $outDir The directory to ensure exists.
     *
     * @throws never Exits the process directly with code 1 and the
     *         underlying reason on STDERR when the directory cannot be created.
     */
    public static function ensureOutDir(string $outDir): void
    {
        if (is_dir($outDir)) {
            return;
        }

        if (!@mkdir($outDir, 0700, true) && !is_dir($outDir)) {
            fwrite(STDERR, "Unable to create output directory \"{$outDir}\": " . self::lastErrorMessage() . "\n");
            exit(1);
        }

        @chmod($outDir, 0700);
    }

    /**
     * Lock down a just-written report file's permissions to 0600 (owner
     * read/write only), since it holds financial data on a shared host.
     *
     * @param string $path The file to secure.
     */
    public static function secureFile(string $path): void
    {
        if (!@chmod($path, 0600)) {
            fwrite(STDERR, "Warning: could not chmod \"{$path}\" to 0600: " . self::lastErrorMessage() . "\n");
        }
    }

    /**
     * Write a `list<list<scalar>>` of CSV-ready rows to a file via
     * `fputcsv()`, lock its permissions down, and print its absolute path.
     *
     * @param string             $path The destination file path.
     * @param list<list<scalar>> $rows Rows to write, one `fputcsv()` call per row.
     *
     * @return string The absolute path written.
     *
     * @throws never Exits the process directly with code 1 and the reason on
     *         STDERR when the file cannot be opened for writing.
     */
    public static function writeCsvFile(string $path, array $rows): string
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            fwrite(STDERR, "Unable to write \"{$path}\": " . self::lastErrorMessage() . "\n");
            exit(1);
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        self::secureFile($path);
        $absolute = realpath($path) ?: $path;
        fwrite(STDOUT, "Wrote {$absolute}\n");

        return $absolute;
    }
}
