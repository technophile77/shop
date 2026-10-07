<?php

declare(strict_types=1);

namespace App\Tests\Controllers;

use App\Controllers\AboutController;
use App\Core\Settings;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Unit tests for AboutController::metaDescription().
 *
 * The method decides what the About page puts in its meta description: the
 * `about_meta_desc_{lang}` setting, else the English one, else ''. It was
 * missing entirely once (index() never passed `metaDesc`, so /en/about and
 * /es/about rendered an empty description), which is why the fallback chain is
 * pinned down here. The tests prime Settings' private static cache through
 * reflection, so the real Settings::get() runs without a database
 * (loadIfNeeded() returns early whenever the cache is non-null), and reset it
 * to null afterwards so no state leaks into other tests. index() itself needs a
 * session and the full layout, so the page is smoke-tested on the live host
 * instead — see CLAUDE.md's deployment notes. Includes one fixed-seed
 * stochastic sweep (this repo keeps stochastic sweeps inline in the unit suite
 * — see StockCheckTest). It draws from its own seeded Random\Randomizer rather
 * than mt_srand(), so the global mt_rand() stream that other tests (e.g.
 * LocalAreasIntegrityTest) rely on is untouched.
 *
 * @see \App\Controllers\AboutController::metaDescription()
 * @see \App\Core\Settings::get() Supplies the blank-means-missing behaviour the fallback relies on.
 */
final class AboutControllerTest extends TestCase
{
    /** Seed for the stochastic test; quoted in failure messages so a run can be replayed. */
    private const SEED = 20261006;

    /** Randomised iterations for the stochastic test. */
    private const ITERATIONS = 300;

    /** Characters PHP's trim() strips: what makes a stored description blank. */
    private const WHITESPACE = [' ', "\t", "\n", "\r", "\x0B"];

    /** Characters trim() leaves alone, including markup, quotes and multi-byte text. */
    private const TEXT_CHARS = ['a', 'k', 'Z', '7', '0', '-', '.', ',', '<', '&', "'", '"', 'ñ', 'é', '¡', '🌹'];

    protected function setUp(): void
    {
        // A primed (non-null) cache keeps get() away from the database even if
        // a test forgets to prime its own rows.
        self::primeCache([]);
    }

    protected function tearDown(): void
    {
        self::primeCache(null);
    }

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    /**
     * Replace Settings' private static cache; null means "not loaded yet".
     *
     * @param array<string, string|null>|null $rows
     */
    private static function primeCache(?array $rows): void
    {
        (new ReflectionProperty(Settings::class, 'cache'))->setValue(null, $rows);
    }

    /**
     * Pick one element of a non-empty list.
     *
     * @param list<string> $items
     */
    private static function pick(Randomizer $rng, array $items): string
    {
        return $items[$rng->getInt(0, count($items) - 1)];
    }

    /** Random non-empty string made only of whitespace characters. */
    private static function randomWhitespace(Randomizer $rng): string
    {
        $chars = [];
        for ($i = $rng->getInt(1, 12); $i > 0; $i--) {
            $chars[] = self::pick($rng, self::WHITESPACE);
        }

        return implode('', $chars);
    }

    /**
     * Random description trim() cannot empty: arbitrary whitespace around and
     * inside text, with at least one non-whitespace character forced in.
     */
    private static function randomDescription(Randomizer $rng): string
    {
        $length = $rng->getInt(1, 24);
        $chars  = [];
        for ($i = 0; $i < $length; $i++) {
            $chars[] = self::pick($rng, $rng->getInt(0, 1) === 0 ? self::WHITESPACE : self::TEXT_CHARS);
        }
        $chars[$rng->getInt(0, $length - 1)] = self::pick($rng, self::TEXT_CHARS);

        return implode('', $chars);
    }

    /**
     * Draw how one language's description is stored.
     *
     * Whether the draw counts as real text is known by construction, so the
     * expectation never has to re-derive blankness from the stored value.
     *
     * @return array{present: bool, value: string|null, real: bool}
     *         'present' is false when the row does not exist at all; 'value' is
     *         what the row holds when it does; 'real' is true only for the
     *         non-blank descriptions.
     */
    private static function randomStoredState(Randomizer $rng): array
    {
        return match ($rng->getInt(0, 5)) {
            0       => ['present' => false, 'value' => null, 'real' => false],
            1       => ['present' => true, 'value' => null, 'real' => false],
            2       => ['present' => true, 'value' => '', 'real' => false],
            3       => ['present' => true, 'value' => self::randomWhitespace($rng), 'real' => false],
            default => ['present' => true, 'value' => self::randomDescription($rng), 'real' => true],
        };
    }

    // -------------------------------------------------------------------------
    // The requested language wins
    // -------------------------------------------------------------------------

    /**
     * Each language reads its own description when both are set.
     */
    public function testLanguageSpecificDescriptionWins(): void
    {
        self::primeCache([
            'about_meta_desc_en' => 'Meet Perla, a Tulsa florist.',
            'about_meta_desc_es' => 'Conozca a Perla, florista en Tulsa.',
        ]);

        self::assertSame('Conozca a Perla, florista en Tulsa.', AboutController::metaDescription('es'));
        self::assertSame('Meet Perla, a Tulsa florist.', AboutController::metaDescription('en'));
    }

