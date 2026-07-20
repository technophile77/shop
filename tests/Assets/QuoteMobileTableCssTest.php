<?php

declare(strict_types=1);

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the mobile layout of the public quote table
 * (views/public/quote-accept.php + the `.quote-table*` rules in main.css).
 *
 * Background — the bug this prevents:
 *   The `<th>` elements for the qty/unit_price/subtotal columns carried hard
 *   pixel widths (60px/120px/120px) via inline `style` attributes. A media
 *   query cannot override an inline `style` without `!important`, so on a
 *   375px phone the table (300px+ of fixed columns, plus padding) overflowed
 *   `.quote-body`. `.quote-card` sets `overflow: hidden` (to clip the dark
 *   header's rounded corners), which silently clipped the overflow instead
 *   of scrolling it — customers could not see the Subtotal column or the
 *   order Total at all, and there was no way to scroll to reveal it.
 *
 *   The fix (1) moves the column widths into CSS classes
 *   (`.col-qty`/`.col-price`/`.col-amount`) that the mobile breakpoint can
 *   shrink, (2) wraps the line-item `<table>` in a `.quote-table-wrap` with
 *   `overflow-x: auto` as a scroll safety net, and (3) renders the totals
 *   (Subtotal/Tax/Delivery/Total) in a second, separate `<table>` that is a
 *   *sibling* of `.quote-table-wrap`, not a descendant of it — so the Total
 *   can never be dragged into the scrollable region and hidden along with
 *   the items.
 *
 * This parses the shipped CSS and the real view file so none of those
 * invariants can silently regress.
 *
 * @see \App\Tests\Assets\QuoteCardCssTest
 * @see views/public/quote-accept.php
 */
final class QuoteMobileTableCssTest extends TestCase
{
    /** Path to the public stylesheet that ships to the browser. */
    private const CSS_PATH = __DIR__ . '/../../public/assets/css/main.css';

    /** Path to the quote-acceptance view template. */
    private const VIEW_PATH = __DIR__ . '/../../views/public/quote-accept.php';

    /**
     * Load main.css once, asserting it exists and is non-empty.
     */
    private static function css(): string
    {
        $css = is_file(self::CSS_PATH) ? (string) file_get_contents(self::CSS_PATH) : '';
        self::assertNotSame('', $css, 'main.css is missing or empty: ' . self::CSS_PATH);
        return $css;
    }

    /**
     * Load the raw PHP source of quote-accept.php once, asserting it exists
     * and is non-empty. Read as source text (not rendered), since the
     * invariants under test here are markup/class-name shape, not
     * data-dependent output.
     */
    private static function viewSource(): string
    {
        $src = is_file(self::VIEW_PATH) ? (string) file_get_contents(self::VIEW_PATH) : '';
        self::assertNotSame('', $src, 'quote-accept.php view is missing: ' . self::VIEW_PATH);
        return $src;
    }

    /**
     * Return the inner declaration block of an exact simple selector.
     *
     * Matches `.foo { ... }` but not `.foo-bar`/`.foo.bar`, because the
     * trailing `{`/whitespace anchor ends the match at the selector token.
     * Mirrors the identically-named helper in QuoteCardCssTest.
     *
     * @param string $selector e.g. ".quote-table-wrap".
     * @return list<string>    Inner text of each matching block.
     */
    private static function blocksFor(string $css, string $selector): array
    {
        $pattern = '/(?:^|[};,{]|\s)' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
        preg_match_all($pattern, $css, $matches);
        return $matches[1];
    }

    /**
     * Extract the full text inside the first `@media (max-width: 768px) {
     * ... }` block, matching braces so nested rule blocks don't truncate
     * the extraction early (a naive `[^}]*` stops at the first nested `}`).
     *
     * @param string $css Full stylesheet source.
     * @return string     Inner text of the media block, or '' if not found.
     */
    private static function mobileBreakpointBlock(string $css): string
    {
        $start = strpos($css, '@media (max-width: 768px)');
        self::assertIsInt($start, 'Expected a `@media (max-width: 768px)` block in main.css.');

        $openBrace = strpos($css, '{', $start);
        self::assertIsInt($openBrace);

        $depth  = 0;
        $length = strlen($css);
        for ($i = $openBrace; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $openBrace + 1, $i - $openBrace - 1);
                }
            }
        }

        self::fail('Unbalanced braces while extracting the @media (max-width: 768px) block.');
    }

    /**
     * The line-items table must be wrapped in a horizontal-scroll container
     * with touch-friendly momentum scrolling, so overflow rows scroll
     * instead of being clipped by `.quote-card`'s `overflow: hidden`.
     */
    public function testQuoteTableWrapProvidesHorizontalScrollContainer(): void
    {
        $blocks = self::blocksFor(self::css(), '.quote-table-wrap');
        self::assertNotEmpty($blocks, 'No `.quote-table-wrap` rule found in main.css.');

        $block = implode("\n", $blocks);
        self::assertMatchesRegularExpression(
            '/overflow-x\s*:\s*auto\s*;/i',
            $block,
            '`.quote-table-wrap` must set `overflow-x: auto` so overflowing item rows scroll within '
            . 'their own container instead of being clipped by `.quote-card`\'s `overflow: hidden`.'
        );
        self::assertMatchesRegularExpression(
            '/-webkit-overflow-scrolling\s*:\s*touch\s*;/i',
            $block,
            '`.quote-table-wrap` must set `-webkit-overflow-scrolling: touch` for smooth momentum '
            . 'scrolling on iOS Safari, the primary target for this customer-facing page.'
        );

        self::assertStringContainsString(
            'class="quote-table-wrap"',
            self::viewSource(),
            'Expected the line-items <table> in quote-accept.php to be wrapped in a `.quote-table-wrap` element.'
        );
    }

    /**
     * The qty/unit_price/subtotal column widths must be defined in CSS
     * classes, not inline `style` attributes, so the mobile breakpoint can
     * override them without `!important`.
     */
    public function testColumnWidthsAreNotHardCodedInlineInTemplate(): void
    {
        $src = self::viewSource();

        self::assertDoesNotMatchRegularExpression(
            '/style="[^"]*width\s*:\s*60px/',
            $src,
            'Found an inline `width: 60px` style — the qty column width must live in the `.col-qty` '
            . 'CSS class so the mobile breakpoint can override it.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/style="[^"]*width\s*:\s*120px/',
            $src,
            'Found an inline `width: 120px` style — the price/amount column widths must live in CSS '
            . 'classes so the mobile breakpoint can override them.'
        );

        foreach (['col-desc', 'col-qty', 'col-price', 'col-amount'] as $class) {
            self::assertMatchesRegularExpression(
                '/class="col-' . preg_quote(substr($class, 4), '/') . '"/',
                $src,
                "Expected a `{$class}` class on the corresponding line-item column."
            );
        }
    }

    /**
     * At the mobile breakpoint, the fixed columns must be defined narrower
     * than their desktop widths, and cell padding/font-size reduced, so the
     * table has a real chance of fitting a 320-375px viewport without
     * relying solely on the scroll fallback.
     */
    public function testMobileBreakpointShrinksColumnsAndPadding(): void
    {
        $mobile = self::mobileBreakpointBlock(self::css());

        self::assertMatchesRegularExpression(
            '/\.quote-table\s+\.col-qty\s*\{[^}]*width\s*:\s*(\d+)px/',
            $mobile,
            'Expected a mobile override narrowing `.col-qty`.'
        );
        preg_match('/\.quote-table\s+\.col-qty\s*\{[^}]*width\s*:\s*(\d+)px/', $mobile, $qtyMatch);
        self::assertLessThan(60, (int) $qtyMatch[1], 'Mobile `.col-qty` width must be narrower than the 60px desktop width.');

        self::assertMatchesRegularExpression(
            '/\.quote-table\s+\.col-price,\s*\n?\s*\.quote-table\s+\.col-amount\s*\{[^}]*width\s*:\s*(\d+)px/',
            $mobile,
            'Expected a mobile override narrowing `.col-price`/`.col-amount`.'
        );
        preg_match(
            '/\.quote-table\s+\.col-price,\s*\n?\s*\.quote-table\s+\.col-amount\s*\{[^}]*width\s*:\s*(\d+)px/',
            $mobile,
            $priceMatch
        );
        self::assertLessThan(
            120,
            (int) $priceMatch[1],
            'Mobile `.col-price`/`.col-amount` width must be narrower than the 120px desktop width.'
        );

        self::assertMatchesRegularExpression(
            '/\.quote-table\s+th,\s*\n?\s*\.quote-table\s+td\s*\{[^}]*padding\s*:/',
            $mobile,
            'Expected mobile padding overrides for `.quote-table th`/`.quote-table td`.'
        );
    }

    /**
     * The totals table must be structurally separate from — not a
     * descendant of — the scrollable `.quote-table-wrap`, so the Total can
     * never scroll out of view along with the items.
     */
    public function testTotalsTableIsNotInsideTheScrollWrapper(): void
    {
        $src = self::viewSource();

        $wrapCloseComment = strpos($src, '<!-- /.quote-table-wrap -->');
        self::assertIsInt($wrapCloseComment, 'Expected a `.quote-table-wrap` closing comment marking its extent.');

        $totalsTablePos = strpos($src, 'quote-totals-table');
        self::assertIsInt($totalsTablePos, 'Expected a `.quote-totals-table` element in quote-accept.php.');

        self::assertGreaterThan(
            $wrapCloseComment,
            $totalsTablePos,
            'The totals table must appear after (as a sibling of, not nested inside) .quote-table-wrap, '
            . 'otherwise the Total row could scroll off-screen with the line items.'
        );

        $tfootPos = strpos($src, '<tfoot>');
        self::assertIsInt($tfootPos);
        self::assertGreaterThan(
            $wrapCloseComment,
            $tfootPos,
            'The <tfoot> (totals) markup must not be nested inside .quote-table-wrap.'
        );
    }

    /**
     * At the mobile breakpoint, each totals row must read as a full-width
     * label→value pair (label growing to fill the row, value shrinking to
     * its own content) rather than depending on the 120px fixed value
     * column used at desktop widths, which would eat into the little space
     * a 320px viewport has.
     */
    public function testMobileBreakpointRestructuresTotalsRowsToFullWidthPairs(): void
    {
        $mobile = self::mobileBreakpointBlock(self::css());

        $labelBlocks = self::blocksFor($mobile, '.quote-total-label');
        self::assertNotEmpty($labelBlocks, 'Expected a mobile `.quote-total-label` override.');
        self::assertMatchesRegularExpression(
            '/text-align\s*:\s*left\s*;/i',
            implode("\n", $labelBlocks),
            'Expected `.quote-total-label` to switch to `text-align: left` on mobile.'
        );

        $valueBlocks = self::blocksFor($mobile, '.quote-total-value');
        self::assertNotEmpty($valueBlocks, 'Expected a mobile `.quote-total-value` override.');
        self::assertMatchesRegularExpression(
            '/width\s*:\s*auto\s*;/i',
            implode("\n", $valueBlocks),
            'Expected `.quote-total-value` to drop its fixed 120px width on mobile so the totals row '
            . 'never needs horizontal scrolling.'
        );
    }
}
