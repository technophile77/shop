<?php

declare(strict_types=1);

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the mobile nav drawer (.nav-mobile) in main.css.
 *
 * Background — the bug this prevents:
 *   The closed drawer is invisible-but-present (opacity:0), and the mobile
 *   media query (max-width:768px) forces `.nav-mobile { display: flex }`,
 *   overriding the base `display: none`. With no `pointer-events: none` on the
 *   closed state, that fixed, full-width, z-index:999 overlay sits over the top
 *   of the viewport and silently swallows clicks — routing taps on links/icons
 *   underneath (e.g. the footer Facebook/Instagram icons) to whichever drawer
 *   link happened to be at that coordinate. The fix is `pointer-events: none`
 *   while closed and `pointer-events: auto` on `.is-open`.
 *
 * These tests parse the actual shipped CSS so the invariant cannot silently
 * regress if someone edits the drawer rules.
 */
final class NavDrawerCssTest extends TestCase
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
     * Return every declaration block whose selector list contains exactly the
     * given simple selector as a standalone selector token.
     *
     * Matches `.nav-mobile { ... }` but NOT `.nav-mobile.is-open { ... }` or
     * `.nav-mobile-links { ... }`, because the trailing `{`/whitespace anchor
     * stops the match at the end of the selector token.
     *
     * @param string $selector e.g. ".nav-mobile" or ".nav-mobile.is-open".
     * @return list<string>    The inner text of each matching `{ ... }` block.
     *
     * @example
     *   self::blocksFor($css, '.nav-mobile.is-open'); // ['  transform: ...; ']
     */
    private static function blocksFor(string $css, string $selector): array
    {
        $pattern = '/(?:^|[};,{]|\s)' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
        preg_match_all($pattern, $css, $matches);
        return $matches[1];
    }

    /**
     * True when any of the blocks declares `$prop: $value` (whitespace-tolerant).
     *
     * @param list<string> $blocks Declaration-block bodies from blocksFor().
     */
    private static function anyDeclares(array $blocks, string $prop, string $value): bool
    {
        $needle = '/' . preg_quote($prop, '/') . '\s*:\s*' . preg_quote($value, '/') . '\s*;/i';
        foreach ($blocks as $block) {
            if (preg_match($needle, $block) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * The closed drawer must not capture pointer events.
     *
     * This is the core regression assertion: even though the media query forces
     * display:flex on mobile, the closed `.nav-mobile` must declare
     * `pointer-events: none` so the invisible overlay is click-through.
     */
    public function testClosedDrawerIsClickThrough(): void
    {
        $blocks = self::blocksFor(self::css(), '.nav-mobile');

        self::assertNotEmpty($blocks, 'No `.nav-mobile` rule found in main.css.');
        self::assertTrue(
            self::anyDeclares($blocks, 'pointer-events', 'none'),
            'The closed `.nav-mobile` must set `pointer-events: none`, otherwise the '
            . 'invisible mobile overlay swallows clicks meant for elements beneath it.'
        );
    }

    /**
     * The closed drawer is invisible (opacity:0) — this is the condition that
     * makes the missing pointer-events:none dangerous, so we assert it stays so.
     */
    public function testClosedDrawerIsInvisible(): void
    {
        $blocks = self::blocksFor(self::css(), '.nav-mobile');

        self::assertTrue(
            self::anyDeclares($blocks, 'opacity', '0'),
            'The closed `.nav-mobile` is expected to be opacity:0 (invisible).'
        );
    }

    /**
     * The open drawer must re-enable pointer events so its own links work.
     */
    public function testOpenDrawerIsInteractive(): void
    {
        $blocks = self::blocksFor(self::css(), '.nav-mobile.is-open');

        self::assertNotEmpty($blocks, 'No `.nav-mobile.is-open` rule found in main.css.');
        self::assertTrue(
            self::anyDeclares($blocks, 'pointer-events', 'auto'),
            'The open `.nav-mobile.is-open` must set `pointer-events: auto` so its '
            . 'links are clickable once the drawer is opened.'
        );
    }
}
