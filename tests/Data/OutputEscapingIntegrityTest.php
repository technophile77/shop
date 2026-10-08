<?php

declare(strict_types=1);

namespace App\Tests\Data;

use PHPUnit\Framework\TestCase;

/**
 * Source-level guards against double-escaped page metadata and unsafe JSON-LD.
 *
 * The layouts HTML-escape pageTitle (the public one metaDesc too) themselves, but three controllers escaped
 * it first, so "Perla's Flowers" read "Perla&#039;s Flowers" in the browser tab; and json_encode() output in a
 * <script type="application/ld+json"> block needs JSON_HEX_TAG, or "</script>" in a value ends the block early.
 * Rendering is out of reach here (database, session), so the tests read the real sources with token_get_all().
 * Not covered: a value passed through a helper that escapes it, and a JSON-LD <script> tag printed by PHP code.
 *
 * @see views/layouts/public.php
 */
final class OutputEscapingIntegrityTest extends TestCase
{
    /** Controllers that once escaped pageTitle; the scan must still find bindings in each. */
    private const FORMERLY_DOUBLE_ESCAPING = ['src/Controllers/ProductController.php', 'src/Controllers/ShopController.php', 'src/Controllers/Admin/CampaignsController.php'];

    /** Templates that print JSON-LD; the scan must still find a json_encode() call in each. */
    private const JSON_LD_VIEWS = [
        'views/layouts/public.php', 'views/public/local-area.php', 'views/public/local-venue.php',
        'views/public/occasion.php', 'views/public/product.php', 'views/public/products.php',
    ];

    /**
     * Controller-scanner fixtures. Each case is a `=== <lines> <label>` line, then PHP source (a `<?php` line is
     * prepended). <lines> lists the lines of the escaping calls the scanner must flag, comma-separated, or `-`.
     */
    private const CONTROLLER_CASES = <<<'PHP'
    === 3 both halves escaped, as ProductController::byCategory() once did
    $siteTitle = Settings::get('shop_name', "Perla's Flowers");
    $pageTitle = htmlspecialchars($categoryName) . ' — ' . htmlspecialchars($siteTitle);
    === 4 array entry, as CampaignsController::show() once did
    return $this->render('admin/campaigns/detail', [
        'csrfToken' => $csrfToken,
        'pageTitle' => htmlspecialchars($campaign['name'] ?? 'Campaign'),
    ], 'admin');
    === 3 multi-line ternary
    $metaDesc = $desc !== ''
        ? htmlspecialchars(mb_substr($desc, 0, 155))
        : sprintf('%s - %s', $name, $site);
    === 2 fully qualified call
    $metaDesc = \htmlentities($blurb, ENT_QUOTES);
    === - raw value; an escape in a later statement is not part of it
    $pageTitle = Settings::get('products_page_title_' . $lang) ?? __t('products.title');
    $label = htmlspecialchars($name);
    === - escape belongs to the next array entry; a closing bracket ends the last entry's value
    $vars = ['pageTitle' => $name, 'label' => htmlspecialchars($label)];
    $last = ['metaDesc' => $name];
    $other = htmlspecialchars($label);
    === - commented-out code, a DocBlock example and a string literal
    // $pageTitle = htmlspecialchars($name);
    /** @example 'pageTitle' => htmlspecialchars($name) */
    $note = "set pageTitle = htmlspecialchars(x)";
    $pageTitle = $name;
    PHP;

    /**
     * JSON-LD scanner fixtures, same layout (no prepended line). <lines> lists the json_encode() calls inside
     * application/ld+json blocks as `<line>+` (names JSON_HEX_TAG) or `<line>-` (does not), or `-` for none.
     */
    private const JSON_LD_CASES = <<<'HTML'
    === 2- call without the flag, as the views once had it
    <script type="application/ld+json">
    <?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>
    === 2+,6- flag after a nested call, then a second block without it
    <script type="application/ld+json">
    <?= json_encode(array_merge($a, ['k' => 1]), JSON_PRETTY_PRINT | JSON_HEX_TAG) ?>
    </script>
    <p>between</p>
    <script type="application/ld+json">
    <?= json_encode($b) ?>
    </script>
    === - ignored: plain script, hand-written JSON-LD, markup after the closing tag
    <script>var a = <?= json_encode($a) ?>;</script>
    <script type="application/ld+json">{"name": "<?= htmlspecialchars($name) ?>"}</script>
    <div x-data="<?= htmlspecialchars(json_encode($state)) ?>"></div>
    HTML;

    // --- Scanners ------------------------------------------------------------

