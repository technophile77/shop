<?php

declare(strict_types=1);

namespace App\Tests\Controllers\Admin;

use App\Controllers\Admin\SettingsController;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Unit tests for the pure POST -> values mapping in SettingsController.
 *
 * valuesFromPost() decides which site_settings rows an admin save writes. It
 * exists because every save used to write '' for each setting the form has no
 * input for (home_page_title_*, about_meta_desc_*, products_meta_desc_*),
 * silently wiping them. update() itself needs a session and a database, so the
 * save-and-redirect flow is verified against the live host instead — see
 * CLAUDE.md's deployment notes. Key names are hard-coded here on purpose: the
 * controller's KNOWN_KEYS is private, and an independent list means removing a
 * key from it fails these tests. Includes one fixed-seed stochastic sweep (this
 * repo keeps stochastic sweeps inline in the unit suite — see StockCheckTest).
 * It draws from its own seeded Random\Randomizer rather than mt_srand(), so the
 * global mt_rand() stream that other tests (e.g. LocalAreasIntegrityTest) rely
 * on is untouched.
 *
 * @see \App\Controllers\Admin\SettingsController::valuesFromPost()
 * @see \App\Core\Settings::get() Falls back to the caller's default for the '' values this mapping can persist.
 */
final class SettingsControllerTest extends TestCase
{
    /** Seed for the stochastic test; quoted in failure messages so a run can be replayed. */
    private const SEED = 20261006;

    /** Randomised iterations for the stochastic test. */
    private const ITERATIONS = 300;

    /** Keys rendered as HTML checkboxes: present -> '1', absent -> '0'. */
    private const CHECKBOX_KEYS = ['show_doordash_button', 'show_whatsapp_button'];

    /**
     * Text inputs that exist on views/admin/settings/index.php, i.e. the text
     * fields a form submission actually carries.
     */
    private const FORM_TEXT_FIELDS = [
        'hero_headline_en',
        'hero_headline_es',
        'hero_subtext_en',
        'hero_subtext_es',
        'order_button_text_en',
        'order_button_text_es',
        'doordash_button_label_en',
        'doordash_button_label_es',
        'promo_strip_text_en',
        'promo_strip_text_es',
        'signup_title_en',
        'signup_title_es',
        'about_text_en',
        'about_text_es',
        'products_page_title_en',
        'products_page_title_es',
        'order_page_title_en',
        'order_page_title_es',
        'about_page_title_en',
        'about_page_title_es',
        'contact_page_title_en',
        'contact_page_title_es',
        'vip_spend_threshold',
    ];

    /**
     * Managed settings with no input on the admin form. A save must leave their
     * stored rows alone; before the fix it overwrote each one with ''.
     */
    private const KEYS_WITHOUT_FORM_INPUT = [
        'home_page_title_en',
        'home_page_title_es',
        'about_meta_desc_en',
        'about_meta_desc_es',
        'products_meta_desc_en',
        'products_meta_desc_es',
    ];

    /** Every managed text key: the form's inputs plus the ones without an input. */
    private const TEXT_KEYS = [...self::FORM_TEXT_FIELDS, ...self::KEYS_WITHOUT_FORM_INPUT];

    /** Characters for random field values: whitespace, markup, quotes, multi-byte text. */
    private const ALPHABET = [
        'a', 'B', '7', '0', ' ', "\t", "\n", "\r", '<', '>', '&', "'", '"', '\\', 'ñ', 'é', '🌹',
    ];

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    /**
     * Sort a key => value map by key so assertions ignore result ordering.
     *
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    /**
     * Pick one element of a non-empty list.
     *
     * @param list<mixed> $items
     */
    private static function pick(Randomizer $rng, array $items): mixed
    {
        return $items[$rng->getInt(0, count($items) - 1)];
    }

    /** Random string of 0-30 characters, so '' and whitespace-only values occur. */
    private static function randomString(Randomizer $rng): string
    {
        $chars = [];
        for ($i = $rng->getInt(0, 30); $i > 0; $i--) {
            $chars[] = self::pick($rng, self::ALPHABET);
        }

        return implode('', $chars);
    }

