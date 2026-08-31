<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure aggregator that turns a flat, any-order list of recognized sales from
 * all three channels into an ordered, gap-filled, week-by-week report series.
 *
 * A naive `GROUP BY week` would get two things wrong that this class exists
 * to fix. First, a week with no sales would simply be absent from the
 * result, and a gap in a report reads as missing data rather than the real
 * information it is ("we sold nothing that week") — so every week from the
 * first sale through the report date is emitted, zero-filled where needed,
 * via {@see \App\Support\WeekBucket::range()}. Second, summing each row's
 * own `recognized_sales` would let a producer's bug (a row whose components
 * don't add up) propagate into the report silently — instead, every
 * aggregated total's `recognized_sales` is *re-derived* from the summed
 * `merchandise`/`delivery`/`tax`/`fees` via
 * {@see \App\Support\SalesChannel::recognize()}, and only compared against
 * the sum of the rows' own `recognized_sales` to raise a warning. This
 * direction matters: the four components are the reported figures for each
 * sale, so they are what the aggregate must be built from, and a mismatch
 * against the naive sum means a producer violated the accounting identity
 * upstream — which the report needs to surface, not inherit.
 *
 * Every money figure is summed as raw (unrounded) floats and rounded to 2
 * decimal places exactly once, after the sum — never per addend — since
 * rounding each addend first would let rounding error accumulate across
 * potentially hundreds of rows in a week.
 *
 * An unrecognized `channel` value (not one of {@see \App\Support\SalesChannel::ALL})
 * is never dropped: it is still folded into the grand total and every week's
 * `total`, and reported as its own key in the `channels` maps, so a data bug
 * upstream makes itself visible as money rather than making money disappear.
 * A row is never rejected for failing to close its own identity either — it
 * is still summed in full and a warning is raised naming its `source_id`.
 * This class only warns; it never throws, because a partially-wrong report
 * is more useful to the business than the report script crashing.
 *
 * No database access, no filesystem access, no side effects.
 *
 * @see \App\Support\SalesChannel Defines the accounting identity and channel constants.
 * @see \App\Support\WeekBucket   Buckets a UTC instant into its business-timezone week.
 */