    /** Read every PHP file under a project directory, keyed by root-relative path with forward slashes. */
    private static function sources(string $directory): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$directory", \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($out);
        self::assertNotEmpty($out, "No PHP files under {$directory}/ - the guard would check nothing.");

        return $out;
    }

    /**
     * Significant tokens as [id, text, start line] (id null for a single character); whitespace and
     * comments are dropped, so commented-out code is never mistaken for code.
     *
     * @return list<array{0: int|null, 1: string, 2: int}>
     */
    private static function tokens(string $source): array
    {
        $tokens = [];
        $line   = 1;
        foreach (token_get_all($source) as $token) {
            [$id, $text, $line] = is_array($token) ? $token : [null, $token, $line];
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = [$id, $text, $line];
            }
            $line += substr_count($text, "\n");
        }

        return $tokens;
    }

    /** Whether the token is the bare single character $char; characters inside strings never are. */
    private static function isChar(?array $token, string $char): bool
    {
        return $token !== null && $token[0] === null && $token[1] === $char;
    }

    /**
     * The places the source gives pageTitle or metaDesc a value (`$x = ...` with `=`, `.=` or `??=`,
     * `$data['x'] = ...`, or a `'x' => ...` entry), each with the first escaping call in that value.
     *
     * @return list<array{0: string, 1: int, 2: array{0: string, 1: int}|null}> [name as written, its line, [function, line] or null].
     */
    private static function pageMetaBindings(string $source): array
    {
        $t        = self::tokens($source);
        $assigns  = static fn (?array $tok): bool => self::isChar($tok, '=') || ($tok !== null && in_array($tok[0], [T_CONCAT_EQUAL, T_COALESCE_EQUAL], true));
        $bindings = [];
        foreach ($t as $i => [$id, $text, $line]) {
            $name  = $id === T_VARIABLE ? ltrim($text, '$') : ($id === T_CONSTANT_ENCAPSED_STRING ? substr($text, 1, -1) : '');
            $next  = $t[$i + 1] ?? null;
            $start = match (true) {
                !in_array($name, ['pageTitle', 'metaDesc'], true)       => null,
                $id === T_VARIABLE                                      => $assigns($next) ? $i + 2 : null,
                $next !== null && $next[0] === T_DOUBLE_ARROW           => $i + 2,
                self::isChar($next, ']') && $assigns($t[$i + 2] ?? null) => $i + 3,
                default                                                 => null,
            };
            if ($start !== null) {
                $bindings[] = [$text, $line, self::firstEscape($t, $start)];
            }
        }

        return $bindings;
    }

    /**
     * First htmlspecialchars/htmlentities call (or callable-string mention) in the value starting at token $from,
     * which ends at the `;` or `,` outside any brackets, or at the bracket closing its array.
     *
     * @param list<array{0: int|null, 1: string, 2: int}> $t
     *
     * @return array{0: string, 1: int}|null [lower-case function name, line], or null.
     */
    private static function firstEscape(array $t, int $from): ?array
    {
        for ($depth = 0, $i = $from; isset($t[$i]); $i++) {
            [$id, $text, $line] = $t[$i];
            if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true) || ($id === null && str_contains('([{', $text))) {
                $depth++;
            } elseif ($id === null && str_contains(')]}', $text)) {
                if (--$depth < 0) {
                    break;
                }
            } elseif ($depth === 0 && ($id === T_CLOSE_TAG || self::isChar($t[$i], ',') || self::isChar($t[$i], ';'))) {
                break;
            }

            $name     = in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) ? $text : ($id === T_CONSTANT_ENCAPSED_STRING ? substr($text, 1, -1) : '');
            $function = strtolower(ltrim($name, '\\'));
            if ($function === 'htmlspecialchars' || $function === 'htmlentities') {
                return [$function, $line];
            }
        }

        return null;
    }

    /**
     * The json_encode() calls inside application/ld+json script blocks; a call taking its flags from a
     * variable counts as missing JSON_HEX_TAG.
     *
     * @return list<array{0: int, 1: bool}> [line, whether JSON_HEX_TAG is among the call's own arguments].
     */
    private static function jsonLdEncodes(string $source): array
    {
        $t      = self::tokens($source);
        $inside = false;
        $calls  = [];
        foreach ($t as $i => [$id, $text, $line]) {
            if ($id === T_INLINE_HTML) {
                preg_match_all('~<script\b[^>]*>|</script\s*>~i', $text, $tags);
                foreach ($tags[0] as $tag) {
                    $inside = $tag[1] !== '/' && preg_match('~(?<![\w-])type\s*=\s*(["\']?)application/ld\+json\1~i', $tag) === 1;
                }
            } elseif ($inside && in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) && strcasecmp(ltrim($text, '\\'), 'json_encode') === 0 && self::isChar($t[$i + 1] ?? null, '(')) {
                $hasHexTag = false;
                for ($depth = 0, $j = $i + 1; isset($t[$j]); $j++) {
                    $depth += (int) self::isChar($t[$j], '(') - (int) self::isChar($t[$j], ')');
                    $hasHexTag = $hasHexTag || (in_array($t[$j][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ltrim($t[$j][1], '\\') === 'JSON_HEX_TAG');
                    if ($depth === 0) {
                        break;
                    }
                }
                $calls[] = [$line, $hasHexTag];
            }
        }

        return $calls;
    }

    // --- Guards over the real source files -----------------------------------

    /** No controller may hand the layouts an already-escaped pageTitle or metaDesc. */
    public function testControllersNeverPreEscapePageTitleOrMetaDesc(): void
    {
        $violations = [];
        foreach (self::sources('src/Controllers') as $path => $source) {
            foreach (self::pageMetaBindings($source) as [$name, $line, $escape]) {
                if ($escape !== null) {
                    $violations[] = "{$path}:{$escape[1]}: {$name} is given a value built with {$escape[0]}() (binding on line {$line})";
                }
            }
        }

        self::assertSame([], $violations, "The layouts escape pageTitle/metaDesc themselves; escaping first shows Perla&#039;s Flowers in the tab. Pass raw text:\n" . implode("\n", $violations));
    }

    /** Every json_encode() call that prints JSON-LD must hex-encode angle brackets, so "</script>" in a value cannot end the block. */
    public function testJsonLdJsonEncodeCallsPassJsonHexTag(): void
    {
        $violations = [];
        foreach (self::sources('views') as $path => $source) {
            foreach (self::jsonLdEncodes($source) as [$line, $hasHexTag]) {
                if (!$hasHexTag) {
                    $violations[] = "{$path}:{$line}: json_encode() prints into an application/ld+json block without JSON_HEX_TAG";
                }
            }
        }

        self::assertSame([], $violations, "Add | JSON_HEX_TAG to the json_encode() call itself (a flags variable is not recognised):\n" . implode("\n", $violations));
    }

    /** The controller scan still finds bindings in the controllers that once double-escaped, so the guard cannot go quiet. */
    public function testControllerScanFindsTheBindingsItGuards(): void
    {
        $sources = self::sources('src/Controllers');
        foreach (self::FORMERLY_DOUBLE_ESCAPING as $path) {
            self::assertNotEmpty(self::pageMetaBindings($sources[$path] ?? ''), "No pageTitle/metaDesc binding found in {$path}: it is gone or the scan is broken.");
        }
    }

    /** The view scan still finds a JSON-LD json_encode() call in every template that prints one, so the guard cannot go quiet. */
    public function testJsonLdScanFindsTheTemplatesItGuards(): void
    {
        $sources = self::sources('views');
        foreach (self::JSON_LD_VIEWS as $path) {
            self::assertNotEmpty(self::jsonLdEncodes($sources[$path] ?? ''), "No json_encode() found in an application/ld+json block of {$path}: it is gone or the scan is broken.");
        }
    }

    // --- Scanner fixtures ----------------------------------------------------

    /** Split a fixture table into [expectation, label, source] cases. */
    private static function cases(string $table): array
    {
        return array_chunk(preg_split('/^=== (\S+) (.*)\n/m', str_replace("\r\n", "\n", $table), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY), 3);
    }

    /** Escaped values are flagged at the line of the escaping call; raw values and look-alikes are not. */
    public function testControllerScannerFlagsEscapedValuesOnly(): void
    {
        foreach (self::cases(self::CONTROLLER_CASES) as [$expect, $label, $source]) {
            $bindings = self::pageMetaBindings("<?php\n" . $source);
            $flagged  = array_values(array_map(fn (array $b): int => $b[2][1], array_filter($bindings, fn (array $b): bool => $b[2] !== null)));

            self::assertNotEmpty($bindings, "{$label}: no binding found");
            self::assertSame($expect === '-' ? [] : array_map('intval', explode(',', $expect)), $flagged, $label);
        }
    }

    /** Only json_encode() calls inside application/ld+json blocks are reported, with whether they name JSON_HEX_TAG. */
    public function testJsonLdScannerReportsCallsInsideJsonLdBlocksOnly(): void
    {
        foreach (self::cases(self::JSON_LD_CASES) as [$expect, $label, $source]) {
            $found = array_map(fn (array $call): string => $call[0] . ($call[1] ? '+' : '-'), self::jsonLdEncodes($source));

            self::assertSame($expect === '-' ? [] : explode(',', $expect), $found, $label);
        }
    }
}
