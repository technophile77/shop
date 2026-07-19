<?php

declare(strict_types=1);

namespace App\Tests\Data;

use PHPUnit\Framework\TestCase;

/**
 * Integrity tests against the REAL lang/en.php and lang/es.php closure.*
 * translation keys. Guards against a translation drifting out of sync: a
 * missing ES key would silently render the literal key on a live page (see
 * \App\Core\Lang::get()), and a dropped %s placeholder would make sprintf()
 * emit the wrong string or throw.
 *
 * The files are loaded directly with require rather than through
 * \App\Core\Lang, because Lang caches its loaded arrays statically and this
 * test needs to see the real file contents every run.
 *
 * @see lang/en.php
 * @see lang/es.php
 * @see \App\Core\Lang
 */
final class ClosureLangIntegrityTest extends TestCase
{
    /** @var array<string, string> */
    private array $en;

    /** @var array<string, string> */
    private array $es;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->en = require $root . '/lang/en.php';
        $this->es = require $root . '/lang/es.php';
    }

    /**
     * @return list<string> Sorted closure.* keys from the given translation array.
     */
    private static function closureKeys(array $strings): array
    {
        $keys = array_filter(array_keys($strings), static fn (string $key): bool => str_starts_with($key, 'closure.'));
        sort($keys);
        return array_values($keys);
    }

    public function testClosureKeysMatchBetweenEnAndEs(): void
    {
        $enKeys = self::closureKeys($this->en);
        $esKeys = self::closureKeys($this->es);

        $missingFromEs = array_values(array_diff($enKeys, $esKeys));
        $missingFromEn = array_values(array_diff($esKeys, $enKeys));

        self::assertSame(
            $enKeys,
            $esKeys,
            'closure.* keys differ between lang/en.php and lang/es.php. '
                . 'Missing from es.php: [' . implode(', ', $missingFromEs) . ']. '
                . 'Missing from en.php: [' . implode(', ', $missingFromEn) . '].'
        );
    }

    public function testClosureMonthsExplodeToTwelveNonEmptyValues(): void
    {
        foreach (['en' => $this->en, 'es' => $this->es] as $lang => $strings) {
            self::assertArrayHasKey('closure.months', $strings, "closure.months missing from {$lang}.php");

            $months = explode(',', $strings['closure.months']);
            self::assertCount(12, $months, "closure.months in {$lang}.php did not explode to 12 values");

            foreach ($months as $i => $month) {
                self::assertNotSame('', trim($month), "closure.months in {$lang}.php has an empty value at index {$i}");
            }
        }
    }

    public function testPlaceholderCountsMatchBetweenEnAndEs(): void
    {
        $enKeys = self::closureKeys($this->en);
        $esKeys = self::closureKeys($this->es);
        $sharedKeys = array_intersect($enKeys, $esKeys);

        foreach ($sharedKeys as $key) {
            $enCount = substr_count($this->en[$key], '%s');
            $esCount = substr_count($this->es[$key], '%s');

            self::assertSame(
                $enCount,
                $esCount,
                "{$key}: %s placeholder count differs (en={$enCount}, es={$esCount})"
            );
        }
    }

    public function testNoClosureValueIsEmpty(): void
    {
        foreach (['en' => $this->en, 'es' => $this->es] as $lang => $strings) {
            foreach (self::closureKeys($strings) as $key) {
                self::assertNotSame('', $strings[$key], "{$key} in {$lang}.php is an empty string");
            }
        }
    }
}
