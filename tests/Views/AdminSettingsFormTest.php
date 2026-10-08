<?php

declare(strict_types=1);

namespace App\Tests\Views;

use App\Controllers\Admin\SettingsController;
use App\Core\Config;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionProperty;

/**
 * Regression guard keeping the admin Site Settings form in step with
 * SettingsController::KNOWN_KEYS.
 *
 * Background — the drift this prevents:
 *   The controller managed home_page_title_*, about_meta_desc_* and
 *   products_meta_desc_*, but views/admin/settings/index.php had no inputs for
 *   them. The owner could not edit those settings at all, and (before
 *   SettingsController::valuesFromPost()) every save wiped them. Nothing tied
 *   the two lists together, so the next key added to one side could be missed
 *   on the other the same way.
 *
 * The tests render the real view with the same extract()+ob_start()+include()
 * mechanism \App\Controllers\BaseController::render() uses (matching the
 * pattern in QuoteAcceptZeroTaxTotalTest) and parse the HTML with DOMDocument,
 * so what is asserted is the form a browser would build and submit:
 *  - the submitted field names are exactly KNOWN_KEYS (every managed key has an
 *    input, and no input posts a key the controller ignores);
 *  - every text field is pre-filled with its stored value, after the browser
 *    decodes the HTML entities (so a missing escape and a doubled escape both
 *    fail, without the test re-implementing the view's escaping);
 *  - a checkbox is checked exactly when its stored value is '1'.
 * KNOWN_KEYS and CHECKBOX_KEYS are private, so they are read through
 * ReflectionClassConstant; nothing here hard-codes a managed key name.
 *
 * Config's static cache and $_SESSION['flash'] are saved and restored around
 * each test so the render is deterministic and no state leaks into other tests.
 *
 * @see \App\Controllers\Admin\SettingsController::KNOWN_KEYS
 * @see \App\Controllers\Admin\SettingsController::valuesFromPost() Maps a submission of this form to the rows that are written.
 * @see views/admin/settings/index.php
 * @see \App\Tests\Views\QuoteAcceptZeroTaxTotalTest
 */
final class AdminSettingsFormTest extends TestCase
{
    /** Path to the view under test. */
    private const VIEW_PATH = __DIR__ . '/../../views/admin/settings/index.php';

    /** Name of the hidden CSRF field, which is not a managed setting. */
    private const CSRF_FIELD = '_csrf_token';

    /** CSRF token handed to the view; contains characters that need escaping. */
    private const CSRF_TOKEN = 'tok"en<1>&2';

    /** Business name configured for the render; contains characters that need escaping. */
    private const BUSINESS_NAME = "Perla's <Flowers> & \"Co\"";

    /**
     * Config's cache before the test, restored afterwards.
     *
     * @var array<string, mixed>
     */
    private array $savedConfigCache = [];

    /**
     * The session flash message found before the test, restored afterwards.
     *
     * @var array<string, string>|null
     */
    private ?array $savedFlash = null;

    protected function setUp(): void
    {
        // The view shows (and clears) a pending flash message; start without one.
        $this->savedFlash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        // Config::get() consults its cache first, so priming it pins the business
        // name the Home Page hint prints without touching the environment.
        $this->savedConfigCache = self::configCache()->getValue();
        self::primeBusinessName(self::BUSINESS_NAME);
    }

    protected function tearDown(): void
    {
        self::configCache()->setValue(null, $this->savedConfigCache);

        if ($this->savedFlash !== null) {
            $_SESSION['flash'] = $this->savedFlash;
        } else {
            unset($_SESSION['flash']);
        }
    }

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    /** Reflection handle on Config's private static cache. */
    private static function configCache(): ReflectionProperty
    {
        return new ReflectionProperty(Config::class, 'cache');
    }

    /** Make Config::get('BUSINESS_NAME') return $name for the rest of the test. */
    private static function primeBusinessName(string $name): void
    {
        self::configCache()->setValue(null, ['BUSINESS_NAME' => $name]);
    }

