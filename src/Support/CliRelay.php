<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Runs one allowlisted static method call in a genuine CLI subprocess
 * (bin/cli-relay.php) and returns its result, for code that must make an
 * outbound HTTPS call but may be invoked from a web request.
 *
 * On this host, PHP's cURL extension under the Apache SAPI has no SSL
 * backend at all (confirmed via `curl_version()['ssl_version']`, which
 * reports "not available" under `apache2handler` vs. a working OpenSSL
 * build under CLI) — every outbound HTTPS call made via cURL from a web
 * request severs the connection instead of failing gracefully. CLI PHP's
 * cURL has a working SSL backend, so the actual call is relayed there
 * instead. First discovered via the Stripe checkout flow (blank "Connection
 * error" on the card-payment link); the same crash later turned out to be
 * silently dropping the Stripe payment owner-notification SMS
 * ({@see \App\Services\QuoteService::notifyOwner()}) too. See
 * docs/stripe-cli-relay.md for the full write-up.
 *
 * All methods are static; this class is never instantiated.
 *
 * @see \App\Services\StripeService
 * @see \App\Services\TwilioService
 * @see \App\Services\QuoteService::notifyOwner()
 */
final class CliRelay
{
    /** Seconds to wait for the subprocess before giving up. */
    private const TIMEOUT_SECONDS = 25;

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * True when the current request must relay through CLI rather than
     * calling the method directly — i.e. whenever this isn't already CLI.
     *
     * @return bool True under the web SAPI; false under CLI (including
     *         inside the relay subprocess itself, so it runs the real call
     *         instead of relaying to itself).
     *
     * @example
     *   if (CliRelay::isNeeded()) {
     *       return CliRelay::run(self::class, __FUNCTION__, func_get_args());
     *   }
     */
    public static function isNeeded(): bool
    {
        return PHP_SAPI !== 'cli';
    }

    /**
     * Runs $class::$method(...$args) in a CLI subprocess and returns its
     * result. $class::$method must be allowlisted in bin/cli-relay.php.
     *
     * @param string $class  Fully-qualified class name (e.g. `self::class`
     *        from the calling method).
     * @param string $method Static method name on $class.
     * @param array<int, mixed> $args Positional arguments for that method.
     * @param bool $resultIsObject When true, a nested JSON object in the
     *        result decodes to \stdClass (matching `$x->y`-style callers,
     *        e.g. {@see \App\Services\StripeService::retrieveCheckoutSession()});
     *        when false (the default), it decodes to a plain array.
     *
     * @return mixed Whatever the relayed method returned, JSON round-tripped.
     *
     * @throws \Stripe\Exception\ApiErrorException When the relayed call threw
     *         one; the original exception subclass is reconstructed.
     * @throws \RuntimeException When the subprocess could not be started, did
     *         not exit cleanly, or returned something other than the expected
     *         JSON envelope (including any other exception from the relayed call).
     *
     * @example
     *   return CliRelay::run(self::class, 'send', func_get_args());
     */
    public static function run(string $class, string $method, array $args, bool $resultIsObject = false): mixed
    {
        $phpBinary   = (string) Config::get('PHP_CLI_BINARY', '/usr/local/bin/php82');
        $relayScript = dirname(__DIR__, 2) . '/bin/cli-relay.php';

        $process = proc_open(
            [$phpBinary, $relayScript],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new \RuntimeException("Could not start CLI relay process ({$phpBinary}).");
        }

        fwrite($pipes[0], json_encode(['class' => $class, 'method' => $method, 'args' => $args]));
        fclose($pipes[0]);

        stream_set_timeout($pipes[1], self::TIMEOUT_SECONDS);
        $stdout = stream_get_contents($pipes[1]);
        $meta   = stream_get_meta_data($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        if ($meta['timed_out'] ?? false) {
            throw new \RuntimeException('CLI relay timed out after ' . self::TIMEOUT_SECONDS . 's.');
        }

        // Object mode by default so a nested JSON object comes back as
        // \stdClass; $resultIsObject decides how the top-level `result` is
        // cast below.
        $response = json_decode(trim((string) $stdout));

        if (!is_object($response) || !property_exists($response, 'ok')) {
            throw new \RuntimeException(
                "CLI relay for {$class}::{$method}() returned an unreadable response. stderr: " . trim($stderr),
            );
        }

        if ($response->ok === true) {
            return $resultIsObject ? $response->result : (array) $response->result;
        }

        $exceptionClass = $response->class ?? null;
        if (is_string($exceptionClass) && is_subclass_of($exceptionClass, \Stripe\Exception\ApiErrorException::class)) {
            throw $exceptionClass::factory(
                (string) ($response->message ?? 'API error.'),
                $response->httpStatus ?? null,
                null,
                null,
                null,
                $response->stripeCode ?? null,
            );
        }

        throw new \RuntimeException(
            "CLI relay error from {$class}::{$method}(): " . (string) ($response->message ?? 'unknown error'),
        );
    }
}
