<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\QuotePaymentEmail;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuotePaymentEmail.
 *
 * subject(): verifies the quote id, customer name, and Stripe-charged amount
 * appear (not the quote total), and the 'Customer' fallback for a missing name.
 * bodyHtml(): verifies row presence/omission for each optional field, the
 * event-date formatting (and its malformed-input fallback), HTML-escaping of
 * customer-supplied text, and the admin/customer link footer.
 *
 * @see \App\Support\QuotePaymentEmail
 */
class QuotePaymentEmailTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeQuote(array $overrides = []): array
    {
        return array_merge([
            'id'              => 37,
            'token'           => 'abc123def456',
            'customer_name'   => 'Rosa García',
            'customer_email'  => 'rosa@example.com',
            'customer_phone'  => '555-123-4567',
            'event_date'      => '2026-09-01',
            'subtotal'        => 280.00,
            'tax_amount'      => 20.00,
            'delivery_fee'    => 10.00,
        ], $overrides);
    }

    private function makeItems(): array
    {
        return [
            ['description' => 'Bridal Bouquet', 'qty' => 1, 'unit_price' => 200.00, 'full_deposit' => true],
            ['description' => 'Boutonniere',    'qty' => 4, 'unit_price' => 20.00,  'full_deposit' => false],
        ];
    }

    // -------------------------------------------------------------------------
    // subject()
    // -------------------------------------------------------------------------

    public function testSubjectContainsIdCustomerAndChargedAmount(): void
    {
        // Quote total is 280 + 20 + 10 = 310, but Stripe only charged a 100 deposit —
        // the subject must report what was actually charged, not the quote total.
        $subject = QuotePaymentEmail::subject($this->makeQuote(), 100.00);

        $this->assertStringContainsString('Quote #37', $subject);
        $this->assertStringContainsString('Rosa García', $subject);
        $this->assertStringContainsString('$100.00', $subject);
        $this->assertStringNotContainsString('$310.00', $subject);
    }

    public function testSubjectFallsBackToCustomerWhenNameMissing(): void
    {
        $quote   = $this->makeQuote(['customer_name' => '']);
        $subject = QuotePaymentEmail::subject($quote, 100.00);

        $this->assertStringContainsString('Customer', $subject);
    }

    // -------------------------------------------------------------------------
    // bodyHtml() — core content
    // -------------------------------------------------------------------------

    public function testBodyHtmlContainsCoreDetails(): void
    {
        $html = QuotePaymentEmail::bodyHtml(
            $this->makeQuote(),
            $this->makeItems(),
            310.00,
            'pi_123456',
            'https://flowers.example.com'
        );

        $this->assertStringContainsString('37', $html);
        $this->assertStringContainsString('rosa@example.com', $html);
        $this->assertStringContainsString('Bridal Bouquet', $html);
        $this->assertStringContainsString('Boutonniere', $html);
        $this->assertStringContainsString('pi_123456', $html);
        $this->assertStringContainsString('/admin/quotes/37', $html);
        $this->assertStringContainsString('/quote/abc123def456', $html);
    }

    // -------------------------------------------------------------------------
    // bodyHtml() — row omission
    // -------------------------------------------------------------------------

    public function testMissingPhoneOmitsPhoneRow(): void
    {
        $quote = $this->makeQuote(['customer_phone' => '']);
        $html  = QuotePaymentEmail::bodyHtml($quote, $this->makeItems(), 310.00, 'pi_123', 'https://flowers.example.com');

        $this->assertStringNotContainsString('Phone', $html);
    }

    public function testMissingEventDateOmitsEventDateRow(): void
    {
        $quote = $this->makeQuote(['event_date' => null]);
        $html  = QuotePaymentEmail::bodyHtml($quote, $this->makeItems(), 310.00, 'pi_123', 'https://flowers.example.com');

        $this->assertStringNotContainsString('Event Date', $html);
    }

    public function testEmptyItemsOmitsItemsRowWithoutError(): void
    {
        $html = QuotePaymentEmail::bodyHtml($this->makeQuote(), [], 310.00, 'pi_123', 'https://flowers.example.com');

        $this->assertStringNotContainsString('Items', $html);
    }

    public function testBlankTokenOmitsCustomerQuoteLink(): void
    {
        $quote = $this->makeQuote(['token' => '']);
        $html  = QuotePaymentEmail::bodyHtml($quote, $this->makeItems(), 310.00, 'pi_123', 'https://flowers.example.com');

        $this->assertStringNotContainsString('/quote/', $html);
        $this->assertStringContainsString('/admin/quotes/37', $html);
    }

    // -------------------------------------------------------------------------
    // bodyHtml() — Quote Total row
    // -------------------------------------------------------------------------

    public function testQuoteTotalRowAbsentWhenEqualToAmountPaid(): void
    {
        // subtotal(280) + tax(20) + delivery(10) = 310, amountPaid = 310 → no diff.
        $html = QuotePaymentEmail::bodyHtml($this->makeQuote(), $this->makeItems(), 310.00, 'pi_123', 'https://flowers.example.com');

        $this->assertStringNotContainsString('Quote Total', $html);
    }

    public function testQuoteTotalRowPresentWhenDifferentFromAmountPaid(): void
    {
        // A 100 deposit against a 310 quote total — the two differ, so the row appears.
        $html = QuotePaymentEmail::bodyHtml($this->makeQuote(), $this->makeItems(), 100.00, 'pi_123', 'https://flowers.example.com');

        $this->assertStringContainsString('Quote Total', $html);
        $this->assertStringContainsString('$310.00', $html);
    }

    // -------------------------------------------------------------------------
    // bodyHtml() — event date formatting
    // -------------------------------------------------------------------------

    public function testValidEventDateFormatted(): void
    {
        // 2026-09-01 is a Tuesday (independently verifiable via `date`/cal), so
        // the expected string below is derived from the calendar, not from
        // running the code under test.
        $quote = $this->makeQuote(['event_date' => '2026-09-01']);
        $html  = QuotePaymentEmail::bodyHtml($quote, $this->makeItems(), 310.00, 'pi_123', 'https://flowers.example.com');

        $this->assertStringContainsString('Tue Sep 1, 2026', $html);
    }

    public function testMalformedEventDateRendersVerbatim(): void
    {
        $quote = $this->makeQuote(['event_date' => 'not-a-date']);
        $html  = QuotePaymentEmail::bodyHtml($quote, $this->makeItems(), 310.00, 'pi_123', 'https://flowers.example.com');

        $this->assertStringContainsString('not-a-date', $html);
    }

    // -------------------------------------------------------------------------
    // bodyHtml() — HTML escaping
    // -------------------------------------------------------------------------

    public function testCustomerNameIsEscaped(): void
    {
        $quote = $this->makeQuote(['customer_name' => '<script>alert(1)</script> & co']);
        $html  = QuotePaymentEmail::bodyHtml($quote, $this->makeItems(), 310.00, 'pi_123', 'https://flowers.example.com');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }
}
