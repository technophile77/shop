<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Support\CliRelay;

/**
 * Sends SMS messages via the Twilio REST API using PHP's curl extension.
 * No Twilio PHP SDK required — uses direct HTTP calls.
 *
 * Credentials are read from the environment: TWILIO_ACCOUNT_SID,
 * TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER. When any of those values are
 * absent, every send method returns a failure result immediately and nothing
 * is sent.
 *
 * When called from a web request, {@see send()} and {@see sendBulk()} relay
 * through {@see \App\Support\CliRelay} rather than calling `curl_exec()`
 * in-process — this host's Apache-SAPI PHP has no cURL SSL backend, so the
 * direct call would sever the connection instead of returning a failure
 * array. See docs/stripe-cli-relay.md.
 *
 * All methods are static; this class is never instantiated.
 *
 * @see \App\Services\QuoteService::notifyOwner()  Related single-send pattern.
 */
final class TwilioService
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Sends a single SMS message.
     *
     * Makes a POST request to the Twilio Messages API using Basic Auth
     * (Account SID : Auth Token). Returns the message SID on success.
     * Logs every attempt and failure to the PHP error log.
     *
     * @param string $to   Recipient phone number (digits only, e.g. "7203883496").
     *                     Will be formatted as +1{number} for 10-digit US numbers,
     *                     or +{number} for 11-digit numbers starting with 1.
     * @param string $body Message body (max 160 chars for a single-segment SMS).
     *
     * @return array{success: bool, sid: ?string, error: ?string}
     *         On success: ['success' => true, 'sid' => 'SM…', 'error' => null].
     *         On failure: ['success' => false, 'sid' => null, 'error' => '…'].
     *
     * Relayed through {@see \App\Support\CliRelay} when called from a web
     * request — this host's Apache-SAPI PHP has no cURL SSL backend, so the
     * `curl_exec()` below would otherwise sever the connection instead of
     * returning a failure array. See docs/stripe-cli-relay.md.
     *
     * @example
     *   $result = TwilioService::send('7203883496', 'Hello from Perla\'s Flowers!');
     *   if ($result['success']) { echo $result['sid']; }
     */
    public static function send(string $to, string $body): array
    {
        if (CliRelay::isNeeded()) {
            try {
                return CliRelay::run(self::class, 'send', func_get_args());
            } catch (\Throwable $e) {
                error_log('[TwilioService] CLI relay failed for send(): ' . $e->getMessage());
                return ['success' => false, 'sid' => null, 'error' => 'CLI relay failed: ' . $e->getMessage()];
            }
        }

        if (!self::isConfigured()) {
            return ['success' => false, 'sid' => null, 'error' => 'Twilio not configured'];
        }

        $sid   = (string) Config::get('TWILIO_ACCOUNT_SID', '');
        $token = (string) Config::get('TWILIO_AUTH_TOKEN',  '');
        $from  = (string) Config::get('TWILIO_FROM_NUMBER', '');

        $formatted = self::formatNumber($to);
        $url       = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $payload   = http_build_query(['From' => $from, 'To' => $formatted, 'Body' => $body]);

        $ch = curl_init($url);
        if ($ch === false) {
            error_log("[TwilioService] curl_init failed sending to {$formatted}.");
            return ['success' => false, 'sid' => null, 'error' => 'curl_init failed'];
        }

        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
        curl_setopt($ch, CURLOPT_USERPWD,        "{$sid}:{$token}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        15);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/x-www-form-urlencoded']);

        $rawResponse = curl_exec($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($rawResponse) || $rawResponse === '') {
            error_log("[TwilioService] Empty or failed curl response for {$formatted} (HTTP {$httpCode}).");
            return ['success' => false, 'sid' => null, 'error' => 'No response from Twilio'];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawResponse, associative: true);

        if ($httpCode === 200 || $httpCode === 201) {
            $messageSid = is_array($decoded) ? (string) ($decoded['sid'] ?? '') : '';
            error_log("[TwilioService] SMS sent to {$formatted} — SID: {$messageSid}");
            return ['success' => true, 'sid' => $messageSid, 'error' => null];
        }

        $errorMessage = is_array($decoded)
            ? (string) ($decoded['message'] ?? $rawResponse)
            : $rawResponse;

        error_log("[TwilioService] SMS to {$formatted} failed (HTTP {$httpCode}): {$errorMessage}");
        return ['success' => false, 'sid' => null, 'error' => $errorMessage];
    }

    /**
     * Sends the same message to multiple recipients.
     *
     * Calls {@see send()} for each number with a 100 ms sleep between calls
     * to stay within Twilio's default rate limit of ~1 message/second.
     * Returns one result array per recipient in the same order as $numbers.
     *
     * @param string[] $numbers Array of phone numbers (digits only).
     * @param string   $body    Message body (max 160 chars).
     *
     * @return array<int, array{to: string, success: bool, sid: ?string, error: ?string}>
     *         Each element includes the original 'to' number alongside the send result.
     *
     * Relayed as a single call through {@see \App\Support\CliRelay} when
     * invoked from a web request — one subprocess handles every recipient,
     * rather than relaying each {@see send()} call separately. See
     * docs/stripe-cli-relay.md.
     *
     * @example
     *   $results = TwilioService::sendBulk(['7203883496', '9185551234'], 'Sale today!');
     *   foreach ($results as $r) {
     *       echo $r['to'] . ': ' . ($r['success'] ? 'sent' : $r['error']) . PHP_EOL;
     *   }
     */
    public static function sendBulk(array $numbers, string $body): array
    {
        if (CliRelay::isNeeded()) {
            try {
                return CliRelay::run(self::class, 'sendBulk', func_get_args());
            } catch (\Throwable $e) {
                error_log('[TwilioService] CLI relay failed for sendBulk(): ' . $e->getMessage());
                return array_map(
                    static fn (string $number): array => [
                        'to' => $number, 'success' => false, 'sid' => null,
                        'error' => 'CLI relay failed: ' . $e->getMessage(),
                    ],
                    $numbers,
                );
            }
        }

        $results = [];

        foreach ($numbers as $index => $number) {
            if ($index > 0) {
                // 100 ms pause between sends to respect Twilio rate limits.
                usleep(100_000);
            }

            $result          = self::send($number, $body);
            $result['to']    = $number;
            $results[]       = $result;
        }

        return $results;
    }

    /**
     * Returns true if all three required Twilio env vars are present and non-empty.
     *
     * @return bool True when TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and
     *              TWILIO_FROM_NUMBER are all set.
     *
     * @example
     *   if (TwilioService::isConfigured()) { TwilioService::send(...); }
     */
    public static function isConfigured(): bool
    {
        return Config::get('TWILIO_ACCOUNT_SID',  '') !== ''
            && Config::get('TWILIO_AUTH_TOKEN',   '') !== ''
            && Config::get('TWILIO_FROM_NUMBER',  '') !== '';
    }

    /**
     * Formats a digits-only US phone number to E.164 format.
     *
     * - 10 digits  → '+1XXXXXXXXXX'
     * - 11 digits starting with '1' → '+1XXXXXXXXXX'
     * - Any other length → '+' prepended as-is (caller's responsibility).
     *
     * @param string $number Raw phone digits (may contain non-digit characters,
     *                       which are stripped before formatting).
     *
     * @return string E.164-formatted number, e.g. '+17203883496'.
     *
     * Public so other services (e.g. {@see \App\Services\QuoteService::notifyOwner()})
     * can reuse the same correct formatting instead of re-deriving it.
     *
     * @example
     *   TwilioService::formatNumber('7203883496');   // '+17203883496'
     *   TwilioService::formatNumber('17203883496');  // '+17203883496'
     *   TwilioService::formatNumber('(720) 388-3496'); // '+17203883496'
     */
    public static function formatNumber(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return '+' . $digits;
    }
}