    /**
     * Read a private list constant of SettingsController.
     *
     * @return list<string>
     */
    private static function controllerKeys(string $constant): array
    {
        return (new ReflectionClassConstant(SettingsController::class, $constant))->getValue();
    }

    /**
     * Every key SettingsController manages.
     *
     * @return list<string>
     */
    private static function knownKeys(): array
    {
        return self::controllerKeys('KNOWN_KEYS');
    }

    /**
     * Managed keys rendered as checkboxes.
     *
     * @return list<string>
     */
    private static function checkboxKeys(): array
    {
        return self::controllerKeys('CHECKBOX_KEYS');
    }

    /**
     * Managed keys that hold free text or a number.
     *
     * @return list<string>
     */
    private static function textKeys(): array
    {
        return array_values(array_diff(self::knownKeys(), self::checkboxKeys()));
    }

    /**
     * A stored value that is distinct for every key and hostile to naive output:
     * quotes of both kinds, markup, an entity-looking sequence, a textarea
     * terminator, a newline and multi-byte text.
     */
    private static function textValueFor(string $key): string
    {
        return "{$key}: Tom & Jerry's \"Flowers\" <b>bold</b> &amp; </textarea><script>x</script>\nñ 🌹";
    }

    /**
     * Build the settings map the controller would hand to the view.
     *
     * Holds a distinct value for every KNOWN_KEYS key, plus rows for keys the
     * form does not manage (the public site reads them), which must not turn up
     * as inputs.
     *
     * @param list<string|null> $checkboxPattern Stored value for each checkbox key, taken
     *                                           in CHECKBOX_KEYS order and cycled when the
     *                                           pattern is shorter than the list; null means
     *                                           the row does not exist.
     *
     * @return array<string, string>
     */
    private static function settingsFixture(array $checkboxPattern): array
    {
        $settings = ['tagline_en' => 'Unmanaged tagline', 'business_hours_mon_open' => '09:00'];

        foreach (self::textKeys() as $key) {
            $settings[$key] = self::textValueFor($key);
        }

        foreach (self::checkboxKeys() as $position => $key) {
            $stored = $checkboxPattern[$position % count($checkboxPattern)];
            if ($stored !== null) {
                $settings[$key] = $stored;
            }
        }

        return $settings;
    }