    /**
     * The stored text comes back exactly as saved: not trimmed (the admin form
     * already trims on save) and not escaped (the layout escapes it).
     */
    public function testStoredDescriptionIsReturnedVerbatim(): void
    {
        $stored = "  Perla's <b>bouquets</b> & \"more\"\n";
        self::primeCache(['about_meta_desc_en' => $stored]);

        self::assertSame($stored, AboutController::metaDescription('en'));
    }

    // -------------------------------------------------------------------------
    // Fallback to English
    // -------------------------------------------------------------------------

    /**
     * An empty, whitespace-only or NULL Spanish row falls back to English, just
     * like a missing one: a blank row must not shadow the English text.
     */
    public function testBlankSpanishFallsBackToEnglish(): void
    {
        $blanks = ['', ' ', '   ', "\t", "\n", "\r\n", " \t \n ", "\x0B", null];

        foreach ($blanks as $blank) {
            self::primeCache([
                'about_meta_desc_en' => 'Meet Perla, a Tulsa florist.',
                'about_meta_desc_es' => $blank,
            ]);

            self::assertSame(
                'Meet Perla, a Tulsa florist.',
                AboutController::metaDescription('es'),
                'blank Spanish value ' . var_export($blank, true)
            );
        }
    }

    /**
     * A Spanish page whose description has no row at all falls back to English.
     */
    public function testAbsentSpanishFallsBackToEnglish(): void
    {
        self::primeCache(['about_meta_desc_en' => 'Meet Perla, a Tulsa florist.']);

        self::assertSame('Meet Perla, a Tulsa florist.', AboutController::metaDescription('es'));
    }

    /**
     * English is the last resort, never a borrower: with no English text the
     * English page gets '', even when a Spanish description exists.
     */
    public function testEnglishNeverBorrowsTheSpanishDescription(): void
    {
        self::primeCache(['about_meta_desc_es' => 'Conozca a Perla, florista en Tulsa.']);
        self::assertSame('', AboutController::metaDescription('en'));

        self::primeCache(['about_meta_desc_en' => '  ', 'about_meta_desc_es' => 'Conozca a Perla, florista en Tulsa.']);
        self::assertSame('', AboutController::metaDescription('en'));
    }

    // -------------------------------------------------------------------------
    // Nothing to show
    // -------------------------------------------------------------------------

    /**
     * With neither description set (no rows, or only blank ones) the result is
     * '' in both languages, never null or another page's text.
     */
    public function testBothBlankOrAbsentYieldEmptyString(): void
    {
        $cases = [
            'no rows at all'      => [],
            'only English absent' => ['about_meta_desc_es' => ''],
            'only Spanish absent' => ['about_meta_desc_en' => ''],
            'both empty'          => ['about_meta_desc_en' => '', 'about_meta_desc_es' => ''],
            'both whitespace'     => ['about_meta_desc_en' => " \t", 'about_meta_desc_es' => "\n\n"],
            'both NULL'           => ['about_meta_desc_en' => null, 'about_meta_desc_es' => null],
        ];

        foreach ($cases as $label => $rows) {
            self::primeCache($rows);

            self::assertSame('', AboutController::metaDescription('es'), "{$label}: es");
            self::assertSame('', AboutController::metaDescription('en'), "{$label}: en");
        }
    }

    /**
     * Only the about_meta_desc_* keys are read: the neighbouring settings other
     * pages use for their own titles and descriptions never leak in.
     */
    public function testOtherPagesSettingsAreIgnored(): void
    {
        self::primeCache([
            'products_meta_desc_en' => 'Products description',
            'products_meta_desc_es' => 'Descripcion de productos',
            'home_page_title_en'    => 'Home title',
            'hero_subtext_en'       => 'Hero subtext',
            'about_page_title_en'   => 'About title',
            'about_text_en'         => 'About text',
        ]);

        self::assertSame('', AboutController::metaDescription('en'));
        self::assertSame('', AboutController::metaDescription('es'));
    }

    // -------------------------------------------------------------------------
    // Stochastic
    // -------------------------------------------------------------------------

    /**
     * Random combinations of absent, NULL, empty, whitespace-only and real
     * descriptions for both languages. The Spanish page shows its own text when
     * that is real, else the English text when that is real, else ''; the
     * English page shows its own text when real, else ''.
     */
    public function testStochasticFallbackChain(): void
    {
        $rng = new Randomizer(new Mt19937(self::SEED));

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $english = self::randomStoredState($rng);
            $spanish = self::randomStoredState($rng);

            $rows = [];
            if ($english['present']) {
                $rows['about_meta_desc_en'] = $english['value'];
            }
            if ($spanish['present']) {
                $rows['about_meta_desc_es'] = $spanish['value'];
            }
            self::primeCache($rows);

            $expectedEnglishPage = $english['real'] ? $english['value'] : '';
            $expectedSpanishPage = match (true) {
                $spanish['real'] => $spanish['value'],
                $english['real'] => $english['value'],
                default          => '',
            };

            $context = sprintf('seed %d, iteration %d, rows %s', self::SEED, $iteration, var_export($rows, true));

            self::assertSame($expectedSpanishPage, AboutController::metaDescription('es'), $context . ', page es');
            self::assertSame($expectedEnglishPage, AboutController::metaDescription('en'), $context . ', page en');
        }
    }
}
