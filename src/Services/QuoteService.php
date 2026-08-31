<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Support\CliRelay;

/**
 * Stateless utility methods for working with quote data and notifications.
 *
 * Keeps non-persistence logic — URL generation, item decoding, subtotal
 * calculation, and owner SMS alerts — out of the model layer so each
 * concern lives in the right place.
 *
 * All methods are static; this class is never instantiated.
 *
 * @see \App\Models\Quote
 * @see \App\Controllers\QuoteController
 */
final class QuoteService
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Builds the public shareable URL for a quote.
     *
     * Reads APP_URL from the environment; falls back to an empty string when
     * not configured so the URL is still well-formed for relative environments.
     *
     * @param string $token The 64-character hex token stored on the quote.
     *
     * @return string The absolute public URL, e.g.
     *                'https://flowers.example.com/quote/abc123def456…'.
     *
     * @example
     *   $url = QuoteService::quoteUrl('abc123def456...');
     *   // 'https://flowers.cresswell.org/quote/abc123def456...'
     */
    public static function quoteUrl(string $token): string
    {
        $base = rtrim((string) Config::get('APP_URL', ''), '/');

        return $base . '/quote/' . $token;
    }

    /**
     * Decodes the items JSON blob from a quote row into a typed PHP array.
     *
     * Each element is cast to the documented types so callers can rely on
     * the shape without additional validation.
     *
     * @param string $itemsJson Raw JSON string from the `items_json` column.
     *                          An empty string or the literal string 'null' returns
     *                          an empty array without throwing.
     *
     * @return array<int, array{description: string, qty: int, unit_price: float, full_deposit: bool}>
     *         Decoded and type-coerced item list. `full_deposit` marks a line
     *         that must be paid in full upfront; it defaults to false for older
     *         quotes whose JSON predates the flag.
     *
     * @throws \JsonException When $itemsJson is non-empty but not valid JSON.
     *
     * @example
     *   $items = QuoteService::decodeItems($quote['items_json']);
     *   foreach ($items as $item) {
     *       echo $item['description'] . ' x' . $item['qty'];
     *   }
     */
    public static function decodeItems(string $itemsJson): array
    {
        if ($itemsJson === '' || $itemsJson === 'null') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($itemsJson, associative: true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            return [];
        }

        return array_map(
            static fn (mixed $item): array => [
                'description'  => (string) ($item['description'] ?? ''),
                'qty'          => (int)    ($item['qty']         ?? 0),
                'unit_price'   => (float)  ($item['unit_price']  ?? 0.0),
                'full_deposit' => (bool)   ($item['full_deposit'] ?? false),
            ],
            $decoded
        );
    }

    /**
     * Calculates the total from a decoded items array.
     *
     * Multiplies qty × unit_price for each item and sums the results.
     * Returns 0.0 for an empty array.
     *
     * @param array<int, array{description: string, qty: int, unit_price: float}> $items
     *        Decoded items as returned by {@see decodeItems()}.
     *
     * @return float The summed subtotal.
     *
     * @example
     *   $subtotal = QuoteService::calculateSubtotal($items); // e.g. 225.00
     */
    public static function calculateSubtotal(array $items): float
    {
        return array_reduce(
            $items,
            static fn (float $carry, array $item): float =>
                $carry + ($item['unit_price'] * $item['qty']),
            0.0
        );
    }

    /**
     * Calculates the deposit due from a decoded items array.
     *
     * Line items flagged `full_deposit` must be paid in full upfront, so their
     * whole line total counts toward the deposit; every other item contributes
     * only $depositPct percent of its line total. The result is therefore:
     *
     *   deposit = Σ(flagged line totals) + depositPct% × Σ(unflagged line totals)
     *
     * When no item is flagged this reduces to the plain `subtotal × pct/100`.
     * Missing `full_deposit` keys are treated as false (not a full-deposit item).
     *
     * @param array<int, array{qty: int|float, unit_price: int|float, full_deposit?: bool}> $items
     *        Decoded items as returned by {@see decodeItems()}.
     * @param int $depositPct Deposit percentage (0–100) applied to unflagged items.
     *
     * @return float The deposit amount in dollars, rounded to 2 decimal places.
     *
     * @example
     *   // $50 item flagged full, $100 item at 50% → 50 + 50 = 100.00
     *   QuoteService::calculateDeposit([
     *       ['qty' => 1, 'unit_price' => 50.0, 'full_deposit' => true],
     *       ['qty' => 1, 'unit_price' => 100.0, 'full_deposit' => false],
     *   ], 50); // 100.00
     */
    public static function calculateDeposit(array $items, int $depositPct): float
    {
        $fullTotal      = 0.0;
        $remainingTotal = 0.0;

        foreach ($items as $item) {
            $lineTotal = (float) $item['unit_price'] * (int) $item['qty'];

            if (!empty($item['full_deposit'])) {
                $fullTotal += $lineTotal;
            } else {
                $remainingTotal += $lineTotal;
            }
        }

        return round($fullTotal + $remainingTotal * ($depositPct / 100), 2);
    }

    /**
     * Notifies the shop owner of quote/order activity by SMS and by a TTS
     * phone call reading the same message, via the Twilio REST API.
     *
     * Reads TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER from
     * the environment. If any of those values are absent, the notification is
     * silently skipped and a message is written to the PHP error log.
     *
     * The SMS goes to WHATSAPP_PHONE only, formatted to E.164 via
     * {@see TwilioService::formatNumber()}. Twilio HTTP errors are also
     * logged and swallowed so a failed SMS or call never breaks the
     * customer-facing flow.
     *
     * In addition to the SMS, this also places a voice call that reads
     * $message aloud via Twilio's TwiML `<Say>` verb — to WHATSAPP_PHONE, and
     * additionally to WHATSAPP_PHONE_2 when that's configured (e.g. a second
     * staff member's phone); leave WHATSAPP_PHONE_2 unset to call only the
     * primary number. Voice calls are exempt from A2P 10DLC campaign
     * registration (that approval process only governs SMS sent from a
     * 10DLC long code), so the call channel keeps working even while the SMS
     * campaign is pending approval — the two channels are independent and
     * both are expected to fire once SMS is also approved.
     *
     * Relayed through {@see \App\Support\CliRelay} when called from a web
     * request — this host's Apache-SAPI PHP has no cURL SSL backend, so the
     * `curl_exec()` below would otherwise sever the connection instead of
     * failing gracefully (this is how a real quote's payment-confirmation
     * owner email went missing: this SMS call crashed the request before the
     * email step ever ran). See docs/stripe-cli-relay.md.
     *
     * @param string $message The message to send by SMS and read aloud on the call.
     *
     * @return void
     *
     * @example
     *   QuoteService::notifyOwner('Deposit confirmed — Rosa García — 2026-09-01 — $75.00');
     */
    public static function notifyOwner(string $message): void
    {
        if (CliRelay::isNeeded()) {
            try {
                CliRelay::run(self::class, 'notifyOwner', func_get_args());
            } catch (\Throwable $e) {
                error_log('[QuoteService] CLI relay failed for notifyOwner(): ' . $e->getMessage());
            }
            return;
        }

        $sid   = (string) Config::get('TWILIO_ACCOUNT_SID',  '');
        $token = (string) Config::get('TWILIO_AUTH_TOKEN',   '');
        $from  = (string) Config::get('TWILIO_FROM_NUMBER',  '');

        if ($sid === '' || $token === '' || $from === '') {
            error_log('[QuoteService] Twilio not configured — skipping owner SMS notification.');
            return;
        }

        $rawPhone = (string) Config::get('WHATSAPP_PHONE', '');
        $to       = TwilioService::formatNumber($rawPhone);

        $url     = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $payload = http_build_query(['From' => $from, 'To' => $to, 'Body' => $message]);

        $ch = curl_init($url);
        if ($ch === false) {
            error_log('[QuoteService] curl_init failed — cannot send owner SMS.');
            return;
        }

        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
        curl_setopt($ch, CURLOPT_USERPWD,        "{$sid}:{$token}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        10);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log(
                "[QuoteService] Twilio SMS failed (HTTP {$httpCode}): "
                . (is_string($response) ? $response : '(no body)')
            );
        }

        // Also place a TTS phone call reading the same message, to every
        // configured recipient (WHATSAPP_PHONE, plus WHATSAPP_PHONE_2 when
        // set). Voice calls are exempt from A2P 10DLC campaign registration
        // (that system only governs SMS from a 10DLC long code), so this
        // channel keeps working even while the SMS campaign is pending
        // approval.
        $twiml = '<Response><Pause length="1"/><Say voice="Polly.Joanna" language="en-US">'
            . htmlspecialchars($message)
            . '</Say></Response>';

        $callRecipients = [$to];

        $secondaryRaw = (string) Config::get('WHATSAPP_PHONE_2', '');
        if ($secondaryRaw !== '') {
            $callRecipients[] = TwilioService::formatNumber($secondaryRaw);
        }

        foreach ($callRecipients as $callTo) {
            self::placeOwnerCall($sid, $token, $from, $callTo, $twiml);
        }
    }

    /**
     * Places one TTS phone call via Twilio's Calls API, reading $twiml aloud.
     *
     * Split out from {@see notifyOwner()} so the same call-placing logic can
     * run once per configured recipient without duplicating the cURL setup.
     * Failures are logged and swallowed, matching notifyOwner()'s contract —
     * one recipient's failure never blocks the call to another.
     *
     * @param string $sid   Twilio Account SID.
     * @param string $token Twilio Auth Token.
     * @param string $from  Caller ID — the Twilio number placing the call (E.164).
     * @param string $to    Recipient number (E.164).
     * @param string $twiml TwiML markup Twilio should execute for the call.
     *
     * @return void
     */
    private static function placeOwnerCall(string $sid, string $token, string $from, string $to, string $twiml): void
    {
        $callUrl     = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Calls.json";
        $callPayload = http_build_query(['From' => $from, 'To' => $to, 'Twiml' => $twiml]);

        $callCh = curl_init($callUrl);
        if ($callCh === false) {
            error_log("[QuoteService] curl_init failed — cannot place owner notification call to {$to}.");
            return;
        }

        curl_setopt($callCh, CURLOPT_POST,           true);
        curl_setopt($callCh, CURLOPT_POSTFIELDS,     $callPayload);
        curl_setopt($callCh, CURLOPT_USERPWD,        "{$sid}:{$token}");
        curl_setopt($callCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($callCh, CURLOPT_TIMEOUT,        10);

        $callResponse = curl_exec($callCh);
        $callHttpCode = (int) curl_getinfo($callCh, CURLINFO_HTTP_CODE);
        curl_close($callCh);

        if ($callHttpCode < 200 || $callHttpCode >= 300) {
            error_log(
                "[QuoteService] Twilio voice call to {$to} failed (HTTP {$callHttpCode}): "
                . (is_string($callResponse) ? $callResponse : '(no body)')
            );
        }
    }
}
