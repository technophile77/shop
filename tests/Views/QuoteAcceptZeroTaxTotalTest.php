<?php

declare(strict_types=1);

namespace App\Tests\Views;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the totals footer of the public quote-acceptance page.
 *
 * Background — the bug this prevents:
 *   views/public/quote-accept.php nested its "Total" `<tr>` inside the
 *   `if ($taxAmount > 0)` block that also gates the "Tax" row. A quote with
 *   no tax configured (tax_amount = 0.00, the common case for this business)
 *   rendered a Subtotal row and no Total row at all — the customer saw no
 *   total. The fix renders the Total row unconditionally, using $quoteTotal
 *   (subtotal + taxAmount, which equals the subtotal when tax is zero), while
 *   keeping the Tax row itself conditional on $taxAmount > 0.
 *
 * This test renders the real view file with the same extract()+ob_start()+
 * include() mechanism \App\Controllers\BaseController::render() uses, so it
 * exercises the template's actual PHP control flow rather than asserting
 * against a hand-computed string. It fails if the Total `<tr>` is ever moved
 * back inside the tax conditional.
 *
 * @see \App\Controllers\BaseController::render()
 * @see views/public/quote-accept.php
 */
final class QuoteAcceptZeroTaxTotalTest extends TestCase
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
     * A quote with zero tax (tax_amount = 0.00) must still render a visible
     * Total row equal to the subtotal, not just a Subtotal row with nothing
     * after it.
     */
    public function testZeroTaxQuoteStillRendersATotalRow(): void
    {
        $quote = [
            'token'          => 'test-token',
            'status'         => 'sent',
            'subtotal'       => 120.00,
            'deposit_amount' => 36.00,
            'deposit_pct'    => 30,
            'tax_rate'       => 0.0,
            'tax_amount'     => 0.0,
            'event_date'     => null,
            'valid_until'    => null,
            'notes'          => null,
        ];

        $items = [
            ['description' => 'Dozen Roses', 'qty' => 1, 'unit_price' => 120.00, 'full_deposit' => false],
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
            'Tax (',
            $tfoot,
            'A zero-tax quote must not render a Tax row.'
        );

        self::assertMatchesRegularExpression(
            '/<tr class="quote-total-row">\s*<td[^>]*>\s*Total\s*<\/td>\s*<td[^>]*>\s*\$120\.00\s*<\/td>\s*<\/tr>/',
            $tfoot,
            'Expected an always-visible Total row of $120.00 in the tfoot for a zero-tax quote. '
            . 'If this fails, the Total <tr> was likely moved back inside the `$taxAmount > 0` conditional.'
        );
    }

    /**
     * When tax IS present, both the Tax row and the Total row (reflecting
     * subtotal + tax) must render.
     */
    public function testNonZeroTaxQuoteRendersBothTaxAndTotalRows(): void
    {
        $quote = [
            'token'          => 'test-token',
            'status'         => 'sent',
            'subtotal'       => 100.00,
            'deposit_amount' => 30.00,
            'deposit_pct'    => 30,
            'tax_rate'       => 0.08,
            'tax_amount'     => 8.00,
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

        self::assertStringContainsString('Tax (8.000%)', $tfoot);

        self::assertMatchesRegularExpression(
            '/<tr class="quote-total-row">\s*<td[^>]*>\s*Total\s*<\/td>\s*<td[^>]*>\s*\$108\.00\s*<\/td>\s*<\/tr>/',
            $tfoot,
            'Expected a Total row of $108.00 (subtotal + tax) in the tfoot.'
        );
    }
}
