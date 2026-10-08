<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\PageTitle;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Unit tests for App\Support\PageTitle::compose() — pure, no DB.
 *
 * compose() exists because the public layout used to print
 * "{page title} | {BUSINESS_NAME}" unconditionally, so every page whose title
 * already was the business name (the home page, or any page that fell back to
 * BUSINESS_NAME) rendered "Perla's Flowers | Perla's Flowers". The table-driven
 * tests pin each rule with literal expected strings, and one fixed-seed
 * stochastic sweep checks the rules over random titles and site names (this
 * repo keeps stochastic sweeps inline in the unit suite — see StockCheckTest).
 * It draws from its own seeded Random\Randomizer rather than mt_srand(), so the
 * global mt_rand() stream that other tests (e.g. LocalAreasIntegrityTest) rely
 * on is untouched.
 *
 * @see \App\Support\PageTitle
 */
final class PageTitleTest extends TestCase
{
    /** Seed for the stochastic test; quoted in failure messages so a run can be replayed. */
    private const SEED = 20261006;

    /** Randomised iterations for the stochastic test (each runs every rule once). */
    private const ITERATIONS = 300;

    /** The business name used by the table-driven tests. */
    private const SITE = "Perla's Flowers";

    /** Characters PHP's trim() strips. */
    private const WHITESPACE = [' ', "\t", "\n", "\r", "\x0B"];

    /** Letters whose upper and lower case map onto each other one-to-one. */
    private const CASED_CHARS = ['a', 'b', 'k', 'z', 'A', 'Z', 'ñ', 'Ñ', 'é', 'É', 'á', 'Á', 'ü', 'Ü'];

    /** Characters without case, including the separator itself, markup and an emoji. */
    private const UNCASED_CHARS = ['0', '7', '-', '.', ',', '|', '&', '<', '>', '"', "'", '—', '¡', '🌹'];

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    /**
     * Pick one element of a non-empty list.
     *
     * @param list<string> $items
     */
    private static function pick(Randomizer $rng, array $items): string
    {
        return $items[$rng->getInt(0, count($items) - 1)];
    }

    /** Random run of 0 to $max whitespace characters (so '' occurs). */
    private static function randomWhitespace(Randomizer $rng, int $max): string
    {
        $chars = [];
        for ($i = $rng->getInt(0, $max); $i > 0; $i--) {
            $chars[] = self::pick($rng, self::WHITESPACE);
        }

        return implode('', $chars);
    }

    /**
     * Random 1-20 character text that starts and ends with a non-whitespace
     * character but may contain spaces inside, so it is its own trimmed form.
     */
    private static function randomCore(Randomizer $rng): string
    {
        $alphabet = [...self::CASED_CHARS, ...self::UNCASED_CHARS];
        $length   = $rng->getInt(1, 20);

        $chars = [];
        for ($i = 0; $i < $length; $i++) {
            $interior = $i > 0 && $i < $length - 1;
            $chars[]  = $interior && $rng->getInt(0, 4) === 0 ? ' ' : self::pick($rng, $alphabet);
        }

        return implode('', $chars);
    }

    /** Random 1-4 non-whitespace characters. */
    private static function randomSuffix(Randomizer $rng): string
    {
        $alphabet = [...self::CASED_CHARS, ...self::UNCASED_CHARS];

        $chars = [];
        for ($i = $rng->getInt(1, 4); $i > 0; $i--) {
            $chars[] = self::pick($rng, $alphabet);
        }

        return implode('', $chars);
    }

    /** Re-case every character of $text at random: upper, lower or untouched. */
    private static function scrambleCase(Randomizer $rng, string $text): string
    {
        $out = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $out .= match ($rng->getInt(0, 2)) {
                0       => mb_strtoupper($char, 'UTF-8'),
                1       => mb_strtolower($char, 'UTF-8'),
                default => $char,
            };
        }