    /** Random value that is not a string (null, array, number or bool). */
    private static function randomNonString(Randomizer $rng): mixed
    {
        return self::pick($rng, [null, [], ['x'], ['a' => 'b'], 7, 0, 1.5, true, false]);
    }

    // -------------------------------------------------------------------------
    // Text keys
    // -------------------------------------------------------------------------

    /**
     * An empty submission writes nothing but the two (unchecked) checkboxes, so
     * no stored text setting is touched.
     */
    public function testEmptyPostOmitsEveryTextKeyAndUnchecksBothCheckboxes(): void
    {
        self::assertSame(
            ['show_doordash_button' => '0', 'show_whatsapp_button' => '0'],
            self::sorted(SettingsController::valuesFromPost([]))
        );
    }

    /**
     * Text keys absent from POST are omitted, which leaves their stored rows
     * unchanged.
     */
    public function testAbsentTextKeysAreOmitted(): void
    {
        $result = SettingsController::valuesFromPost(['about_text_en' => 'Our story']);

        self::assertSame(
            ['about_text_en' => 'Our story', 'show_doordash_button' => '0', 'show_whatsapp_button' => '0'],
            self::sorted($result)
        );
    }

    /**
     * A text key submitted as '' is kept as '': the owner cleared the field on
     * purpose, and Settings::get() will fall back to the default for it.
     */
    public function testPresentEmptyTextKeyYieldsEmptyString(): void
    {
        $result = SettingsController::valuesFromPost(['about_text_en' => '', 'hero_headline_en' => 'Hi']);

        self::assertArrayHasKey('about_text_en', $result);
        self::assertSame(
            [
                'about_text_en'        => '',
                'hero_headline_en'     => 'Hi',
                'show_doordash_button' => '0',
                'show_whatsapp_button' => '0',
            ],
            self::sorted($result)
        );
    }

    /**
     * Text values are returned verbatim — not trimmed, escaped or cast.
     */
    public function testTextValuesAreReturnedVerbatim(): void
    {
        $value  = "  <b>Meet</b> Perla & \"friends\"\n";
        $result = SettingsController::valuesFromPost(['about_text_en' => $value]);

        self::assertSame($value, $result['about_text_en']);
    }

    /**
     * A text key posted as an array (field[]) is omitted rather than being
     * stringified to "Array".
     */
    public function testArrayValuedTextKeyIsOmitted(): void
    {
        $result = SettingsController::valuesFromPost([
            'about_text_en'    => ['one', 'two'],
            'hero_headline_en' => 'Hi',
        ]);

        self::assertArrayNotHasKey('about_text_en', $result);
        self::assertSame('Hi', $result['hero_headline_en']);
    }

    /**
     * Only strings are persisted for text keys: numbers and booleans (which can
     * arrive from a JSON body) are omitted too.
     */
    public function testOtherNonStringTextValuesAreOmitted(): void
    {
        $result = SettingsController::valuesFromPost([
            'vip_spend_threshold' => 250,
            'hero_subtext_en'     => 1.5,
            'signup_title_en'     => true,
            'signup_title_es'     => false,
            'about_text_es'       => 'kept',
        ]);

        self::assertSame(
            ['about_text_es' => 'kept', 'show_doordash_button' => '0', 'show_whatsapp_button' => '0'],
            self::sorted($result)
        );
    }

    /**
     * A null value counts as absent: update() passes null for every field the
     * form did not send (Request::post() returns null for those).
     */
    public function testNullValuesCountAsAbsent(): void
    {
        $result = SettingsController::valuesFromPost([
            'about_text_en'        => null,
            'show_doordash_button' => null,
            'show_whatsapp_button' => '1',
        ]);

        self::assertSame(
            ['show_doordash_button' => '0', 'show_whatsapp_button' => '1'],
            self::sorted($result)
        );
    }

    // -------------------------------------------------------------------------
    // Checkbox keys
    // -------------------------------------------------------------------------

