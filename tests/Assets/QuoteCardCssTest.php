<?php

declare(strict_types=1);

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the public quote card (.quote-card) in main.css.
 *
 * Background — the bug this prevents:
 *   The public quote page renders inside `.section--dark`, which sets
 *   `color: var(--color-text-light)`. `.quote-card` has a white background, so
 *   unless it sets its own text color, line-item rows (`.quote-table td`, which
 *   have no explicit color) inherit the light color and render white-on-white —
 *   the customer sees only the (explicitly-coloured) totals, not the flowers.
 *   The fix is an explicit `color: var(--color-text-dark)` on `.quote-card`.
 *
 * This parses the shipped CSS so the invariant cannot silently regress.
 */
final class QuoteCardCssTest extends TestCase
{
    /** Path to the public stylesheet that ships to the browser. */
    private const CSS_PATH = __DIR__ . '/../../public/assets/css/main.css';

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
     * Return the inner declaration block of an exact simple selector.
     *
     * Matches `.quote-card { ... }` but not `.quote-card-foo`/`.quote-card.bar`,
     * because the trailing `{`/whitespace anchor ends the match at the selector
     * token.
     *
     * @param string $selector e.g. ".quote-card".
     * @return list<string>    Inner text of each matching block.
     */
    private static function blocksFor(string $css, string $selector): array
    {
        $pattern = '/(?:^|[};,{]|\s)' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
        preg_match_all($pattern, $css, $matches);
        return $matches[1];
    }

    /**
     * The quote card must set an explicit dark text colour so item rows are not
     * left inheriting the surrounding dark section's light colour.
     */
    public function testQuoteCardSetsDarkTextColor(): void
    {
        $blocks = self::blocksFor(self::css(), '.quote-card');

        self::assertNotEmpty($blocks, 'No `.quote-card` rule found in main.css.');

        $hasDarkColor = false;
        foreach ($blocks as $block) {
            if (preg_match('/color\s*:\s*var\(\s*--color-text-dark\s*\)\s*;/i', $block) === 1) {
                $hasDarkColor = true;
                break;
            }
        }

        self::assertTrue(
            $hasDarkColor,
            'The `.quote-card` rule must set `color: var(--color-text-dark)`, otherwise '
            . 'line items inherit the light color from `.section--dark` and render '
            . 'white-on-white (invisible flowers, only totals show).'
        );
    }
}