final class WeeklySalesAggregator
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * A zero-filled `ChannelTotals` shape.
     *
     * Every consumer of this class — gap-filled weeks, zero-filled channels
     * within a week that had no sales, and the empty-input result of
     * {@see self::aggregate()} — uses this so every `ChannelTotals` array in
     * the report has an identical shape and no consumer ever needs `isset()`.
     *
     * @return array{merchandise: float, delivery: float, tax: float, fees: float,
     *               recognized_sales: float, deposit_collected: float, order_count: int}
     *         A `ChannelTotals` with every money figure at `0.00` and `order_count` at `0`.
     *
     * @example
     *   WeeklySalesAggregator::emptyTotals();
     *   // ['merchandise' => 0.00, 'delivery' => 0.00, 'tax' => 0.00, 'fees' => 0.00,
     *   //  'recognized_sales' => 0.00, 'deposit_collected' => 0.00, 'order_count' => 0]
     */
    public static function emptyTotals(): array
    {
        return [
            'merchandise'        => 0.00,
            'delivery'           => 0.00,
            'tax'                => 0.00,
            'fees'               => 0.00,
            'recognized_sales'   => 0.00,
            'deposit_collected'  => 0.00,
            'order_count'        => 0,
        ];
    }

    /**
     * Pure field-by-field addition of two `ChannelTotals`.
     *
     * This does not re-derive `recognized_sales` from the summed components —
     * it is plain addition of every field, including `recognized_sales` as
     * given. {@see self::aggregate()} is responsible for overriding
     * `recognized_sales` with a re-derived value wherever the contract
     * requires that; this method stays a small, honest, reusable primitive.
     *
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float, order_count: int} $a
     *        One `ChannelTotals` operand.
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float, order_count: int} $b
     *        The other `ChannelTotals` operand.
     *
     * @return array{merchandise: float, delivery: float, tax: float, fees: float,
     *               recognized_sales: float, deposit_collected: float, order_count: int}
     *         The field-by-field sum of `$a` and `$b`.
     *
     * @example
     *   WeeklySalesAggregator::sumTotals(
     *       ['merchandise' => 35.00, 'delivery' => 0.00, 'tax' => 2.98, 'fees' => 0.00,
     *        'recognized_sales' => 37.98, 'deposit_collected' => 0.00, 'order_count' => 1],
     *       ['merchandise' => 112.00, 'delivery' => 15.00, 'tax' => 10.82, 'fees' => 0.00,
     *        'recognized_sales' => 137.82, 'deposit_collected' => 63.50, 'order_count' => 1]
     *   );
     *   // ['merchandise' => 147.00, 'delivery' => 15.00, 'tax' => 13.80, 'fees' => 0.00,
     *   //  'recognized_sales' => 175.80, 'deposit_collected' => 63.50, 'order_count' => 2]
     */
    public static function sumTotals(array $a, array $b): array
    {
        return [
            'merchandise'        => $a['merchandise'] + $b['merchandise'],
            'delivery'           => $a['delivery'] + $b['delivery'],
            'tax'                => $a['tax'] + $b['tax'],
            'fees'               => $a['fees'] + $b['fees'],
            'recognized_sales'   => $a['recognized_sales'] + $b['recognized_sales'],
            'deposit_collected'  => $a['deposit_collected'] + $b['deposit_collected'],
            'order_count'        => $a['order_count'] + $b['order_count'],
        ];
    }

    /**
     * Check whether a `ChannelTotals` closes on its own accounting identity.
     *
     * A thin wrapper over {@see \App\Support\SalesChannel::closes()} that
     * unpacks a `ChannelTotals` array, used by tests and by the report
     * script's own self-check before it trusts a total enough to print it.
     *
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float, order_count: int} $totals
     *        The `ChannelTotals` to check.
     *
     * @return bool True when `merchandise + delivery + tax - fees` equals
     *              `recognized_sales` within {@see \App\Support\SalesChannel::IDENTITY_TOLERANCE}.
     *
     * @example
     *   WeeklySalesAggregator::identityHolds([
     *       'merchandise' => 90.00, 'delivery' => 10.00, 'tax' => 7.67, 'fees' => 0.00,
     *       'recognized_sales' => 107.67, 'deposit_collected' => 0.00, 'order_count' => 1,
     *   ]); // true
     */
    public static function identityHolds(array $totals): bool
    {
        return SalesChannel::closes(
            $totals['merchandise'],
            $totals['delivery'],
            $totals['tax'],
            $totals['fees'],
            $totals['recognized_sales']
        );
    }

    /**
     * Aggregate a flat list of channel rows into an ordered, gap-filled,
     * week-by-week report series.
     *
     * Buckets every row by {@see \App\Support\WeekBucket::fromUtc()}, sums
     * it into its channel and into the week's `total`, then fills every week
     * from the first sale through `$throughWeekStart` (or the last sale's
     * week, when null) so a quiet week appears as an explicit zero row
     * rather than a gap. If `$throughWeekStart` is earlier than the last
     * sale's week, real sales are never dropped: the series still runs
     * through the last sale's week, and a warning records that the requested
     * end was extended.
     *
     * @param list<array{channel: string, source_id: string, recognized_at: \DateTimeImmutable,
     *              merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float, warnings: list<string>}> $channelRows
     *        Recognized sales from all channels mixed together, in any order.
     * @param string|null $throughWeekStart The last week to include, as a
     *        `'YYYY-MM-DD'` Monday; defaults to the last sale's week when null.
     *
     * @return array{
     *     weeks: list<array{week_start: string, iso_week: string,
     *         channels: array<string, array{merchandise: float, delivery: float, tax: float,
     *             fees: float, recognized_sales: float, deposit_collected: float, order_count: int}>,
     *         total: array{merchandise: float, delivery: float, tax: float, fees: float,
     *             recognized_sales: float, deposit_collected: float, order_count: int},
     *         warnings: list<string>}>,
     *     totals: array{channels: array<string, array{merchandise: float, delivery: float, tax: float,
     *             fees: float, recognized_sales: float, deposit_collected: float, order_count: int}>,
     *         total: array{merchandise: float, delivery: float, tax: float, fees: float,
     *             recognized_sales: float, deposit_collected: float, order_count: int}},
     *     warnings: list<string>,
     *     first_sale_week: string|null,
     *     last_week: string|null,
     * }
     *         `weeks` is ascending by `week_start` with every three (or more,
     *         if an unrecognized channel was present) channel keys always
     *         present and zero-filled; `totals` is the grand total across
     *         every week; `warnings` collects every row-level and
     *         aggregate-level warning; `first_sale_week`/`last_week` are null
     *         only when `$channelRows` is empty.
     *
     * @example
     *   WeeklySalesAggregator::aggregate([
     *       ['channel' => SalesChannel::SHOP, 'source_id' => 'order-13',
     *        'recognized_at' => new \DateTimeImmutable('2026-07-20 15:00:00', new \DateTimeZone('UTC')),
     *        'merchandise' => 90.00, 'delivery' => 10.00, 'tax' => 7.67, 'fees' => 0.00,
     *        'recognized_sales' => 107.67, 'deposit_collected' => 0.00, 'warnings' => []],
     *   ]);
     *   // weeks: one row for 2026-W29, channels.shop.recognized_sales === 107.67,
     *   // channels.quote and channels.doordash zero-filled, first_sale_week === '2026-07-20'
     */
    public static function aggregate(array $channelRows, ?string $throughWeekStart = null): array
    {
        if ($channelRows === []) {
            return [
                'weeks'           => [],
                'totals'          => [
                    'channels' => self::zeroChannelMap(SalesChannel::ALL),
                    'total'    => self::emptyTotals(),
                ],
                'warnings'        => [],
                'first_sale_week' => null,
                'last_week'       => null,
            ];
        }

        [$rawByWeek, $weekWarnings, $channelKeysSeen, $warnings] = self::bucketRows($channelRows);

        $channelKeys = array_values(array_unique([...SalesChannel::ALL, ...array_keys($channelKeysSeen)]));

        $weekStarts = array_keys($rawByWeek);
        usort($weekStarts, WeekBucket::compareWeekStart(...));

        $firstSaleWeek = $weekStarts[0];
        $lastSaleWeek  = $weekStarts[count($weekStarts) - 1];

        [$rangeEnd, $rangeWarning] = self::resolveRangeEnd($lastSaleWeek, $throughWeekStart);
        if ($rangeWarning !== null) {
            $warnings[] = $rangeWarning;
        }

        $range = WeekBucket::range($firstSaleWeek, $rangeEnd);

        $weeks         = [];
        $grandChannels = self::zeroChannelMap($channelKeys);
        $grandTotal    = self::emptyTotals();

        foreach ($range as $weekInfo) {
            $weekStart = $weekInfo['week_start'];
            $rawForWeek = $rawByWeek[$weekStart] ?? [];
            $warningsForWeek = $weekWarnings[$weekStart] ?? [];

            $channels = [];
            foreach ($channelKeys as $channel) {
                $raw = $rawForWeek[$channel] ?? self::rawZero();
                [$totals, $reportedRecognized] = self::finalizeChannelTotals($raw);

                $mismatch = self::identityMismatchWarning($weekStart, $channel, $totals['recognized_sales'], $reportedRecognized);
                if ($mismatch !== null) {
                    $warningsForWeek[] = $mismatch;
                    $warnings[]        = $mismatch;
                }

                $channels[$channel]      = $totals;
                $grandChannels[$channel] = self::sumTotals($grandChannels[$channel], $totals);
            }

            $weekTotal  = self::withDerivedRecognizedSales(
                array_reduce($channels, self::sumTotals(...), self::emptyTotals())
            );
            $grandTotal = self::sumTotals($grandTotal, $weekTotal);

            $weeks[] = [
                'week_start' => $weekStart,
                'iso_week'   => $weekInfo['iso_week'],
                'channels'   => $channels,
                'total'      => $weekTotal,
                'warnings'   => $warningsForWeek,
            ];
        }

        foreach ($channelKeys as $channel) {
            $grandChannels[$channel] = self::withDerivedRecognizedSales($grandChannels[$channel]);
        }

        return [
            'weeks'           => $weeks,
            'totals'          => [
                'channels' => $grandChannels,
                'total'    => self::withDerivedRecognizedSales($grandTotal),
            ],
            'warnings'        => $warnings,
            'first_sale_week' => $firstSaleWeek,
            'last_week'       => $range[count($range) - 1]['week_start'],
        ];
    }

    /**
     * Decide the last week the gap-filled series must run through, and
     * whether extending past a too-early `$throughWeekStart` needs a warning.
     *
     * @param string $lastSaleWeek The last sale's week, as `'YYYY-MM-DD'`.
     * @param string|null $throughWeekStart The caller's requested end week, or null.
     *
     * @return array{0: string, 1: string|null} The week to end the range on,
     *         and a warning message when `$throughWeekStart` was too early to
     *         honour, or null when no warning is needed.
     */
    private static function resolveRangeEnd(string $lastSaleWeek, ?string $throughWeekStart): array
    {
        if ($throughWeekStart === null) {
            return [$lastSaleWeek, null];
        }

        $comparison = WeekBucket::compareWeekStart($throughWeekStart, $lastSaleWeek);

        if ($comparison > 0) {
            return [$throughWeekStart, null];
        }

        if ($comparison < 0) {
            return [$lastSaleWeek, sprintf(
                'requested throughWeekStart (%s) is earlier than the last sale\'s week (%s);'
                    . ' the report was extended through %s instead so no sales were dropped.',
                $throughWeekStart,
                $lastSaleWeek,
                $lastSaleWeek
            )];
        }

        return [$lastSaleWeek, null];
    }

    /**
     * Bucket every row into its week and channel, accumulating raw
     * (unrounded) sums and collecting every warning a row raises.
     *
     * @param list<array{channel: string, source_id: string, recognized_at: \DateTimeImmutable,
     *              merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float, warnings: list<string>}> $channelRows
     *        The rows to bucket, in any order.
     *
     * @return array{0: array<string, array<string, array{merchandise: float, delivery: float,
     *             tax: float, fees: float, deposit_collected: float, order_count: int,
     *             reported_recognized_sales: float}>>,
     *         1: array<string, list<string>>, 2: array<string, true>, 3: list<string>}
     *         In order: raw sums keyed by `week_start` then `channel`; every
     *         week's own warnings keyed by `week_start`; the set of distinct
     *         channel values seen (as map keys, for `array_unique`-style
     *         dedup); and every warning in encounter order.
     */
    private static function bucketRows(array $channelRows): array
    {
        $rawByWeek       = [];
        $weekWarnings    = [];
        $channelKeysSeen = [];
        $warnings        = [];

        foreach ($channelRows as $row) {
            $weekStart = WeekBucket::fromUtc($row['recognized_at'])['week_start'];
            $channel   = $row['channel'];

            $channelKeysSeen[$channel] = true;

            $rawByWeek[$weekStart][$channel] ??= self::rawZero();
            $rawByWeek[$weekStart][$channel] = self::addRow($rawByWeek[$weekStart][$channel], $row);

            foreach ([...self::rowWarnings($row), ...$row['warnings']] as $warning) {
                $weekWarnings[$weekStart][] = $warning;
                $warnings[]                 = $warning;
            }
        }

        return [$rawByWeek, $weekWarnings, $channelKeysSeen, $warnings];
    }

    /**
     * Build the warnings a single row raises on its own merits: an
     * unrecognized channel, or components that don't close against its own
     * `recognized_sales`.
     *
     * @param array{channel: string, source_id: string, recognized_at: \DateTimeImmutable,
     *             merchandise: float, delivery: float, tax: float, fees: float,
     *             recognized_sales: float, deposit_collected: float, warnings: list<string>} $row
     *        The row to inspect.
     *
     * @return list<string> Self-contained warning sentences naming `source_id`;
     *         empty when the row is clean.
     */
    private static function rowWarnings(array $row): array
    {
        $warnings = [];

        if (!in_array($row['channel'], SalesChannel::ALL, true)) {
            $warnings[] = sprintf(
                '%s: channel "%s" is not a recognized sales channel; included in the grand total anyway.',
                $row['source_id'],
                $row['channel']
            );
        }

        if (!SalesChannel::closes($row['merchandise'], $row['delivery'], $row['tax'], $row['fees'], $row['recognized_sales'])) {
            $warnings[] = sprintf(
                '%s: components (merchandise %.2f + delivery %.2f + tax %.2f - fees %.2f = %.2f)'
                    . ' do not close against its reported recognized_sales (%.2f).',
                $row['source_id'],
                $row['merchandise'],
                $row['delivery'],
                $row['tax'],
                $row['fees'],
                SalesChannel::recognize($row['merchandise'], $row['delivery'], $row['tax'], $row['fees']),
                $row['recognized_sales']
            );
        }

        return $warnings;
    }

    /**
     * Build the warning raised when a `ChannelTotals` re-derived from summed
     * components disagrees with the sum of its rows' own `recognized_sales`.
     *
     * @param string $weekStart The week the mismatch occurred in, as `'YYYY-MM-DD'`.
     * @param string $channel   The channel the mismatch occurred in.
     * @param float  $derivedRecognized The re-derived `recognized_sales`, already rounded.
     * @param float  $reportedRecognized The sum of the rows' own `recognized_sales`, already rounded.
     *
     * @return string|null A self-contained warning sentence, or null when the
     *         two figures agree within {@see \App\Support\SalesChannel::IDENTITY_TOLERANCE}.
     */
    private static function identityMismatchWarning(
        string $weekStart,
        string $channel,
        float $derivedRecognized,
        float $reportedRecognized
    ): ?string {
        if (abs($derivedRecognized - $reportedRecognized) <= SalesChannel::IDENTITY_TOLERANCE) {
            return null;
        }

        return sprintf(
            'week %s, channel "%s": recognized_sales derived from summed components (%.2f)'
                . ' disagrees with the sum of its rows\' reported recognized_sales (%.2f).',
            $weekStart,
            $channel,
            $derivedRecognized,
            $reportedRecognized
        );
    }

    /**
     * A zero-filled raw accumulator for one channel in one week, before
     * rounding and before `recognized_sales` is re-derived.
     *
     * Kept separate from `ChannelTotals` because it carries
     * `reported_recognized_sales` — the running sum of the rows' own
     * `recognized_sales`, used only to detect a mismatch against the
     * re-derived figure, never surfaced to a consumer.
     *
     * @return array{merchandise: float, delivery: float, tax: float, fees: float,
     *               deposit_collected: float, order_count: int, reported_recognized_sales: float}
     *         A zeroed raw accumulator.
     */
    private static function rawZero(): array
    {
        return [
            'merchandise'                => 0.0,
            'delivery'                   => 0.0,
            'tax'                        => 0.0,
            'fees'                       => 0.0,
            'deposit_collected'          => 0.0,
            'order_count'                => 0,
            'reported_recognized_sales'  => 0.0,
        ];
    }

    /**
     * Add one row's raw figures into a raw accumulator.
     *
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *             deposit_collected: float, order_count: int, reported_recognized_sales: float} $raw
     *        The accumulator to add into.
     * @param array{channel: string, source_id: string, recognized_at: \DateTimeImmutable,
     *             merchandise: float, delivery: float, tax: float, fees: float,
     *             recognized_sales: float, deposit_collected: float, warnings: list<string>} $row
     *        The row to add.
     *
     * @return array{merchandise: float, delivery: float, tax: float, fees: float,
     *               deposit_collected: float, order_count: int, reported_recognized_sales: float}
     *         The updated accumulator.
     */
    private static function addRow(array $raw, array $row): array
    {
        return [
            'merchandise'               => $raw['merchandise'] + $row['merchandise'],
            'delivery'                  => $raw['delivery'] + $row['delivery'],
            'tax'                       => $raw['tax'] + $row['tax'],
            'fees'                      => $raw['fees'] + $row['fees'],
            'deposit_collected'         => $raw['deposit_collected'] + $row['deposit_collected'],
            'order_count'               => $raw['order_count'] + 1,
            'reported_recognized_sales' => $raw['reported_recognized_sales'] + $row['recognized_sales'],
        ];
    }

    /**
     * Round a raw accumulator's money figures once and re-derive
     * `recognized_sales` from the rounded components.
     *
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *             deposit_collected: float, order_count: int, reported_recognized_sales: float} $raw
     *        The raw accumulator to finalize.
     *
     * @return array{0: array{merchandise: float, delivery: float, tax: float, fees: float,
     *             recognized_sales: float, deposit_collected: float, order_count: int},
     *         1: float}
     *         The finished `ChannelTotals`, and the rounded sum of the rows'
     *         own `recognized_sales` (for the caller's mismatch check).
     */
    private static function finalizeChannelTotals(array $raw): array
    {
        $merchandise = round($raw['merchandise'], 2);
        $delivery    = round($raw['delivery'], 2);
        $tax         = round($raw['tax'], 2);
        $fees        = round($raw['fees'], 2);
        $deposit     = round($raw['deposit_collected'], 2);

        $totals = [
            'merchandise'        => $merchandise,
            'delivery'           => $delivery,
            'tax'                => $tax,
            'fees'               => $fees,
            'recognized_sales'   => SalesChannel::recognize($merchandise, $delivery, $tax, $fees),
            'deposit_collected'  => $deposit,
            'order_count'        => $raw['order_count'],
        ];

        return [$totals, round($raw['reported_recognized_sales'], 2)];
    }

    /**
     * Override a `ChannelTotals`' `recognized_sales` with the value derived
     * from its own `merchandise`/`delivery`/`tax`/`fees`.
     *
     * Used for the week `total` and the grand `totals.total`, which are
     * themselves aggregated `ChannelTotals` (summed across channels or
     * weeks) and so are held to the same derive-don't-sum rule as a single
     * channel's total.
     *
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *             recognized_sales: float, deposit_collected: float, order_count: int} $totals
     *        The `ChannelTotals` whose `recognized_sales` is to be re-derived.
     *
     * @return array{merchandise: float, delivery: float, tax: float, fees: float,
     *               recognized_sales: float, deposit_collected: float, order_count: int}
     *         The same totals with `recognized_sales` replaced by the derived value.
     */
    private static function withDerivedRecognizedSales(array $totals): array
    {
        $totals['recognized_sales'] = SalesChannel::recognize(
            $totals['merchandise'],
            $totals['delivery'],
            $totals['tax'],
            $totals['fees']
        );

        return $totals;
    }

    /**
     * A `ChannelTotals` map zero-filled for a given list of channel keys.
     *
     * @param list<string> $channelKeys The channel keys the map must contain.
     *
     * @return array<string, array{merchandise: float, delivery: float, tax: float, fees: float,
     *               recognized_sales: float, deposit_collected: float, order_count: int}>
     *         One zero-filled `ChannelTotals` per key in `$channelKeys`.
     */
    private static function zeroChannelMap(array $channelKeys): array
    {
        $map = [];
        foreach ($channelKeys as $channel) {
            $map[$channel] = self::emptyTotals();
        }

        return $map;
    }
}
