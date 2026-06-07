<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

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
     * @return array<int, array{description: string, qty: int, unit_price: float}>
     *         Decoded and type-coerced item list.
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
                'description' => (string) ($item['description'] ?? ''),
                'qty'         => (int)    ($item['qty']         ?? 0),
                'unit_price'  => (float)  ($item['unit_price']  ?? 0.0),
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
     * @param string $message The SMS body to send to the owner.
     *
     * @return void
     *
     * @example
     *   QuoteService::notifyOwner('Deposit confirmed — Rosa García — 2026-09-01 — $75.00');
     */
    public static function notifyOwner(string $message): void
    {
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
