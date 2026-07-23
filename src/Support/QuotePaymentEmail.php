<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds the subject and HTML body for the owner-facing "quote payment
 * received" notification email.
 *
 * This is the email counterpart to {@see \App\Services\QuoteService::notifyOwner()}'s
 * SMS alert — it exists so a Stripe quote payment reaches the owner through a
 * second channel with richer detail (line items, event date, payment intent
 * id) than an SMS can carry. Purely functional: no database, session, SMTP,
 * or Config access. The caller is responsible for resolving `APP_URL` and
 * passing it in as `$appUrl`, and for wrapping {@see bodyHtml()}'s output with
 * {@see \App\Services\MailService::buildHtml()} before sending.
 *
 * @see \App\Controllers\StripeController
 * @see \App\Services\MailService::send()
 */
final class QuotePaymentEmail
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Builds the notification email subject line.
     *
     * Identifies the quote by id and customer, and states the amount Stripe
     * actually charged (not the quote's full total, which may differ when the
     * customer paid only a deposit). The subject is a plain mail header, not
     * HTML, so it is intentionally not escaped.
     *
     * @param array<string, mixed> $quote      A quote row from {@see \App\Models\Quote::findByToken()}.
     *                                         Uses `id` and `customer_name`; `customer_name`
     *                                         falls back to 'Customer' when missing or blank.
     * @param float                $amountPaid Amount Stripe charged, in dollars.
     *
     * @return string The subject line.
     *
     * @example
     *   QuotePaymentEmail::subject(['id' => 37, 'customer_name' => 'Rosa García'], 310.0);
     *   // 'Stripe Payment Received — Quote #37 — Rosa García — $310.00'
     */
    public static function subject(array $quote, float $amountPaid): string
    {
        $id           = (int) ($quote['id'] ?? 0);
        $customerName = self::customerName($quote);
        $amount       = '$' . number_format($amountPaid, 2);

        return "Stripe Payment Received — Quote #{$id} — {$customerName} — {$amount}";
    }

    /**
     * Builds the inner HTML fragment for the notification email body.
     *
     * Returns only the content to place inside {@see \App\Services\MailService::buildHtml()}'s
     * template — not a full HTML document. Every row is omitted when its
     * underlying value is blank, so the table degrades gracefully for
     * incomplete quotes (missing phone, no event date, etc).
     *
     * @param array<string, mixed> $quote           A quote row from {@see \App\Models\Quote::findByToken()}.
     *                                               Uses `id`, `customer_name`, `customer_email`,
     *                                               `customer_phone`, `event_date`, `subtotal`,
     *                                               `tax_amount`, `delivery_fee`, and `token`.
     * @param list<array{description: string, qty: int, unit_price: float, full_deposit: bool}> $items
     *                                               Decoded line items from {@see \App\Services\QuoteService::decodeItems()}.
     *                                               May be empty, in which case the 'Items' row is omitted.
     * @param float                 $amountPaid      Amount Stripe actually charged, in dollars.
     * @param string                $paymentIntentId Stripe PaymentIntent id; omitted from the body when blank.
     * @param string                $appUrl          Absolute application base URL (e.g. 'https://flowers.example.com'),
     *                                                used to build the admin and customer links in the footer.
     *
     * @return string The inner HTML fragment.
     *
     * @example
     *   $html = QuotePaymentEmail::bodyHtml(
     *       ['id' => 37, 'customer_name' => 'Rosa García', 'customer_email' => 'rosa@example.com', 'token' => 'abc123'],
     *       [['description' => 'Bridal Bouquet', 'qty' => 1, 'unit_price' => 310.0, 'full_deposit' => true]],
     *       310.0,
     *       'pi_123',
     *       'https://flowers.example.com'
     *   );
     */
    public static function bodyHtml(
        array $quote,
        array $items,
        float $amountPaid,
        string $paymentIntentId,
        string $appUrl,
    ): string {
        $id           = (int) ($quote['id'] ?? 0);
        $customerName = self::customerName($quote);
        $customerEmail = (string) ($quote['customer_email'] ?? '');
        $customerPhone = (string) ($quote['customer_phone'] ?? '');
        $token         = (string) ($quote['token'] ?? '');

        $quoteTotal = (float) ($quote['subtotal'] ?? 0)
            + (float) ($quote['tax_amount'] ?? 0)
            + (float) ($quote['delivery_fee'] ?? 0);

        $rows  = self::row('Amount Paid', '$' . number_format($amountPaid, 2));
        $rows .= round($quoteTotal, 2) !== round($amountPaid, 2)
            ? self::row('Quote Total', '$' . number_format($quoteTotal, 2))
            : '';
        $rows .= self::row('Quote #', (string) $id);
        $rows .= self::row('Customer', $customerName);
        $rows .= self::row('Email', $customerEmail);
        $rows .= self::row('Phone', $customerPhone);
        $rows .= self::row('Event Date', self::formatEventDate((string) ($quote['event_date'] ?? '')));
        $rows .= self::row('Items', self::itemLines($items));
        $rows .= self::row('Stripe Payment', $paymentIntentId);

        $appUrl = rtrim($appUrl, '/');
        $links  = '<a href="' . htmlspecialchars($appUrl . '/admin/quotes/' . $id) . '">admin panel</a>';
        if ($token !== '') {
            $links .= ' &middot; <a href="' . htmlspecialchars($appUrl . '/quote/' . $token) . '">customer\'s quote page</a>';
        }

        return '<h2 style="margin:0 0 1rem;font-size:1.2rem">Quote Payment Received 🌸</h2>'
            . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
            . '<p style="margin:1.5rem 0 0;font-size:0.85rem;color:#999">' . $links . '</p>';
    }

    /**
     * Resolves the customer's display name, falling back to 'Customer' when absent.
     *
     * @param array<string, mixed> $quote A quote row; uses `customer_name`.
     *
     * @return string The display name, never blank.
     */
    private static function customerName(array $quote): string
    {
        $name = trim((string) ($quote['customer_name'] ?? ''));

        return $name !== '' ? $name : 'Customer';
    }

    /**
     * Formats the quote's event date for display, tolerating malformed input.
     *
     * Parses `$eventDate` as a 'Y-m-d' string and renders it as 'D M j, Y'
     * (e.g. 'Tue Sep 1, 2026'). When it doesn't parse in that format, the raw
     * string is returned verbatim so the owner still sees whatever was stored.
     *
     * @param string $eventDate Raw `event_date` column value; may be blank.
     *
     * @return string The formatted date, the raw string, or '' when blank.
     */
    private static function formatEventDate(string $eventDate): string
    {
        if ($eventDate === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $eventDate);

        return $date !== false ? $date->format('D M j, Y') : $eventDate;
    }

    /**
     * Renders decoded quote line items as one line per item.
     *
     * Each line reads "{description} × {qty} — ${unit_price} each"; lines are
     * joined with newlines so {@see row()}'s `nl2br` renders them as separate
     * lines in the email.
     *
     * @param list<array{description: string, qty: int, unit_price: float, full_deposit: bool}> $items
     *        Decoded items from {@see \App\Services\QuoteService::decodeItems()}.
     *
     * @return string The joined item lines; '' when $items is empty.
     */
    private static function itemLines(array $items): string
    {
        return implode("\n", array_map(
            static fn (array $item): string => sprintf(
                '%s × %d — $%s each',
                (string) ($item['description'] ?? ''),
                (int) ($item['qty'] ?? 0),
                number_format((float) ($item['unit_price'] ?? 0), 2)
            ),
            $items
        ));
    }

    /**
     * Renders one label/value table row, escaping both, or '' when the value is blank.
     *
     * Mirrors the row markup used by {@see \App\Controllers\StripeController::notifyOwnerShopOrder()}
     * so the two owner notification emails share a visual style.
     *
     * @param string $label Row label, e.g. 'Customer'.
     * @param string $value Row value; the row is omitted entirely when this is ''.
     *
     * @return string The `<tr>…</tr>` markup, or '' when $value is blank.
     */
    private static function row(string $label, string $value): string
    {
        if ($value === '') {
            return '';
        }

        return '<tr>'
            . '<td style="padding:6px 12px;font-weight:600;color:#555;white-space:nowrap">'
            . htmlspecialchars($label) . '</td>'
            . '<td style="padding:6px 12px;color:#1a1a1a">'
            . nl2br(htmlspecialchars($value)) . '</td>'
            . '</tr>';
    }
}
