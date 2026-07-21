<?php

declare(strict_types=1);

namespace App\Tests\Views;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the delivery_fee row on the public quote-acceptance page.
 *
 * Background — the bug this prevents:
 *   Oklahoma exempts separately-stated delivery charges from sales tax, but the
 *   quote path used to fold delivery into a hand-typed items_json line, so it
 *   was taxed along with the merchandise (see live quote #17). The fix adds a
 *   dedicated, untaxed delivery_fee column (migrations/018_add_quote_delivery_fee.sql)
 *   computed by App\Support\QuotePricing, and this view renders it as its own
 *   row — shown only when > 0 — between Tax and Total, with Total reflecting
 *   subtotal + tax + delivery in both cases.
 *
 * This test renders the real view file with the same extract()+ob_start()+
 * include() mechanism \App\Controllers\BaseController::render() uses (matching
 * the pattern in QuoteAcceptZeroTaxTotalTest), so it exercises the template's
 * actual PHP control flow rather than asserting against a hand-computed string.
 *
 * @see \App\Support\QuotePricing
 * @see views/public/quote-accept.php
 * @see \App\Tests\Views\QuoteAcceptZeroTaxTotalTest
 */
final class QuoteAcceptDeliveryRowTest extends TestCase
{
    /** Path to the view under test. */
    private const VIEW_PATH = __DIR__ . '/../../views/public/quote-accept.php';

    /**
     * Renders views/public/quote-accept.php with the given variables using
     * the same extract()+ob_start()+include() mechanism
     * \App\Controllers\BaseController::render() uses for the view layer, and
     * returns the captured HTML.
     *
     * @param array<string, mixed> $vars Variables to extract into the view's scope.
     * @return string Captured HTML output.
     */
    private static function renderView(array $vars): string
    {
        self::assertFileExists(self::VIEW_PATH, 'quote-accept.php view is missing: ' . self::VIEW_PATH);

        return (static function (string $_viewFile, array $_vars): string {
            extract($_vars);
            ob_start();
            include $_viewFile;
            return (string) ob_get_clean();
        })(self::VIEW_PATH, $vars);
    }

    /**
     * Extracts the inner markup of the first <tfoot>...</tfoot> block found
     * in $html.
     *
     * @param string $html Full rendered page HTML.
     * @return string Inner tfoot markup, or '' when no tfoot is present.
     */
    private static function extractTfoot(string $html): string
    {
        preg_match('/<tfoot>(.*)<\/tfoot>/s', $html, $matches);
        return $matches[1] ?? '';
    }

    /**
     * A quote with a positive delivery_fee must render a Delivery row, and the
     * Total must equal subtotal + tax + delivery (not just subtotal + tax).
     */
    public function testPositiveDeliveryFeeRendersDeliveryRowAndIsAddedToTotal(): void
    {
        $quote = [
            'token'          => 'test-token',
            'status'         => 'sent',
            'subtotal'       => 100.00,
            'tax_rate'       => 0.08,
            'tax_amount'     => 8.00,
            'delivery_fee'   => 15.00,
            'deposit_amount' => 30.00,
            'deposit_pct'    => 30,
            'event_date'     => null,
            'valid_until'    => null,
            'notes'          => null,
        ];

        $items = [
            ['description' => 'Bridal Bouquet', 'qty' => 1, 'unit_price' => 100.00, 'full_deposit' => false],
        ];

        $html = self::renderView([
            'quote'     => $quote,
            'items'     => $items,
            'csrfToken' => 'test-csrf-token',
            'expired'   => false,
            'lang'      => 'en',
            'startStep' => 'review',
        ]);

        $tfoot = self::extractTfoot($html);
        self::assertNotSame('', $tfoot, 'Expected a <tfoot> block in the rendered quote table.');

        self::assertMatchesRegularExpression(
            '/<tr>\s*<td[^>]*>\s*Delivery\s*<\/td>\s*<td[^>]*>\s*\$15\.00\s*<\/td>\s*<\/tr>/',
            $tfoot,
            'Expected a Delivery row of $15.00 in the tfoot when delivery_fee > 0.'
        );

        self::assertMatchesRegularExpression(
            '/<tr class="quote-total-row">\s*<td[^>]*>\s*Total\s*<\/td>\s*<td[^>]*>\s*\$123\.00\s*<\/td>\s*<\/tr>/',
            $tfoot,
            'Expected Total of $123.00 (subtotal $100 + tax $8 + delivery $15), not just subtotal + tax.'
        );

        // The Delivery row must come after Tax and before Total, matching the
        // Subtotal → Tax → Delivery → Total display order.
        $taxPos      = strpos($tfoot, 'Tax (');
        $deliveryPos = strpos($tfoot, 'Delivery');
        $totalPos    = strpos($tfoot, 'Total');
        self::assertIsInt($taxPos);
        self::assertIsInt($deliveryPos);
        self::assertIsInt($totalPos);
        self::assertTrue($taxPos < $deliveryPos && $deliveryPos < $totalPos, 'Expected row order Tax, Delivery, Total.');
    }

    /**
     * A quote with delivery_fee = 0.00 (the default for existing quotes, and
     * for any quote where the owner didn't set a delivery charge) must not
     * render a Delivery row, and Total must equal subtotal + tax only.
     */
    public function testZeroDeliveryFeeOmitsDeliveryRow(): void
    {
        $quote = [
            'token'          => 'test-token',
            'status'         => 'sent',
            'subtotal'       => 100.00,
            'tax_rate'       => 0.08,
            'tax_amount'     => 8.00,
            'delivery_fee'   => 0.00,
            'deposit_amount' => 30.00,
            'deposit_pct'    => 30,
            'event_date'     => null,
            'valid_until'    => null,
            'notes'          => null,
        ];

        $items = [
            ['description' => 'Bridal Bouquet', 'qty' => 1, 'unit_price' => 100.00, 'full_deposit' => false],
        ];

        $html = self::renderView([
            'quote'     => $quote,
            'items'     => $items,
            'csrfToken' => 'test-csrf-token',
            'expired'   => false,
            'lang'      => 'en',
            'startStep' => 'review',
        ]);

        $tfoot = self::extractTfoot($html);
        self::assertNotSame('', $tfoot, 'Expected a <tfoot> block in the rendered quote table.');

        self::assertStringNotContainsString(
            'Delivery',
            $tfoot,
            'A quote with delivery_fee = 0.00 must not render a Delivery row.'
        );

        self::assertMatchesRegularExpression(
            '/<tr class="quote-total-row">\s*<td[^>]*>\s*Total\s*<\/td>\s*<td[^>]*>\s*\$108\.00\s*<\/td>\s*<\/tr>/',
            $tfoot,
            'Expected Total of $108.00 (subtotal + tax only) when delivery_fee is 0.'
        );
    }
}
