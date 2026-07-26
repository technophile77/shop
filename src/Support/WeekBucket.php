<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free converter from UTC instants to ISO-8601 calendar
 * weeks in the business timezone, for the weekly sales report.
 *
 * This class exists because of a real one-hour timezone hazard: the live
 * MySQL server's `@@session.time_zone` is `SYSTEM`, which resolves to US
 * Eastern, while the app runs in America/Chicago. The report script works
 * around this by pinning the DB connection to `SET SESSION time_zone =
 * '+00:00'` so every `TIMESTAMP` comes out as UTC, and this class is the
 * single place that UTC is then converted into a business-timezone week.
 *
 * Weeks are ISO-8601: they start on Monday and are labelled `YYYY-Www`.
 *
 * This class deliberately never relies on `date_default_timezone_set()`
 * having been called — every `\DateTimeImmutable` is constructed or
 * converted with an explicit `\DateTimeZone`, so behaviour is identical
 * regardless of the ambient default timezone. No database access, no
 * side effects.
 *
 * @see \App\Support\SalesChannel Defines the recognized-sales identity that
 *      produces the rows this class buckets into weeks.
 */
final class WeekBucket
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * IANA name of the timezone the sales report is presented in.
     *
     * Chosen because the business operates out of Tulsa, Oklahoma, which
     * observes America/Chicago's DST rules.
     */
    public const BUSINESS_TIMEZONE = 'America/Chicago';

    /**
     * The business timezone as a reusable `\DateTimeZone` object.
     *
     * @return \DateTimeZone The business timezone, for converting UTC
     *         instants to local wall-clock time or constructing local
     *         instants directly.
     *
     * @example
     *   WeekBucket::businessZone()->getName();
     *   // 'America/Chicago'
     */
    public static function businessZone(): \DateTimeZone
    {
        return new \DateTimeZone(self::BUSINESS_TIMEZONE);
    }

    /**
     * Bucket a UTC instant into its business-timezone ISO-8601 week.
     *
     * This is the primary entry point: every producer of a sale timestamp
     * hands its UTC instant here and gets back the week it belongs to once
     * converted to America/Chicago wall-clock time.
     *
     * Callers are contractually required to pass a `$utc` whose timezone is
     * UTC (e.g. read off a connection pinned to `SET SESSION time_zone =
     * '+00:00'`). As a guard against a mislabelled instant slipping through,
     * this method checks the instant's actual UTC offset and converts if it
     * is non-zero — it does not simply trust the timezone name — but this is
     * a safety net, not a licence to pass non-UTC values.
     *
     * @param \DateTimeImmutable $utc The sale's recognition instant; must represent UTC.
     *
     * @return array{week_start: string, iso_week: string} `week_start` is the
     *         `'YYYY-MM-DD'` of the Monday that starts the week in
     *         America/Chicago; `iso_week` is the `'YYYY-Www'` key for the
     *         same week.
     *
     * @example
     *   // 2026-07-19 23:30 America/Chicago is a Sunday, 2026-07-20 04:30 UTC.
     *   WeekBucket::fromUtc(new \DateTimeImmutable('2026-07-20 04:30:00', new \DateTimeZone('UTC')));
     *   // ['week_start' => '2026-07-13', 'iso_week' => '2026-W29']
     *
     * @see self::fromDbTimestamp() Convenience wrapper for a raw MySQL string.
     */
    public static function fromUtc(\DateTimeImmutable $utc): array
    {
        // Guard: trust the instant's actual offset, not the timezone's name,
        // since callers are required to pass UTC but a mislabelled value
        // (e.g. tagged '+00:00' but really a local offset) must not silently
        // land in the wrong week.
        if ($utc->getOffset() !== 0) {
            $utc = $utc->setTimezone(new \DateTimeZone('UTC'));
        }

        $businessLocal = $utc->setTimezone(self::businessZone());
        $weekStart     = self::weekStartOf($businessLocal);

        return [
            'week_start' => $weekStart->format('Y-m-d'),
            'iso_week'   => self::isoWeekKey($businessLocal),
        ];
    }

    /**
     * Parse a MySQL `'Y-m-d H:i:s'` timestamp known to be UTC and bucket it
     * into its business-timezone week.
     *
     * Exists so the report script never has to construct the
     * `\DateTimeImmutable` itself — it just hands over the raw string that
     * came back from a connection pinned to `SET SESSION time_zone =
     * '+00:00'`.
     *
     * @param string $utcTimestamp A MySQL `DATETIME`/`TIMESTAMP` string in
     *        `'Y-m-d H:i:s'` format, representing UTC.
     *
     * @return array{week_start: string, iso_week: string} same shape as
     *         {@see self::fromUtc()}.
     *
     * @throws \InvalidArgumentException if `$utcTimestamp` cannot be parsed
     *         as `'Y-m-d H:i:s'`, naming the offending value.
     *
     * @example
     *   // 2026-07-24 01:13:38 UTC is 2026-07-23 20:13:38 in Chicago.
     *   WeekBucket::fromDbTimestamp('2026-07-24 01:13:38');
     *   // ['week_start' => '2026-07-20', 'iso_week' => '2026-W30']
     *
     * @see self::fromUtc() The method this delegates to.
     */
    public static function fromDbTimestamp(string $utcTimestamp): array
    {
        $utc = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $utcTimestamp,
            new \DateTimeZone('UTC')
        );

        if ($utc === false) {
            throw new \InvalidArgumentException(
                sprintf('Unparseable UTC timestamp: "%s"', $utcTimestamp)
            );
        }

        return self::fromUtc($utc);
    }

    /**
     * The Monday 00:00:00 that starts the ISO week containing a
     * business-local instant.
     *
     * @param \DateTimeImmutable $businessLocal A `\DateTimeImmutable` already
     *        expressed in the business timezone (typically the result of
     *        converting a UTC instant, as {@see self::fromUtc()} does
     *        internally).
     *
     * @return \DateTimeImmutable The Monday 00:00:00 of that week, in the
     *         same timezone as `$businessLocal`.
     *
     * @example
     *   WeekBucket::weekStartOf(new \DateTimeImmutable('2026-07-19 23:30:00', WeekBucket::businessZone()));
     *   // DateTimeImmutable for 2026-07-13 00:00:00 America/Chicago
     *
     * @see self::isoWeekKey() The companion `'YYYY-Www'` label for the same week.
     */
    public static function weekStartOf(\DateTimeImmutable $businessLocal): \DateTimeImmutable
    {
        // ISO day-of-week: 1 = Monday .. 7 = Sunday.
        $daysSinceMonday = ((int) $businessLocal->format('N')) - 1;

        $monday = $daysSinceMonday > 0
            ? $businessLocal->sub(new \DateInterval(sprintf('P%dD', $daysSinceMonday)))
            : $businessLocal;

        return $monday->setTime(0, 0, 0);
    }

    /**
     * The `'YYYY-Www'` ISO-8601 week key for a business-local instant.
     *
     * Built from PHP's `o` (ISO-8601 year) and `W` (ISO-8601 week number)
     * format specifiers — deliberately never `Y` (calendar year). Near a
     * year boundary the ISO year can differ from the calendar year: e.g.
     * 2027-01-01 falls in ISO week 53 of ISO year 2026 (`2026-W53`), not
     * `2027-W53`. Using `Y` there would silently produce the wrong key and
     * scatter that week's sales across two labels in the report.
     *
     * @param \DateTimeImmutable $businessLocal A `\DateTimeImmutable` already
     *        expressed in the business timezone.
     *
     * @return string The zero-padded `'YYYY-Www'` key, e.g. `'2026-W07'`.
     *
     * @example
     *   WeekBucket::isoWeekKey(new \DateTimeImmutable('2027-01-01', WeekBucket::businessZone()));
     *   // '2026-W53'
     */
    public static function isoWeekKey(\DateTimeImmutable $businessLocal): string
    {
        return sprintf('%s-W%s', $businessLocal->format('o'), $businessLocal->format('W'));
    }

    /**
     * Every consecutive week from `$firstWeekStart` to `$lastWeekStart`
     * inclusive, with no gaps.
     *
     * This is what lets the report show zero-sale weeks as explicit zero
     * rows instead of silently omitting them: the report iterates this list
     * rather than the distinct weeks actually present in the sales data.
     *
     * Advances one Monday at a time by adding `\DateInterval('P7D')` to a
     * Monday 00:00 in the business timezone — never by adding 604800
     * seconds, because a fixed-seconds jump crosses a US DST transition
     * incorrectly (it would land on 23:00 or 01:00 the following Monday
     * instead of 00:00, potentially skipping a week or double-counting one).
     *
     * @param string $firstWeekStart The first week's Monday, as `'YYYY-MM-DD'`.
     * @param string $lastWeekStart  The last week's Monday (inclusive), as `'YYYY-MM-DD'`.
     *
     * @return list<array{week_start: string, iso_week: string}> one entry per
     *         week, in ascending order, same shape as {@see self::fromUtc()}.
     *
     * @throws \InvalidArgumentException if either argument is not a real
     *         calendar date on a Monday, or if `$lastWeekStart` is before
     *         `$firstWeekStart`.
     *
     * @example
     *   WeekBucket::range('2026-07-13', '2026-07-13');
     *   // [['week_start' => '2026-07-13', 'iso_week' => '2026-W29']]
     *
     * @see self::compareWeekStart() Used to validate ordering.
     */
    public static function range(string $firstWeekStart, string $lastWeekStart): array
    {
        $first = self::parseMonday($firstWeekStart);
        $last  = self::parseMonday($lastWeekStart);

        if (self::compareWeekStart($lastWeekStart, $firstWeekStart) < 0) {
            throw new \InvalidArgumentException(
                sprintf('Last week start "%s" is before first week start "%s".', $lastWeekStart, $firstWeekStart)
            );
        }

        $weeks   = [];
        $current = $first;
        $oneWeek = new \DateInterval('P7D');

        while (self::compareWeekStart($current->format('Y-m-d'), $last->format('Y-m-d')) <= 0) {
            $weeks[] = [
                'week_start' => $current->format('Y-m-d'),
                'iso_week'   => self::isoWeekKey($current),
            ];
            $current = $current->add($oneWeek);
        }

        return $weeks;
    }

    /**
     * Compare two `week_start` strings for sorting or ordering checks.
     *
     * Plain string comparison already sorts `'YYYY-MM-DD'` values correctly,
     * but this wraps it so that intent is explicit at call sites and every
     * week-start comparison in the report goes through one place.
     *
     * @param string $a A `'YYYY-MM-DD'` week-start string.
     * @param string $b A `'YYYY-MM-DD'` week-start string.
     *
     * @return int A negative number if `$a` is earlier than `$b`, zero if
     *         equal, a positive number if `$a` is later than `$b`.
     *
     * @example
     *   WeekBucket::compareWeekStart('2026-05-25', '2026-07-13');
     *   // -1
     */
    public static function compareWeekStart(string $a, string $b): int
    {
        return $a <=> $b;
    }

    /**
     * Parse a `'YYYY-MM-DD'` string in the business timezone, verifying it
     * is both a real calendar date and a Monday.
     *
     * Internal helper for {@see self::range()}; re-formatting the parsed
     * date and comparing it back to the input catches invalid dates that
     * `\DateTimeImmutable::createFromFormat()` would otherwise silently roll
     * over (e.g. `'2026-02-30'` becoming March 2nd).
     *
     * @param string $weekStart A candidate `'YYYY-MM-DD'` week-start string.
     *
     * @return \DateTimeImmutable The Monday 00:00:00 instant in the business timezone.
     *
     * @throws \InvalidArgumentException if `$weekStart` is not a real date
     *         in `'Y-m-d'` format, or is not a Monday, naming the offending
     *         value.
     */
    private static function parseMonday(string $weekStart): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart, self::businessZone());

        if ($parsed === false || $parsed->format('Y-m-d') !== $weekStart) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a valid "Y-m-d" date.', $weekStart)
            );
        }

        if ($parsed->format('N') !== '1') {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a Monday.', $weekStart)
            );
        }

        return $parsed;
    }
}
