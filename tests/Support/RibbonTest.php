<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Ribbon;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Support\Ribbon — pure, no DB.
 *
 * Verifies the ribbon character-limit formula scales with bouquet size and is
 * clamped to the inclusive range [10, 15] at every boundary.
 *
 * @see \App\Support\Ribbon
 */
final class RibbonTest extends TestCase
{
    /**
     * Boundary table: flower count → expected character limit.
     *
     * @return array<string, array{0:int, 1:int}>
     */
    public static function limitProvider(): array
    {
        return [
            'zero stems'         => [0, 10],
            'just below first +' => [9, 10],
            'first increment'    => [10, 11],
            'mid-range'          => [25, 12],
            'just below max'     => [49, 14],
            'reaches max'        => [50, 15],
            'above max stays 15' => [100, 15],
            'far above max'      => [1000, 15],
            'negative clamps'    => [-5, 10],
        ];
    }

    /**
     * ribbonCharLimit() returns the clamped, size-scaled limit.
     *
     * @dataProvider limitProvider
     */
    public function testRibbonCharLimit(int $flowerCount, int $expected): void
    {
        self::assertSame($expected, Ribbon::ribbonCharLimit($flowerCount));
    }

    /**
     * The result is always within [10, 15] for a wide sweep of inputs.
     */
    public function testRibbonCharLimitAlwaysInRange(): void
    {
        for ($n = -20; $n <= 200; $n++) {
            $limit = Ribbon::ribbonCharLimit($n);
            self::assertGreaterThanOrEqual(10, $limit);
            self::assertLessThanOrEqual(15, $limit);
        }
    }
}
