<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free stock-sufficiency checks for chosen bouquet colors.
 *
 * Decides whether the flower colors a customer has selected across their whole
 * cart can be satisfied by the available stem stock. Nothing here reads the
 * database, session, clock, or environment — the caller passes in both the cart
 * items and a stock map — which keeps the aggregation and boundary behaviour
 * deterministically unit-testable.
 *
 * Demand is aggregated **across every cart line**: each chosen color of each
 * chosen flower type on a line demands `flower_count * qty` stems (the full
 * bouquet stem count applies to every chosen color of every chosen type), and a
 * given (flower_type_id, color_id) pair sums its demand over all lines that pick
 * it. A tracked pair is insufficient when its stock is strictly less than that
 * aggregated demand; an untracked pair (null or missing stock) is never short.
 *
 * @see \App\Support\Cart  Documents the canonical cart line-item shape.
 */
final class StockCheck
{
    /** Demand contributed by a line whose flower_count is null/absent/0. */
    private const NO_DEMAND = 0;

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Return every chosen (flower_type, color) pair whose stock can't meet demand.
     *
     * Walks the cart, aggregates the stem demand for each chosen
     * (flower_type_id, color_id) pair across all lines, then compares each
     * tracked pair's available stock against its total demand. A pair is
     * reported when its non-null stock is strictly less than the aggregated
     * demand. Untracked pairs (stock null or missing from the map) are never
     * reported, no matter how high their demand. The result is de-duplicated and
     * sorted ascending by flower_type_id then color_id so it is deterministic.
     *
     * @param array<int, array<string, mixed>> $items    Cart line items. Each line
     *        consumes: `flower_count` (?int total stems the bouquet needs — a
     *        null/absent/0 value contributes zero demand), `qty` (int how many of
     *        this bouquet), and `colors` (a list of
     *        `['flower_type_id'=>int, 'color_ids'=>int[], 'mixed'=>bool]` entries
     *        describing the chosen colors per flower type).
     * @param array<int, array<int, ?int>> $stockMap Available stem stock keyed as
     *        `[flower_type_id => [flower_color_id => ?int stock_count]]`. A null
     *        value — or a missing flower-type / color entry — means the pair is
     *        untracked and therefore always sufficient. An integer is the number
     *        of stems on hand.
     *
     * @return list<array{flower_type_id: int, color_id: int}> Insufficient pairs,
     *         de-duplicated and sorted by flower_type_id then color_id. Empty when
     *         every chosen color has enough (or untracked) stock.
     *
     * @example Sufficient → empty:
     *   StockCheck::insufficientColors(
     *       [['flower_count' => 6, 'qty' => 1,
     *         'colors' => [['flower_type_id' => 1, 'color_ids' => [10], 'mixed' => false]]]],
     *       [1 => [10 => 6]]
     *   ); // []  (stock 6 >= demand 6*1)
     *
     * @example Insufficient → the short pair:
     *   StockCheck::insufficientColors(
     *       [['flower_count' => 6, 'qty' => 2,
     *         'colors' => [['flower_type_id' => 1, 'color_ids' => [10], 'mixed' => false]]]],
     *       [1 => [10 => 6]]
     *   ); // [['flower_type_id' => 1, 'color_id' => 10]]  (stock 6 < demand 6*2)
     */
    public static function insufficientColors(array $items, array $stockMap): array
    {
        $demand = self::aggregateDemand($items);

        $insufficient = [];
        foreach ($demand as $typeId => $byColor) {
            foreach ($byColor as $colorId => $needed) {
                $stock = $stockMap[$typeId][$colorId] ?? null;
                if ($stock !== null && $stock < $needed) {
                    $insufficient[] = ['flower_type_id' => $typeId, 'color_id' => $colorId];
                }
            }
        }

        // Deterministic order: ascending by flower_type_id, then color_id.
        usort($insufficient, static fn (array $a, array $b): int =>
            [$a['flower_type_id'], $a['color_id']] <=> [$b['flower_type_id'], $b['color_id']]
        );

        return $insufficient;
    }

    /**
     * Aggregate stem demand per (flower_type_id, color_id) across all cart lines.
     *
     * For each line, every chosen color of every chosen flower type demands the
     * full `flower_count * qty` stems; demand for a repeated pair sums across
     * lines. Lines with a null/absent/0 flower_count contribute nothing. The
     * returned map is naturally de-duplicated by its keys.
     *
     * @param array<int, array<string, mixed>> $items Cart line items (see
     *        {@see insufficientColors()} for the consumed keys).
     *
     * @return array<int, array<int, int>> Map of
     *         `[flower_type_id => [color_id => total stems demanded]]`. Only pairs
     *         with positive demand appear.
     */
    private static function aggregateDemand(array $items): array
    {
        $demand = [];

        foreach ($items as $line) {
            $flowerCount = (int) ($line['flower_count'] ?? 0);
            $qty         = (int) ($line['qty'] ?? 0);
            $perPair     = $flowerCount * $qty;

            if ($perPair <= self::NO_DEMAND) {
                continue; // A bouquet needing no stems (or zero of it) adds nothing.
            }

            foreach ((array) ($line['colors'] ?? []) as $choice) {
                $typeId = (int) ($choice['flower_type_id'] ?? 0);
                foreach ((array) ($choice['color_ids'] ?? []) as $rawColorId) {
                    $colorId = (int) $rawColorId;
                    $demand[$typeId][$colorId] = ($demand[$typeId][$colorId] ?? 0) + $perPair;
                }
            }
        }

        return $demand;
    }
}
