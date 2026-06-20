<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\StockCheck;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure stock-sufficiency logic in App\Support\StockCheck.
 *
 * Cart items and the stock map are injected, so per-pair demand aggregation,
 * the strict `<` boundary, cross-line summing, and the untracked-is-sufficient
 * rule are exercised deterministically. Includes one fixed-seed stochastic
 * sweep asserting the over-stocked / under-by-one invariants (this repo keeps
 * stochastic sweeps inline in the unit suite — see LocalAreasIntegrityTest).
 *
 * @see \App\Support\StockCheck
 */
final class StockCheckTest extends TestCase
{
    /**
     * Build a single cart line from the keys StockCheck consumes.
     *
     * @param int|null $flowerCount Stems the bouquet needs (null/0 = no demand).
     * @param int      $qty         How many of this bouquet.
     * @param array<int, array{flower_type_id: int, color_ids: int[]}> $colors
     */
    private static function line(?int $flowerCount, int $qty, array $colors): array
    {
        $normalized = array_map(static fn (array $c): array => [
            'flower_type_id' => $c['flower_type_id'],
            'color_ids'      => $c['color_ids'],
            'mixed'          => $c['mixed'] ?? false,
        ], $colors);

        return ['flower_count' => $flowerCount, 'qty' => $qty, 'colors' => $normalized];
    }

    public function testEmptyCartIsSufficient(): void
    {
        self::assertSame([], StockCheck::insufficientColors([], []));
        self::assertSame([], StockCheck::insufficientColors([], [1 => [10 => 0]]));
    }

    public function testUntrackedColorIsAlwaysSufficient(): void
    {
        $items = [self::line(6, 5, [['flower_type_id' => 1, 'color_ids' => [10]]])];

        // Explicit null stock for the chosen pair → untracked → sufficient.
        self::assertSame([], StockCheck::insufficientColors($items, [1 => [10 => null]]));

        // Pair missing from the map entirely → also untracked → sufficient.
        self::assertSame([], StockCheck::insufficientColors($items, []));
        self::assertSame([], StockCheck::insufficientColors($items, [1 => [99 => 3]]));
    }

    public function testExactStockIsSufficientBoundary(): void
    {
        // stock_count == flower_count * qty → `>=` holds → [].
        $items = [self::line(6, 2, [['flower_type_id' => 1, 'color_ids' => [10]]])];
        self::assertSame([], StockCheck::insufficientColors($items, [1 => [10 => 12]]));
    }

    public function testInsufficientWhenStockBelowDemand(): void
    {
        // stock 11 < demand 12 → that pair is reported.
        $items = [self::line(6, 2, [['flower_type_id' => 1, 'color_ids' => [10]]])];
        self::assertSame(
            [['flower_type_id' => 1, 'color_id' => 10]],
            StockCheck::insufficientColors($items, [1 => [10 => 11]])
        );
    }

    public function testQtyMultiplierPushesDemandOverStock(): void
    {
        $colors = [['flower_type_id' => 1, 'color_ids' => [10]]];

        // qty 1: demand 6 <= stock 6 → sufficient.
        self::assertSame([], StockCheck::insufficientColors(
            [self::line(6, 1, $colors)],
            [1 => [10 => 6]]
        ));

        // qty 2: demand 12 > stock 6 → insufficient.
        self::assertSame(
            [['flower_type_id' => 1, 'color_id' => 10]],
            StockCheck::insufficientColors([self::line(6, 2, $colors)], [1 => [10 => 6]])
        );
    }

    public function testNullOrZeroFlowerCountContributesNoDemand(): void
    {
        $colors = [['flower_type_id' => 1, 'color_ids' => [10]]];

        // flower_count null → no demand even against zero stock.
        self::assertSame([], StockCheck::insufficientColors(
            [self::line(null, 4, $colors)],
            [1 => [10 => 0]]
        ));

        // flower_count 0 → no demand even against zero stock.
        self::assertSame([], StockCheck::insufficientColors(
            [self::line(0, 4, $colors)],
            [1 => [10 => 0]]
        ));
    }

    public function testCrossLineAggregationTipsOverStock(): void
    {
        // Same (type 1, color 10) picked on two lines: demand 6 + 6 = 12 > 10.
        $items = [
            self::line(6, 1, [['flower_type_id' => 1, 'color_ids' => [10]]]),
            self::line(6, 1, [['flower_type_id' => 1, 'color_ids' => [10]]]),
        ];
        self::assertSame(
            [['flower_type_id' => 1, 'color_id' => 10]],
            StockCheck::insufficientColors($items, [1 => [10 => 10]])
        );

        // De-duplicated: the same short pair appears once even from two lines.
        $result = StockCheck::insufficientColors($items, [1 => [10 => 0]]);
        self::assertCount(1, $result);
    }

    public function testCrossLineAggregationStaysSufficientWhenStockCovers(): void
    {
        // demand 12 with stock exactly 12 → boundary still sufficient.
        $items = [
            self::line(4, 1, [['flower_type_id' => 1, 'color_ids' => [10]]]),
            self::line(8, 1, [['flower_type_id' => 1, 'color_ids' => [10]]]),
        ];
        self::assertSame([], StockCheck::insufficientColors($items, [1 => [10 => 12]]));
    }