    /**
     * Render the real view with the same extract()+ob_start()+include() mechanism
     * \App\Controllers\BaseController::render() uses for the view layer.
     *
     * @param array<string, string> $settings Stored settings handed to the view.
     *
     * @return string Captured HTML output.
     */
    private static function renderForm(array $settings): string
    {
        self::assertFileExists(self::VIEW_PATH, 'settings view is missing: ' . self::VIEW_PATH);

        return (static function (string $_viewFile, array $_vars): string {
            extract($_vars);
            ob_start();
            try {
                include $_viewFile;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        })(self::VIEW_PATH, ['settings' => $settings, 'csrfToken' => self::CSRF_TOKEN]);
    }

    /** Parse rendered HTML into a queryable document (libxml's own warnings are silenced). */
    private static function parse(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            // The prefix makes libxml read the markup as UTF-8 instead of Latin-1.
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }

    /**
     * Run an XPath query that must be valid and return its element nodes.
     *
     * @return list<DOMElement>
     */
    private static function elements(DOMXPath $xpath, string $expression): array
    {
        $nodes = $xpath->query($expression);
        self::assertNotFalse($nodes, "invalid XPath: {$expression}");

        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Group the named controls inside the form by field name.
     *
     * Only controls inside a <form> count (a field outside it is never
     * submitted), and only named ones (an unnamed control is not submitted
     * either).
     *
     * @return array<string, list<DOMElement>> Field name => controls, in document order.
     */
    private static function controlsByName(DOMXPath $xpath): array
    {
        $byName = [];
        foreach (self::elements($xpath, '//form//input | //form//textarea | //form//select') as $control) {
            $name = $control->getAttribute('name');
            if ($name !== '') {
                $byName[$name][] = $control;
            }
        }

        return $byName;
    }

    /**
     * Render the form with the given checkbox states and group its controls.
     *
     * @param list<string|null> $checkboxPattern See settingsFixture().
     *
     * @return array<string, list<DOMElement>> The managed fields only (the CSRF field is left out).
     */
    private static function managedControls(array $checkboxPattern): array
    {
        $controls = self::controlsByName(self::parse(self::renderForm(self::settingsFixture($checkboxPattern))));
        unset($controls[self::CSRF_FIELD]);

        return $controls;
    }

    /**
     * The value a browser would pre-fill into a text control: the textarea body
     * or the input's value attribute, with HTML entities already decoded.
     */
    private static function prefilledValue(string $key, DOMElement $control): string
    {
        return match ($control->nodeName) {
            'textarea' => $control->textContent,
            'input'    => $control->getAttribute('value'),
            default    => self::fail("{$key}: unsupported <{$control->nodeName}> control; teach this test to read it"),
        };
    }

    /** Whether the control is a checkbox input. */
    private static function isCheckbox(DOMElement $control): bool
    {
        return $control->nodeName === 'input' && strtolower($control->getAttribute('type')) === 'checkbox';
    }

    // -------------------------------------------------------------------------
    // Field names
    // -------------------------------------------------------------------------

    /**
     * The form submits exactly the keys the controller manages: none is missing
     * (so the owner can edit every setting) and none is extra (so no input
     * posts a value the controller silently ignores). Each key has one control.
     */
    public function testFormSubmitsExactlyTheManagedKeys(): void
    {
        $controls = self::managedControls(['1', '0']);
        $names    = array_keys($controls);
        $known    = self::knownKeys();

        $missing = array_values(array_diff($known, $names));
        self::assertSame(
            [],
            $missing,
            'KNOWN_KEYS entries with no input inside the <form> (the owner cannot edit them): ' . implode(', ', $missing)
        );

        $unmanaged = array_values(array_diff($names, $known));
        self::assertSame(
            [],
            $unmanaged,
            'form fields missing from KNOWN_KEYS (the controller would discard them): ' . implode(', ', $unmanaged)
        );

        $duplicated = array_keys(array_filter($controls, static fn (array $found): bool => count($found) > 1));
        self::assertSame(
            [],
            $duplicated,
            'managed keys rendered by more than one control: ' . implode(', ', $duplicated)
        );
    }

    /**
     * The form posts to the route SettingsController::update() serves and
     * carries the CSRF token update() requires.
     */
    public function testFormPostsToTheSettingsRouteWithTheCsrfToken(): void
    {
        $xpath = self::parse(self::renderForm(self::settingsFixture(['1', '0'])));

        $forms = self::elements($xpath, '//form');
        self::assertCount(1, $forms, 'Expected exactly one <form> on the settings page.');
        self::assertSame('POST', strtoupper($forms[0]->getAttribute('method')));
        self::assertSame('/admin/settings', $forms[0]->getAttribute('action'));

        $tokens = self::controlsByName($xpath)[self::CSRF_FIELD] ?? [];
        self::assertCount(1, $tokens, 'Expected exactly one _csrf_token field inside the form.');
        self::assertSame(self::CSRF_TOKEN, $tokens[0]->getAttribute('value'));
    }

    /**
     * A control's type agrees with the controller's CHECKBOX_KEYS: those keys
     * are checkboxes (an unchecked box sends nothing, which valuesFromPost()
     * reads as '0') and every other key is not (a text key would otherwise
     * never be able to submit "off").
     */
    public function testControlTypesAgreeWithTheControllersCheckboxKeys(): void
    {
        $controls = self::managedControls(['1', '0']);

        foreach (self::knownKeys() as $key) {
            self::assertArrayHasKey($key, $controls, "{$key} has no input.");

            $expectCheckbox = in_array($key, self::checkboxKeys(), true);
            self::assertSame(
                $expectCheckbox,
                self::isCheckbox($controls[$key][0]),
                $expectCheckbox
                    ? "{$key} is in CHECKBOX_KEYS, so its input must be a checkbox."
                    : "{$key} is not in CHECKBOX_KEYS, so its input must not be a checkbox."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Pre-filled values
    // -------------------------------------------------------------------------

    /**
     * Every text field's markup carries the HTML-escaped form of its own stored
     * value, which is the same as saying the browser pre-fills exactly that
     * stored value once it decodes the entities. The values contain quotes,
     * markup, an entity-looking "&amp;", a "</textarea>" and multi-byte text, so
     * a missing escape, a doubled escape or a field bound to the wrong key all
     * show up as a different decoded value.
     */
    public function testEveryTextFieldIsPrefilledWithItsEscapedStoredValue(): void
    {
        $settings = self::settingsFixture(['1', '0']);
        $controls = self::controlsByName(self::parse(self::renderForm($settings)));

        foreach (self::textKeys() as $key) {
            self::assertArrayHasKey($key, $controls, "{$key} has no input.");
            self::assertSame(
                $settings[$key],
                self::prefilledValue($key, $controls[$key][0]),
                "{$key} is not pre-filled with its stored value."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Checkboxes
    // -------------------------------------------------------------------------

    /**
     * A checkbox is checked exactly when its stored value is the string '1': a
     * stored '0', an empty or missing row, and look-alike text such as 'on' or
     * 'true' all render unchecked, matching the strict '1' comparison the
     * public layout applies to the same settings.
     */
    public function testCheckboxesAreCheckedExactlyWhenTheStoredValueIsOne(): void
    {
        // Each pattern is cycled over the checkbox keys, so the test holds for any number of them.
        $patterns = [
            'all stored 1'               => ['1'],
            'all stored 0'               => ['0'],
            'alternating 1, 0'           => ['1', '0'],
            'alternating 0, 1'           => ['0', '1'],
            'rows missing'               => [null],
            'empty values'               => [''],
            'look-alike truthy text'     => ['on', 'true'],
            'a 1 with whitespace around' => [' 1 '],
        ];

        foreach ($patterns as $label => $pattern) {
            $controls = self::managedControls($pattern);

            foreach (self::checkboxKeys() as $position => $key) {
                self::assertArrayHasKey($key, $controls, "{$label}: {$key} has no input.");

                $stored = $pattern[$position % count($pattern)];
                self::assertSame(
                    $stored === '1',
                    $controls[$key][0]->hasAttribute('checked'),
                    sprintf('%s: %s stored as %s', $label, $key, var_export($stored, true))
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Home Page title hint
    // -------------------------------------------------------------------------

    /**
     * The Home Page hint tells the owner which text is appended to the page
     * title. It shows the business name trimmed (as PageTitle::compose() trims
     * it) and escaped: the configured name contains markup and quotes, and the
     * browser must read it back as that exact text.
     */
    public function testHomePageHintShowsTheEscapedBusinessName(): void
    {
        self::primeBusinessName("  Perla's <Flowers> & \"Co\" \n");

        self::assertSame(
            "Shown in the browser tab and in search results. \u{201C} | Perla's <Flowers> & \"Co\"\u{201D} is added automatically.",
            self::homePageHint(self::parse(self::renderForm(self::settingsFixture(['1', '0']))))
        );
    }

    /**
     * Without a configured business name nothing is appended to the title, so
     * the hint must not promise a suffix (no dangling quote pair).
     */
    public function testHomePageHintOmitsTheSuffixWhenNoBusinessNameIsConfigured(): void
    {
        foreach (['', '   ', "\t\n"] as $blank) {
            self::primeBusinessName($blank);

            self::assertSame(
                'Shown in the browser tab and in search results.',
                self::homePageHint(self::parse(self::renderForm(self::settingsFixture(['1', '0'])))),
                'BUSINESS_NAME ' . var_export($blank, true)
            );
        }
    }

    /**
     * Text of the Home Page title hint with its whitespace collapsed.
     *
     * @return string The hint's text, entities decoded.
     */
    private static function homePageHint(DOMXPath $xpath): string
    {
        $hints = self::elements($xpath, '//form//p[starts-with(normalize-space(.), "Shown in the browser tab")]');
        self::assertCount(1, $hints, 'Expected exactly one Home Page title hint.');

        return trim((string) preg_replace('/\s+/u', ' ', $hints[0]->textContent));
    }
}