    /**
     * Checkboxes are '1' when present and '0' when absent; the submitted value
     * is irrelevant, so a browser's default 'on' counts as checked.
     */
    public function testCheckboxesAreOneWhenPresentAndZeroWhenAbsent(): void
    {
        $cases = [
            'neither'              => [[], '0', '0'],
            'doordash only'        => [['show_doordash_button' => '1'], '1', '0'],
            'whatsapp only'        => [['show_whatsapp_button' => '1'], '0', '1'],
            'both'                 => [['show_doordash_button' => '1', 'show_whatsapp_button' => '1'], '1', '1'],
            'browser default "on"' => [['show_doordash_button' => 'on', 'show_whatsapp_button' => 'on'], '1', '1'],
        ];

        foreach ($cases as $label => [$post, $doordash, $whatsapp]) {
            $result = SettingsController::valuesFromPost($post);

            self::assertSame($doordash, $result['show_doordash_button'], "{$label}: doordash");
            self::assertSame($whatsapp, $result['show_whatsapp_button'], "{$label}: whatsapp");
        }
    }

    // -------------------------------------------------------------------------
    // Allow-list
    // -------------------------------------------------------------------------

    /**
     * Fields outside the allow-list — including the CSRF token every real form
     * carries — never reach the result (mass-assignment protection).
     */
    public function testUnknownKeysAreIgnored(): void
    {
        $result = SettingsController::valuesFromPost([
            'rogue_key'     => 'x',
            '_csrf_token'   => 'abc123',
            'id'            => '1',
            'about_text_en' => 'ok',
        ]);

        self::assertSame(
            ['about_text_en' => 'ok', 'show_doordash_button' => '0', 'show_whatsapp_button' => '0'],
            self::sorted($result)
        );
    }

    // -------------------------------------------------------------------------
    // The original bug
    // -------------------------------------------------------------------------

    /**
     * A full submission of the admin form writes every field it carried, and
     * none of the settings it has no input for — the six SEO strings that every
     * save used to wipe.
     */
    public function testFormSubmissionNeverWritesKeysItHasNoInputFor(): void
    {
        $post     = ['_csrf_token' => 'token'];
        $expected = [];
        foreach (self::FORM_TEXT_FIELDS as $key) {
            $post[$key]     = "value of {$key}";
            $expected[$key] = "value of {$key}";
        }
        foreach (self::CHECKBOX_KEYS as $key) {
            $post[$key]     = '1';
            $expected[$key] = '1';
        }

        $result = SettingsController::valuesFromPost($post);

        foreach (self::KEYS_WITHOUT_FORM_INPUT as $key) {
            self::assertArrayNotHasKey($key, $result, "{$key} must survive an admin save");
        }
        self::assertSame(self::sorted($expected), self::sorted($result));
    }

    // -------------------------------------------------------------------------
    // Stochastic
    // -------------------------------------------------------------------------

    /**
     * Random mixes of present, absent and non-string text fields, random
     * checkbox states and random unknown fields. The result is always exactly
     * the text keys submitted as strings (verbatim, including '' and
     * whitespace-only values) plus both checkbox keys, and nothing else.
     */
    public function testStochasticResultIsExactlyPresentTextKeysPlusBothCheckboxes(): void
    {
        $rng = new Randomizer(new Mt19937(self::SEED));

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $post     = [];
            $expected = [];

            foreach (self::TEXT_KEYS as $key) {
                $kind = $rng->getInt(0, 3);
                if ($kind === 0) {
                    continue; // Field not submitted.
                }
                if ($kind === 3) {
                    $post[$key] = self::randomNonString($rng); // Submitted, but not a string.
                    continue;
                }

                $value          = self::randomString($rng);
                $post[$key]     = $value;
                $expected[$key] = $value;
            }

            foreach (self::CHECKBOX_KEYS as $key) {
                $checked = $rng->getInt(0, 1) === 1;
                if ($checked) {
                    $post[$key] = self::pick($rng, ['1', 'on']);
                }
                $expected[$key] = $checked ? '1' : '0';
            }

            // Noise that must never reach the result.
            $post['_csrf_token'] = self::randomString($rng);
            for ($n = $rng->getInt(0, 3); $n > 0; $n--) {
                $post['unknown_' . $rng->getInt(0, 999999)] = self::randomString($rng);
            }

            self::assertSame(
                self::sorted($expected),
                self::sorted(SettingsController::valuesFromPost($post)),
                sprintf('seed %d, iteration %d, post %s', self::SEED, $iteration, var_export($post, true))
            );
        }
    }
}