        return $out;
    }

    /**
     * Run compose() over a table of [pageTitle, siteName, expected] rows.
     *
     * @param array<string, array{0: string, 1: string, 2: string}> $cases Rows keyed by a label for failure messages.
     */
    private static function assertComposes(array $cases): void
    {
        foreach ($cases as $label => [$pageTitle, $siteName, $expected]) {
            self::assertSame($expected, PageTitle::compose($pageTitle, $siteName), $label);
        }
    }

    // -------------------------------------------------------------------------
    // Rule: a distinct title gets the site name appended
    // -------------------------------------------------------------------------

    /**
     * A title that differs from the site name is followed by " | " and the site
     * name, and is otherwise left as it is — including HTML-special characters,
     * because callers do the escaping.
     */
    public function testDistinctTitleIsFollowedBySiteName(): void
    {
        self::assertComposes([
            'ordinary title'             => ['Our Flowers', self::SITE, "Our Flowers | Perla's Flowers"],
            'Spanish title'              => ['Nuestras Flores Frescas', self::SITE, "Nuestras Flores Frescas | Perla's Flowers"],
            'single character'           => ['A', self::SITE, "A | Perla's Flowers"],
            'title with its own pipe'    => ['Roses | Tulips', self::SITE, "Roses | Tulips | Perla's Flowers"],
            'markup stays raw'           => ['Roses & <Tulips> "now"', self::SITE, "Roses & <Tulips> \"now\" | Perla's Flowers"],
            'title extends the name'     => ["Perla's Flowers in Tulsa", self::SITE, "Perla's Flowers in Tulsa | Perla's Flowers"],
            'title ends with the name'   => ["Best of Perla's Flowers", self::SITE, "Best of Perla's Flowers | Perla's Flowers"],
            'title is a prefix of name'  => ['Perla', self::SITE, "Perla | Perla's Flowers"],
            'interior whitespace counts' => ["Perla's  Flowers", self::SITE, "Perla's  Flowers | Perla's Flowers"],
            'different site name'        => ['Our Flowers', 'Rosa Blooms', 'Our Flowers | Rosa Blooms'],
        ]);
    }

    /**
     * Surrounding whitespace is trimmed from both parts; whitespace inside a
     * part is kept.
     */
    public function testSurroundingWhitespaceIsTrimmedFromBothParts(): void
    {
        self::assertComposes([
            'padded title'             => ["\n  Our Flowers\t", self::SITE, "Our Flowers | Perla's Flowers"],
            'padded site name'         => ['Our Flowers', "  Perla's Flowers \n", "Our Flowers | Perla's Flowers"],
            'both padded'              => [" \tOur Flowers\r\n", "\x0B Perla's Flowers ", "Our Flowers | Perla's Flowers"],
            'interior whitespace kept' => ['Our   Flowers', self::SITE, "Our   Flowers | Perla's Flowers"],
        ]);
    }

    // -------------------------------------------------------------------------
    // Rule: a blank title yields the site name alone
    // -------------------------------------------------------------------------

    /**
     * A page with no title of its own (empty or whitespace-only) shows just the
     * site name, trimmed.
     */
    public function testBlankTitleYieldsSiteNameAlone(): void
    {
        $blanks = ['', ' ', '   ', "\t", "\n", "\r\n", " \t \n ", "\x0B"];

        $cases = [];
        foreach ($blanks as $blank) {
            $cases['blank title ' . var_export($blank, true)] = [$blank, self::SITE, self::SITE];
        }
        $cases['padded site name'] = ['', "  Perla's Flowers\n", self::SITE];

        self::assertComposes($cases);
    }

    // -------------------------------------------------------------------------
    // Rule: a title equal to the site name yields the site name alone
    // -------------------------------------------------------------------------

    /**
     * The home page and every page that falls back to BUSINESS_NAME pass the
     * site name as the title; the result must not say it twice. Case and
     * surrounding whitespace are ignored, and the result keeps the site name's
     * own casing.
     */
    public function testTitleEqualToSiteNameYieldsSiteNameAlone(): void
    {
        self::assertComposes([
            'identical'                  => [self::SITE, self::SITE, self::SITE],
            'lower case'                 => ["perla's flowers", self::SITE, self::SITE],
            'upper case'                 => ["PERLA'S FLOWERS", self::SITE, self::SITE],
            'mixed case'                 => ["pERLA's fLOWERS", self::SITE, self::SITE],
            'padded title'               => ["  Perla's Flowers\t\n", self::SITE, self::SITE],
            'padded site name'           => [self::SITE, "\n Perla's Flowers  ", self::SITE],
            'padded and re-cased'        => [" \tPERLA'S flowers\n", " Perla's Flowers ", self::SITE],
            'accented, upper-cased'      => ['ÑANDÚ FLORES ÁÉÍÓÚ', 'Ñandú Flores Áéíóú', 'Ñandú Flores Áéíóú'],
            'accented, lower-cased'      => ['ñandú flores áéíóú', 'Ñandú Flores Áéíóú', 'Ñandú Flores Áéíóú'],
            'one character'              => ['p', 'P', 'P'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Rule: a blank site name yields the title alone
    // -------------------------------------------------------------------------

    /**
     * Without a configured business name there is nothing to append, so the
     * (trimmed) title stands alone, with no dangling separator.
     */
    public function testBlankSiteNameYieldsTrimmedTitleAlone(): void
    {
        self::assertComposes([
            'empty site name'      => ['Our Flowers', '', 'Our Flowers'],
            'spaces site name'     => ['Our Flowers', '   ', 'Our Flowers'],
            'whitespace site name' => ['Our Flowers', "\t\n \r", 'Our Flowers'],
            'padded title'         => ["  Our Flowers\n", '', 'Our Flowers'],
            'title with a pipe'    => ['Roses | Tulips', '', 'Roses | Tulips'],
            'markup stays raw'     => ['Roses & <Tulips>', ' ', 'Roses & <Tulips>'],
        ]);
    }

    /**
     * With both parts blank there is no title at all: the result is ''.
     */
    public function testBothBlankYieldsEmptyString(): void
    {
        self::assertComposes([
            'both empty'      => ['', '', ''],
            'title blank'     => [" \t", '', ''],
            'site blank'      => ['', "\n ", ''],
            'both whitespace' => ["\r\n", " \x0B ", ''],
        ]);
    }

    // -------------------------------------------------------------------------
    // Stochastic
    // -------------------------------------------------------------------------

    /**
     * Random site names and titles, built so the expected answer is known by
     * construction. Every iteration checks each rule once:
     *  - a whitespace-only (or empty) title yields the site name's text;
     *  - the site name's text re-cased at random (upper, lower or untouched per
     *    character, accented letters included) and padded with whitespace
     *    yields the site name's text;
     *  - a whitespace-only (or empty) site name yields the title's text;
     *  - a title that extends the site name by at least one character, at the
     *    front or the back, gets " | " and the site name appended.
     * The padding around either part never changes the outcome.
     */
    public function testStochasticRulesOverRandomTitlesAndSiteNames(): void
    {
        $rng = new Randomizer(new Mt19937(self::SEED));

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $core  = self::randomCore($rng);
            $site  = self::randomWhitespace($rng, 4) . $core . self::randomWhitespace($rng, 4);
            $other = self::randomCore($rng);

            $blankTitle = self::randomWhitespace($rng, 6);
            self::assertStochastic($iteration, 'blank title', $core, $blankTitle, $site);

            $recased = self::randomWhitespace($rng, 4) . self::scrambleCase($rng, $core) . self::randomWhitespace($rng, 4);
            self::assertStochastic($iteration, 'title equals site name', $core, $recased, $site);

            $paddedTitle = self::randomWhitespace($rng, 4) . $other . self::randomWhitespace($rng, 4);
            $blankSite   = self::randomWhitespace($rng, 6);
            self::assertStochastic($iteration, 'blank site name', $other, $paddedTitle, $blankSite);

            $extended = $rng->getInt(0, 1) === 0
                ? $core . self::randomSuffix($rng)
                : self::randomSuffix($rng) . $core;
            $paddedExtended = self::randomWhitespace($rng, 4) . $extended . self::randomWhitespace($rng, 4);
            self::assertStochastic($iteration, 'distinct title', $extended . ' | ' . $core, $paddedExtended, $site);
        }
    }

    /**
     * Assert one stochastic case, quoting the seed and inputs on failure.
     *
     * @param int    $iteration Loop counter, for the failure message.
     * @param string $rule      Which rule the case exercises, for the failure message.
     * @param string $expected  The result the construction guarantees.
     * @param string $pageTitle Title handed to compose().
     * @param string $siteName  Site name handed to compose().
     */
    private static function assertStochastic(int $iteration, string $rule, string $expected, string $pageTitle, string $siteName): void
    {
        self::assertSame(
            $expected,
            PageTitle::compose($pageTitle, $siteName),
            sprintf(
                'seed %d, iteration %d, rule "%s", title %s, site name %s',
                self::SEED,
                $iteration,
                $rule,
                var_export($pageTitle, true),
                var_export($siteName, true)
            )
        );
    }
}
