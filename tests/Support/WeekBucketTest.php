<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\WeekBucket;
use PHPUnit\Framework\TestCase;

/**
 * Unit and property tests for WeekBucket's UTC-to-business-week conversion.
 *
 * Covers the DST-summer skew that motivates the class (a Sunday-night sale
 * in Chicago landing in a different ISO week than a Monday-just-after-midnight
 * sale one hour later in UTC), the ISO-year-boundary bug that a naive `Y`-based
 * key would produce, `weekStartOf()` idempotence, `range()`'s gap-free
 * enumeration (including across a US DST transition), and `fromDbTimestamp()`
 * parsing plus its failure path. A stochastic test asserts the general laws
 * hold over ~300 random UTC instants spanning several years.
 *
 * @see \App\Support\WeekBucket
 */
class WeekBucketTest extends TestCase
{
    /**
     * The DST-summer skew this class exists to prevent: two UTC instants one
     * hour apart must land in different ISO weeks when converted to Chicago,
     * because the boundary between them is Sunday night / Monday morning.
     *
     * 2026-07-19 23:30 America/Chicago (Sunday) is 2026-07-20 04:30 UTC.
     * 2026-07-20 00:30 America/Chicago (Monday) is 2026-07-20 05:30 UTC.
     * Both UTC instants fall on the same UTC calendar day, 30 minutes apart —
     * a naive reading in US Eastern (which is always exactly one hour ahead
     * of Central, DST or not) would put the Eastern-local reading of the
     * first instant at 2026-07-20 00:30 Eastern, i.e. it would look like the
     * *same* Monday-morning moment as the second instant read locally,
     * conflating two different ISO weeks. Converting correctly to
     * America/Chicago instead keeps them apart: the first is the tail end of
     * the week starting 2026-07-13, the second is the start of the week
     * beginning 2026-07-20.
     */
    public function testDstSummerSkewSplitsAcrossWeeks(): void
    {
        $sundayNight = new \DateTimeImmutable('2026-07-20 04:30:00', new \DateTimeZone('UTC'));
        $mondayMorning = new \DateTimeImmutable('2026-07-20 05:30:00', new \DateTimeZone('UTC'));

        $sundayResult = WeekBucket::fromUtc($sundayNight);
        $mondayResult = WeekBucket::fromUtc($mondayMorning);

        // 2026-07-13 is a Monday (verified: (new DateTimeImmutable('2026-07-13'))->format('N') === '1').
        $this->assertSame('2026-07-13', $sundayResult['week_start']);
        $this->assertSame('2026-W29', $sundayResult['iso_week']);

        // 2026-07-20 is the following Monday.
        $this->assertSame('2026-07-20', $mondayResult['week_start']);
        $this->assertSame('2026-W30', $mondayResult['iso_week']);

        $this->assertNotSame($sundayResult['week_start'], $mondayResult['week_start']);
    }

    /**
     * The project's first sale, 2026-05-30 20:05:37 Chicago time (a Saturday),
     * must bucket into the week starting the preceding Monday, 2026-05-25.
     */
    public function testFirstSaleDate(): void
    {
        // 2026-05-30 20:05:37 America/Chicago = 2026-05-31 01:05:37 UTC (CDT is UTC-5 in May).
        $utc = new \DateTimeImmutable('2026-05-31 01:05:37', new \DateTimeZone('UTC'));

        $result = WeekBucket::fromUtc($utc);

        // 2026-05-25 is a Monday (verified: (new DateTimeImmutable('2026-05-25'))->format('N') === '1'),
        // and it precedes Saturday 2026-05-30 within the same week.
        $this->assertSame('2026-05-25', $result['week_start']);
        $this->assertSame('2026-W22', $result['iso_week']);
    }

