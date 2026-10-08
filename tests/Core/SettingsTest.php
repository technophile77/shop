<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Core\Settings;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Unit tests for the blank-value fallback in App\Core\Settings::get().
 *
 * Settings reads site_settings lazily into a private static cache. These tests
 * prime that cache directly through reflection, so the real get() / all() code
 * runs without a database (loadIfNeeded() returns early whenever the cache is
 * non-null). The cache is reset to null afterwards so no state leaks into other
 * tests. Includes two fixed-seed stochastic sweeps (this repo keeps stochastic
 * sweeps inline in the unit suite — see StockCheckTest). They draw from their
 * own seeded Random\Randomizer rather than mt_srand(), so the global mt_rand()
 * stream that other tests (e.g. LocalAreasIntegrityTest) rely on is untouched.
 *
 * @see \App\Core\Settings::get()
 * @see \App\Core\Settings::all()
 */
final class SettingsTest extends TestCase
{
    /** Seed for the stochastic tests; quoted in failure messages so a run can be replayed. */
    private const SEED = 20261006;

    /** Randomised iterations per stochastic test. */
    private const ITERATIONS = 300;

    /** Characters PHP's trim() strips: what makes a stored value blank. */
    private const WHITESPACE = [' ', "\t", "\n", "\r", "\x0B"];

    /** Characters trim() leaves alone, including multi-byte ones and a lone '0'. */
    private const NON_BLANK = [
        'a', 'k', 'Z', '7', '0', '-', '.', ',', '<', '&', "'", '"', '\\', '/', 'ñ', 'é', '¡', '🌹',
    ];