    public function testMultipleFlowerTypesConstrainedIndependently(): void
    {
        // One line, two flower types, each color demands flower_count*qty = 5*1.
        $items = [self::line(5, 1, [
            ['flower_type_id' => 1, 'color_ids' => [10, 11]],
            ['flower_type_id' => 2, 'color_ids' => [20]],
        ])];

        $stock = [
            1 => [10 => 5, 11 => 4],   // 11 is short (4 < 5)
            2 => [20 => 5],            // sufficient
        ];

        self::assertSame(
            [['flower_type_id' => 1, 'color_id' => 11]],
            StockCheck::insufficientColors($items, $stock)
        );
    }

    public function testZeroStockWithPositiveDemandIsInsufficient(): void
    {
        $items = [self::line(3, 1, [['flower_type_id' => 7, 'color_ids' => [70]]])];
        self::assertSame(
            [['flower_type_id' => 7, 'color_id' => 70]],
            StockCheck::insufficientColors($items, [7 => [70 => 0]])
        );
    }

    public function testResultIsSortedByTypeThenColor(): void
    {
        $items = [self::line(2, 1, [
            ['flower_type_id' => 2, 'color_ids' => [30, 10]],
            ['flower_type_id' => 1, 'color_ids' => [20]],
        ])];

        // All zero stock → every chosen pair is short.
        $stock = [
            1 => [20 => 0],
            2 => [10 => 0, 30 => 0],
        ];

        self::assertSame(
            [
                ['flower_type_id' => 1, 'color_id' => 20],
                ['flower_type_id' => 2, 'color_id' => 10],
                ['flower_type_id' => 2, 'color_id' => 30],
            ],
            StockCheck::insufficientColors($items, $stock)
        );
    }

    /**
     * Stochastic sweep over random valid carts and stock maps.
     *
     * Two invariants, checked on each random cart:
     *   1. If every tracked stock_count is raised to at least the total possible
     *      demand for its pair, the result is always [] (nothing can be short).
     *   2. If a single chosen, tracked pair is then set to (its demand − 1), that
     *      exact pair always appears in the result.
     *
     * Deterministic via a fixed mt_srand seed so failures reproduce.
     */
    public function testStochasticOverStockedIsEmptyAndUnderByOneIsReported(): void
    {
        mt_srand(20260620);

        for ($iter = 0; $iter < 300; $iter++) {
            $context  = "iteration {$iter}";
            $typeIds  = [1, 2, 3];
            $colorIds = [10, 11, 12, 13];

            // Build a random cart of 1–4 lines, each picking random colors.
            $lineCount = mt_rand(1, 4);
            $items     = [];
            for ($l = 0; $l < $lineCount; $l++) {
                $flowerCount = mt_rand(0, 8);
                $qty         = mt_rand(1, 4);

                $colors = [];
                foreach ($typeIds as $typeId) {
                    if (mt_rand(0, 1) === 0) {
                        continue; // This line doesn't use this flower type.
                    }
                    // Pick 1–3 distinct colors for this type.
                    $picked = $colorIds;
                    shuffle($picked);
                    $picked = array_slice($picked, 0, mt_rand(1, 3));
                    $colors[] = ['flower_type_id' => $typeId, 'color_ids' => $picked, 'mixed' => false];
                }
                if ($colors === []) {
                    // Guarantee at least one color choice on the line.
                    $colors[] = ['flower_type_id' => 1, 'color_ids' => [10], 'mixed' => false];
                }

                $items[] = ['flower_count' => $flowerCount, 'qty' => $qty, 'colors' => $colors];
            }

            // Compute the true aggregated demand independently of StockCheck.
            $demand = [];
            foreach ($items as $line) {
                $per = (int) $line['flower_count'] * (int) $line['qty'];
                if ($per <= 0) {
                    continue;
                }
                foreach ($line['colors'] as $c) {
                    foreach ($c['color_ids'] as $cid) {
                        $demand[$c['flower_type_id']][$cid] = ($demand[$c['flower_type_id']][$cid] ?? 0) + $per;
                    }
                }
            }

            // Invariant 1: track every pair at exactly its full demand → sufficient.
            $generous = [];
            foreach ($demand as $typeId => $byColor) {
                foreach ($byColor as $cid => $need) {
                    $generous[$typeId][$cid] = $need; // stock == demand, boundary OK
                }
            }
            self::assertSame(
                [],
                StockCheck::insufficientColors($items, $generous),
                "over-stocked should be empty ({$context})"
            );

            // Invariant 2: knock one tracked pair with positive demand down by one.
            $shortPairs = [];
            foreach ($demand as $typeId => $byColor) {
                foreach ($byColor as $cid => $need) {
                    if ($need > 0) {
                        $shortPairs[] = [$typeId, $cid];
                    }
                }
            }
            if ($shortPairs === []) {
                continue; // Whole cart was zero-demand; nothing to under-stock.
            }

            [$tType, $tColor] = $shortPairs[mt_rand(0, count($shortPairs) - 1)];
            $undersupplied             = $generous;
            $undersupplied[$tType][$tColor] = $demand[$tType][$tColor] - 1;

            $result = StockCheck::insufficientColors($items, $undersupplied);
            self::assertContains(
                ['flower_type_id' => $tType, 'color_id' => $tColor],
                $result,
                "under-by-one pair must be reported ({$context}, pair {$tType}/{$tColor})"
            );
        }
    }
}