    /**
     * At an ISO year boundary the ISO year can differ from the calendar
     * year; the key must use the ISO year (`o`), not the calendar year (`Y`).
     *
     * 2027-01-01 is a Friday. Its ISO week is week 53 of ISO year 2026 (the
     * week starting Monday 2026-12-28 runs 2026-12-28 through 2027-01-03),
     * so the correct key is '2026-W53'. A `Y`-based implementation would
     * instead produce '2027-W53', which is not a real ISO week — 2027's
     * ISO weeks start with 2027-W01 on 2027-01-04.
     */
    public function testIsoYearBoundaryUsesIsoYearNotCalendarYear(): void
    {
        // 2027-01-01 00:30 Chicago = 2027-01-01 06:30 UTC (CST is UTC-6 in January).
        $utc = new \DateTimeImmutable('2027-01-01 06:30:00', new \DateTimeZone('UTC'));

        $result = WeekBucket::fromUtc($utc);

        $this->assertSame('2026-12-28', $result['week_start']);
        $this->assertSame('2026-W53', $result['iso_week']); // NOT '2027-W53'
    }

    /**
     * A Monday input to weekStartOf() returns the same date at midnight
     * (idempotence): the week start of a Monday is itself.
     */
    public function testWeekStartOfIsIdempotentForMonday(): void
    {
        $monday = new \DateTimeImmutable('2026-07-20 15:45:00', WeekBucket::businessZone());

        $result = WeekBucket::weekStartOf($monday);

        $this->assertSame('2026-07-20 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    /**
     * A Sunday input to weekStartOf() rolls back to the preceding Monday.
     */
    public function testWeekStartOfRollsSundayBackToMonday(): void
    {
        $sunday = new \DateTimeImmutable('2026-07-19 23:30:00', WeekBucket::businessZone());

        $result = WeekBucket::weekStartOf($sunday);

        $this->assertSame('2026-07-13 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    /**
     * range() from 2026-05-25 to 2026-07-20 must return every Monday in
     * between with no gaps, and the exact count.
     */
    public function testRangeReturnsConsecutiveWeeksWithExactCount(): void
    {
        $weeks = WeekBucket::range('2026-05-25', '2026-07-20');

        // 2026-05-25 to 2026-07-20 is 56 days apart (verified via DateTimeImmutable::diff()),
        // 56 / 7 = 8 full weeks plus the starting week itself = 9 weeks.
        $this->assertCount(9, $weeks);
        $this->assertSame('2026-05-25', $weeks[0]['week_start']);
        $this->assertSame('2026-07-20', $weeks[8]['week_start']);

        // No gaps: every consecutive pair is exactly 7 calendar days apart.
        for ($i = 1; $i < count($weeks); $i++) {
            $prev = new \DateTimeImmutable($weeks[$i - 1]['week_start']);
            $curr = new \DateTimeImmutable($weeks[$i]['week_start']);
            $this->assertSame(7, $prev->diff($curr)->days);
        }
    }

    /**
     * range() with identical first and last arguments returns exactly one entry.
     */
    public function testRangeWithSameStartAndEndReturnsOneEntry(): void
    {
        $weeks = WeekBucket::range('2026-07-13', '2026-07-13');

        $this->assertCount(1, $weeks);
        $this->assertSame('2026-07-13', $weeks[0]['week_start']);
        $this->assertSame('2026-W29', $weeks[0]['iso_week']);
    }

    /**
     * range() rejects a first-week-start that is not a Monday.
     */
    public function testRangeThrowsForNonMondayStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // 2026-07-14 is a Tuesday.
        WeekBucket::range('2026-07-14', '2026-07-20');
    }

    /**
     * range() rejects a last-week-start earlier than the first.
     */
    public function testRangeThrowsWhenLastBeforeFirst(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WeekBucket::range('2026-07-20', '2026-07-13');
    }

    /**
     * A range spanning the US DST transition (2026-11-01) must still return
     * only real Mondays — this is the case that would break under a
     * 604800-second-per-week implementation, since adding a fixed number of
     * seconds across a "fall back" transition lands on 23:00 Sunday rather
     * than 00:00 Monday.
     */
    public function testRangeSpanningDstTransitionStaysOnMondays(): void
    {
        $weeks = WeekBucket::range('2026-10-26', '2026-11-16');

        // 2026-10-26, 11-02, 11-09, 11-16 = 4 Mondays; DST ends 2026-11-01.
        $this->assertCount(4, $weeks);

        foreach ($weeks as $week) {
            $date = new \DateTimeImmutable($week['week_start'], WeekBucket::businessZone());
            $this->assertSame('1', $date->format('N'), $week['week_start'] . ' must be a Monday');
        }

        $this->assertSame(
            ['2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16'],
            array_column($weeks, 'week_start')
        );
    }

    /**
     * fromDbTimestamp() parses a raw MySQL UTC string and buckets it
     * correctly once converted to Chicago local time.
     */
    public function testFromDbTimestampConvertsToChicagoWeek(): void
    {
        // 2026-07-24 01:13:38 UTC = 2026-07-23 20:13:38 America/Chicago (CDT, UTC-5).
        $result = WeekBucket::fromDbTimestamp('2026-07-24 01:13:38');

        $this->assertSame('2026-07-20', $result['week_start']);
        $this->assertSame('2026-W30', $result['iso_week']);
    }

    /**
     * fromDbTimestamp() throws on a string that isn't a parseable timestamp.
     */
    public function testFromDbTimestampThrowsOnUnparseableString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WeekBucket::fromDbTimestamp('not-a-date');
    }

    /**
     * Property test: for ~300 random UTC instants spread across several
     * years, the general laws of week bucketing must hold regardless of the
     * specific instant. Seeded deterministically so a failure reproduces.
     *
     * Laws checked:
     *  - week_start always parses as a Monday (format('N') === '1') in the
     *    business timezone.
     *  - The instant, converted to business-local time, falls within
     *    [week_start 00:00, week_start + 7 days) — bounds computed
     *    independently from the returned week_start, not from the bucketing
     *    logic under test.
     *  - iso_week always matches /^\d{4}-W\d{2}$/.
     *  - Two instants that land in the same week produce identical
     *    week_start AND identical iso_week (consistency of the two outputs).
     */
    public function testRandomUtcInstantsObeyBucketingLaws(): void
    {
        mt_srand(20260725);

        $businessZone = WeekBucket::businessZone();
        $utcZone      = new \DateTimeZone('UTC');

        // Random timestamps spanning 2023-01-01 through 2029-12-31.
        $rangeStart = (new \DateTimeImmutable('2023-01-01 00:00:00', $utcZone))->getTimestamp();
        $rangeEnd   = (new \DateTimeImmutable('2029-12-31 23:59:59', $utcZone))->getTimestamp();

        for ($i = 0; $i < 300; $i++) {
            $timestamp = mt_rand($rangeStart, $rangeEnd);
            $utc       = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($utcZone);

            $result = WeekBucket::fromUtc($utc);

            $this->assertMatchesRegularExpression('/^\d{4}-W\d{2}$/', $result['iso_week']);

            $weekStartLocal = new \DateTimeImmutable($result['week_start'] . ' 00:00:00', $businessZone);
            $this->assertSame('1', $weekStartLocal->format('N'));

            $weekEndLocal   = $weekStartLocal->add(new \DateInterval('P7D'));
            $businessLocal  = $utc->setTimezone($businessZone);

            $this->assertGreaterThanOrEqual(
                $weekStartLocal->getTimestamp(),
                $businessLocal->getTimestamp()
            );
            $this->assertLessThan(
                $weekEndLocal->getTimestamp(),
                $businessLocal->getTimestamp()
            );

            // A second instant known to be in the same business week (same
            // Monday-midnight plus a random offset within [0, 6 days]) must
            // produce the identical week_start and iso_week.
            $sameWeekOffsetDays = mt_rand(0, 6);
            $sameWeekLocal      = $weekStartLocal->add(new \DateInterval(sprintf('P%dDT%dH', $sameWeekOffsetDays, mt_rand(0, 23))));
            $sameWeekUtc        = $sameWeekLocal->setTimezone($utcZone);
            $sameWeekResult     = WeekBucket::fromUtc($sameWeekUtc);

            $this->assertSame($result['week_start'], $sameWeekResult['week_start']);
            $this->assertSame($result['iso_week'], $sameWeekResult['iso_week']);
        }
    }
}