    protected function setUp(): void
    {
        // A primed (non-null) cache keeps get()/all() away from the database
        // even if a test forgets to prime its own rows.
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

    /** Fresh generator for a stochastic test, seeded so every run replays the same stream. */
    private static function seededRandomizer(): Randomizer
    {
        return new Randomizer(new Mt19937(self::SEED));
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

    /**
     * Random string trim() cannot empty: a random mix of whitespace and other
     * characters, with at least one non-blank character forced in.
     */
    private static function randomNonBlank(Randomizer $rng): string
    {
        $length = $rng->getInt(1, 24);
        $chars  = [];
        for ($i = 0; $i < $length; $i++) {
            $chars[] = self::pick($rng, $rng->getInt(0, 1) === 0 ? self::WHITESPACE : self::NON_BLANK);
        }
        $chars[$rng->getInt(0, $length - 1)] = self::pick($rng, self::NON_BLANK);

        return implode('', $chars);
    }

    /** Random non-empty string made only of whitespace characters. */
    private static function randomWhitespace(Randomizer $rng): string
    {
        $length = $rng->getInt(1, 16);
        $chars  = [];
        for ($i = 0; $i < $length; $i++) {
            $chars[] = self::pick($rng, self::WHITESPACE);
        }

        return implode('', $chars);
    }

    /** Failure-message context that makes a stochastic failure reproducible. */
    private static function context(int $iteration, string $key, string $stored): string
    {
        return sprintf(
            'seed %d, iteration %d, key %s, stored %s',
            self::SEED,
            $iteration,
            $key,
            var_export($stored, true)
        );
    }

    // -------------------------------------------------------------------------
    // get() — fallback to $default
    // -------------------------------------------------------------------------

    /**
     * A key with no row returns the caller's default.
     */
    public function testAbsentKeyReturnsDefault(): void
    {
        self::primeCache(['other_key' => 'present']);

        self::assertSame('fallback', Settings::get('missing_key', 'fallback'));
    }

    /**
     * An empty row falls back to the default — the "Our Story vanished" bug,
     * where `??` let '' shadow the .env fallback.
     */
    public function testEmptyStringReturnsDefault(): void
    {
        self::primeCache(['about_text_en' => '']);

        self::assertSame('From .env', Settings::get('about_text_en', 'From .env'));
    }

    /**
     * Whitespace-only rows (spaces, tabs, newlines) fall back to the default.
     */
    public function testWhitespaceOnlyReturnsDefault(): void
    {
        $blanks = ['   ', "\t", "\n", "\r\n", " \t \n ", "\n\n\n", "\x0B"];

        foreach ($blanks as $blank) {
            self::primeCache(['about_text_en' => $blank]);

            self::assertSame(
                'From .env',
                Settings::get('about_text_en', 'From .env'),
                'blank value ' . var_export($blank, true)
            );
        }
    }

    /**
     * A NULL row falls back to the default (site_settings.value is nullable).
     */
    public function testNullStoredValueReturnsDefault(): void
    {
        self::primeCache(['about_text_en' => null]);

        self::assertSame('From .env', Settings::get('about_text_en', 'From .env'));
    }

    /**
     * With no default supplied, an empty, blank, NULL or absent row is null.
     */
    public function testOmittedDefaultYieldsNullWhenAbsentOrBlank(): void
    {
        self::primeCache(['empty' => '', 'blank' => "  \t", 'nothing' => null]);

        self::assertNull(Settings::get('empty'));
        self::assertNull(Settings::get('blank'));
        self::assertNull(Settings::get('nothing'));
        self::assertNull(Settings::get('missing'));
    }

    /**
     * The default is handed back as-is, whatever its type (including '').
     */
    public function testDefaultIsReturnedUnchangedWhateverItsType(): void
    {
        self::primeCache(['empty' => '']);

        self::assertSame('', Settings::get('empty', ''));
        self::assertSame(42, Settings::get('empty', 42));
        self::assertSame(0, Settings::get('empty', 0));
        self::assertFalse(Settings::get('empty', false));
        self::assertSame([], Settings::get('empty', []));
    }

    // -------------------------------------------------------------------------
    // get() — stored values are real values
    // -------------------------------------------------------------------------

    /**
     * '0' is a real value, not a blank one: checkbox settings store '0' / '1',
     * and '0' is falsy in PHP, which is exactly the trap a truthiness check
     * would fall into.
     */
    public function testZeroStringIsARealValue(): void
    {
        self::primeCache(['show_doordash_button' => '0']);

        self::assertSame('0', Settings::get('show_doordash_button', '1'));
    }

    /**
     * An ordinary stored value wins over the default.
     */
    public function testStoredValueIsReturned(): void
    {
        self::primeCache(['hero_headline_en' => 'Handcrafted with Love']);

        self::assertSame('Handcrafted with Love', Settings::get('hero_headline_en', 'Default headline'));
    }

    /**
     * Whitespace around real text is kept: get() only judges blankness, it does
     * not trim what it returns.
     */
    public function testStoredValueKeepsSurroundingWhitespace(): void
    {
        $stored = "  Meet Perla.\n";
        self::primeCache(['about_text_en' => $stored]);

        self::assertSame($stored, Settings::get('about_text_en', 'Default'));
    }

    /**
     * Other keys are unaffected by a blank neighbour.
     */
    public function testBlankRowDoesNotAffectOtherKeys(): void
    {
        self::primeCache(['about_text_en' => '', 'about_text_es' => 'Nuestra historia']);

        self::assertSame('fallback', Settings::get('about_text_en', 'fallback'));
        self::assertSame('Nuestra historia', Settings::get('about_text_es', 'fallback'));
    }

    // -------------------------------------------------------------------------
    // all() — unchanged
    // -------------------------------------------------------------------------

    /**
     * all() still returns every stored row as saved, blank values included:
     * the admin form needs the raw rows to pre-populate its inputs.
     */
    public function testAllReturnsStoredRowsRaw(): void
    {
        $rows = [
            'about_text_en'        => '',
            'tagline_en'           => '   ',
            'show_whatsapp_button' => '0',
            'hero_headline_en'     => 'Handcrafted with Love',
        ];
        self::primeCache($rows);

        self::assertSame($rows, Settings::all());
    }

    // -------------------------------------------------------------------------
    // Stochastic
    // -------------------------------------------------------------------------

    /**
     * Random strings containing at least one non-whitespace character (with
     * arbitrary whitespace, quotes, multi-byte text and lone '0's around it)
     * always come back exactly as stored — never trimmed, never replaced by the
     * default — and an absent key beside them still yields the default.
     */
    public function testStochasticNonBlankValuesRoundTripUnchanged(): void
    {
        $rng = self::seededRandomizer();

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $rows = [];
            for ($n = 1, $count = $rng->getInt(1, 8); $n <= $count; $n++) {
                $rows["key_{$n}"] = self::randomNonBlank($rng);
            }
            self::primeCache($rows);

            foreach ($rows as $key => $stored) {
                self::assertSame(
                    $stored,
                    Settings::get($key, 'the-default'),
                    self::context($iteration, $key, $stored)
                );
            }

            self::assertSame(
                'the-default',
                Settings::get('absent_key', 'the-default'),
                sprintf('seed %d, iteration %d, absent key', self::SEED, $iteration)
            );
        }
    }

    /**
     * Random whitespace-only strings always yield the caller's default,
     * whatever mix of spaces, tabs, newlines and vertical tabs they hold.
     */
    public function testStochasticWhitespaceOnlyValuesAlwaysYieldDefault(): void
    {
        $rng = self::seededRandomizer();

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $default = 'default-' . $rng->getInt(0, 999999);

            $rows = [];
            for ($n = 1, $count = $rng->getInt(1, 8); $n <= $count; $n++) {
                $rows["key_{$n}"] = self::randomWhitespace($rng);
            }
            self::primeCache($rows);

            foreach ($rows as $key => $stored) {
                self::assertSame(
                    $default,
                    Settings::get($key, $default),
                    self::context($iteration, $key, $stored)
                );
            }
        }
    }
}
