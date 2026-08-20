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
     * Sends an SMS notification to the shop owner via the Twilio REST API.
     *
     * Reads TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER from
     * the environment. If any of those values are absent, the notification is
     * silently skipped and a message is written to the PHP error log.
     *
     * The recipient number is WHATSAPP_PHONE prefixed with '+1'. Twilio HTTP
     * errors are also logged and swallowed so a failed SMS never breaks the
     * customer-facing flow.
     *
     * Relayed through {@see \App\Support\CliRelay} when called from a web
     * request — this host's Apache-SAPI PHP has no cURL SSL backend, so the
     * `curl_exec()` below would otherwise sever the connection instead of
     * failing gracefully (this is how a real quote's payment-confirmation
     * owner email went missing: this SMS call crashed the request before the
     * email step ever ran). See docs/stripe-cli-relay.md.
     *
     * @param string $message The SMS body to send to the owner.
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
        $to       = '+1' . $rawPhone;

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
    }
}
