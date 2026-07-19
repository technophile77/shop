<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Closures;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure store-closure logic in App\Support\Closures.
 *
 * Covers the lexicographic isClosed()/covering() hot path, the date-math
 * datesInRange()/closedDatesBetween() pair, overlaps()'s touching-vs-adjacent
 * boundary, admin-facing validateRange(), locale-injected
 * formatRange()/formatList(), and the customer-facing rejectionMessage()
 * (using fixture templates, never real English/Spanish copy, since the real
 * strings live in the lang files).
 *
 * @see \App\Support\Closures
 */
final class ClosuresTest extends TestCase
{
    protected function setUp(): void
    {
        // tests/bootstrap.php does not set a timezone; the timezone-boundary
        // tests below depend on America/Chicago being active.
        date_default_timezone_set('America/Chicago');
    }

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    /** Build a raw store_closures row for test fixtures. */
    private function c(string $s, string $e, ?string $r = null): array
    {
        return ['id' => 1, 'start_date' => $s, 'end_date' => $e, 'reason' => $r];
    }

    private function enMonths(): array
    {
        return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    }

    private function esMonths(): array
    {
        return ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    }

    // -------------------------------------------------------------------------
    // isClosed()
    // -------------------------------------------------------------------------

    public function testIsClosedInclusiveStart(): void
    {
        self::assertTrue(Closures::isClosed('2026-07-04', [$this->c('2026-07-04', '2026-07-08')]));
    }

    public function testIsClosedInclusiveEnd(): void
    {
        self::assertTrue(Closures::isClosed('2026-07-08', [$this->c('2026-07-04', '2026-07-08')]));
    }

    public function testIsClosedDayBeforeIsFalse(): void
    {
        self::assertFalse(Closures::isClosed('2026-07-03', [$this->c('2026-07-04', '2026-07-08')]));
    }

    public function testIsClosedDayAfterIsFalse(): void
    {
        self::assertFalse(Closures::isClosed('2026-07-09', [$this->c('2026-07-04', '2026-07-08')]));
    }

    public function testIsClosedSingleDayClosure(): void
    {
        self::assertTrue(Closures::isClosed('2026-07-04', [$this->c('2026-07-04', '2026-07-04')]));
    }

    public function testIsClosedAcrossMonthBoundary(): void
    {
        $closures = [$this->c('2026-06-28', '2026-07-03')];
        self::assertTrue(Closures::isClosed('2026-06-30', $closures));
        self::assertTrue(Closures::isClosed('2026-07-01', $closures));
        self::assertFalse(Closures::isClosed('2026-06-27', $closures));
        self::assertFalse(Closures::isClosed('2026-07-04', $closures));
    }

    public function testIsClosedAcrossYearBoundary(): void
    {
        $closures = [$this->c('2026-12-30', '2027-01-02')];
        self::assertTrue(Closures::isClosed('2027-01-01', $closures));
    }

    public function testIsClosedOnLeapDay(): void
    {
        $closures = [$this->c('2028-02-27', '2028-03-01')];
        self::assertTrue(Closures::isClosed('2028-02-29', $closures));
    }

    public function testIsClosedEmptyClosureList(): void
    {
        self::assertFalse(Closures::isClosed('2026-07-04', []));
    }

    public function testIsClosedMalformedDatesReturnFalseWithoutThrowing(): void
    {
        $closures = [$this->c('2026-07-04', '2026-07-08')];
        self::assertFalse(Closures::isClosed('', $closures));
        self::assertFalse(Closures::isClosed('nope', $closures));
        self::assertFalse(Closures::isClosed('2026-13-99', $closures));
    }

    // -------------------------------------------------------------------------
    // datesInRange()
    // -------------------------------------------------------------------------

    public function testDatesInRangeSingleDay(): void
    {
        self::assertSame(['2026-07-04'], Closures::datesInRange('2026-07-04', '2026-07-04'));
    }

    public function testDatesInRangeFiveDaySpan(): void
    {
        $dates = Closures::datesInRange('2026-07-04', '2026-07-08');
        self::assertCount(5, $dates);
        self::assertSame(
            ['2026-07-04', '2026-07-05', '2026-07-06', '2026-07-07', '2026-07-08'],
            $dates
        );
    }

    public function testDatesInRangeAcrossMonthBoundary(): void
    {
        $dates = Closures::datesInRange('2026-06-28', '2026-07-03');
        self::assertCount(6, $dates);
        self::assertSame('2026-06-28', $dates[0]);
        self::assertSame('2026-07-03', $dates[count($dates) - 1]);
    }

    public function testDatesInRangeContainsLeapDay(): void
    {
        $dates = Closures::datesInRange('2028-02-26', '2028-02-29');
        self::assertCount(4, $dates);
        self::assertContains('2028-02-29', $dates);
    }

    public function testDatesInRangeInvertedReturnsEmpty(): void
    {
        self::assertSame([], Closures::datesInRange('2026-07-08', '2026-07-04'));
    }

    public function testDatesInRangeExceedingMaxSpanReturnsEmpty(): void
    {
        // 2025-01-01 to 2026-01-02 is a 367-day inclusive span (MAX_SPAN_DAYS is 366).
        self::assertSame([], Closures::datesInRange('2025-01-01', '2026-01-02'));
    }

    // -------------------------------------------------------------------------
    // closedDatesBetween()
    // -------------------------------------------------------------------------

    public function testClosedDatesBetweenWindowInsideClosure(): void
    {
        $closures = [$this->c('2026-07-01', '2026-07-10')];
        self::assertSame(
            ['2026-07-03', '2026-07-04', '2026-07-05'],
            Closures::closedDatesBetween('2026-07-03', '2026-07-05', $closures)
        );
    }

    public function testClosedDatesBetweenPartialOverlap(): void
    {
        $closures = [$this->c('2026-07-08', '2026-07-15')];
        self::assertSame(
            ['2026-07-08', '2026-07-09', '2026-07-10'],
            Closures::closedDatesBetween('2026-07-05', '2026-07-10', $closures)
        );
    }

    public function testClosedDatesBetweenTwoOverlappingClosuresDeduplicate(): void
    {
        $closures = [
            $this->c('2026-07-04', '2026-07-08'),
            $this->c('2026-07-06', '2026-07-10'),
        ];
        $closed = Closures::closedDatesBetween('2026-07-01', '2026-07-12', $closures);

        $expected = ['2026-07-04', '2026-07-05', '2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10'];
        self::assertSame($expected, $closed);
        self::assertSame(count($expected), count(array_unique($closed)));
    }

    public function testClosedDatesBetweenNoClosuresReturnsEmpty(): void
    {
        self::assertSame([], Closures::closedDatesBetween('2026-07-01', '2026-07-10', []));
    }

    public function testClosedDatesBetweenFromAfterToReturnsEmpty(): void
    {
        $closures = [$this->c('2026-07-01', '2026-07-10')];
        self::assertSame([], Closures::closedDatesBetween('2026-07-10', '2026-07-01', $closures));
    }

    // -------------------------------------------------------------------------
    // overlaps()
    // -------------------------------------------------------------------------

    public function testOverlapsTouchingEndpointsIsTrue(): void
    {
        self::assertTrue(Closures::overlaps('2026-01-01', '2026-01-05', '2026-01-05', '2026-01-09'));
    }

    public function testOverlapsAdjacentIsFalse(): void
    {
        self::assertFalse(Closures::overlaps('2026-01-01', '2026-01-04', '2026-01-05', '2026-01-09'));
    }

    public function testOverlapsContainmentBothDirections(): void
    {
        self::assertTrue(Closures::overlaps('2026-01-01', '2026-01-10', '2026-01-03', '2026-01-05'));
        self::assertTrue(Closures::overlaps('2026-01-03', '2026-01-05', '2026-01-01', '2026-01-10'));
    }

    public function testOverlapsIdenticalRangesIsTrue(): void
    {
        self::assertTrue(Closures::overlaps('2026-01-01', '2026-01-05', '2026-01-01', '2026-01-05'));
    }

    public function testOverlapsDisjointRangesIsFalse(): void
    {
        self::assertFalse(Closures::overlaps('2026-01-01', '2026-01-05', '2026-02-01', '2026-02-05'));
    }

    // -------------------------------------------------------------------------
    // validateRange()
    // -------------------------------------------------------------------------

    public function testValidateRangeValidReturnsEmpty(): void
    {
        self::assertSame([], Closures::validateRange('2026-07-04', '2026-07-08', [], $this->enMonths()));
    }

    public function testValidateRangeEmptyDates(): void
    {
        self::assertSame(
            ['Please choose a start date and an end date.'],
            Closures::validateRange('', '', [], $this->enMonths())
        );
    }

    public function testValidateRangeUnparseableDates(): void
    {
        self::assertSame(
            ['Please choose valid dates.'],
            Closures::validateRange('nope', '2026-07-08', [], $this->enMonths())
        );
    }

    public function testValidateRangeInverted(): void
    {
        self::assertSame(
            ['The end date must be on or after the start date.'],
            Closures::validateRange('2026-07-08', '2026-07-04', [], $this->enMonths())
        );
    }

    public function testValidateRangeTooLong(): void
    {
        self::assertSame(
            ['A closure cannot be longer than 366 days.'],
            Closures::validateRange('2025-01-01', '2026-01-02', [], $this->enMonths())
        );
    }

    public function testValidateRangeOverlappingExistingClosure(): void
    {
        $existing = [$this->c('2026-07-04', '2026-07-08')];
        $errors   = Closures::validateRange('2026-07-06', '2026-07-10', $existing, $this->enMonths());

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Jul 4 – Jul 8, 2026', implode(' ', $errors));
    }

    public function testValidateRangeAdjacentButNotOverlappingIsValid(): void
    {
        $existing = [$this->c('2026-07-01', '2026-07-04')];
        self::assertSame(
            [],
            Closures::validateRange('2026-07-05', '2026-07-09', $existing, $this->enMonths())
        );
    }

    // -------------------------------------------------------------------------
    // formatRange() — proves the class owns no locale of its own
    // -------------------------------------------------------------------------

    public function testFormatRangeSingleDay(): void
    {
        $closure = $this->c('2026-07-04', '2026-07-04');
        self::assertSame('Jul 4, 2026', Closures::formatRange($closure, $this->enMonths()));
    }

    public function testFormatRangeMultiDaySameYear(): void
    {
        $closure = $this->c('2026-07-04', '2026-07-08');
        self::assertSame('Jul 4 – Jul 8, 2026', Closures::formatRange($closure, $this->enMonths()));
    }

    public function testFormatRangeCrossYear(): void
    {
        $closure = $this->c('2026-12-30', '2027-01-02');
        self::assertSame('Dec 30, 2026 – Jan 2, 2027', Closures::formatRange($closure, $this->enMonths()));
    }

    public function testFormatRangeUsesInjectedMonthsForEnglish(): void
    {
        $closure = $this->c('2027-01-04', '2027-01-04');
        self::assertSame('Jan 4, 2027', Closures::formatRange($closure, $this->enMonths()));
    }

    public function testFormatRangeUsesInjectedMonthsForSpanish(): void
    {
        $closure = $this->c('2027-01-04', '2027-01-04');
        self::assertSame('Ene 4, 2027', Closures::formatRange($closure, $this->esMonths()));
    }

    // -------------------------------------------------------------------------
    // rejectionMessage() — uses fixture templates, never real EN/ES copy
    // -------------------------------------------------------------------------

    private function fixtureStrings(): array
    {
        return [
            'rejected'        => 'CLOSED:%s',
            'rejected_reason' => 'CLOSEDREASON:%s:%s',
            'upcoming'        => 'LIST:%s',
            'choose_another'  => 'PICKANOTHER',
        ];
    }

    public function testRejectionMessageUsesReasonVariantWhenReasonPresent(): void
    {
        $closures = [$this->c('2026-07-04', '2026-07-08', 'Holiday')];
        $message  = Closures::rejectionMessage('2026-07-05', $closures, $this->fixtureStrings(), $this->enMonths());

        self::assertSame(
            'CLOSEDREASON:2026-07-05:Holiday LIST:Jul 4 – Jul 8, 2026 PICKANOTHER',
            $message
        );
    }

    public function testRejectionMessageUsesPlainVariantWhenReasonIsNull(): void
    {
        $closures = [$this->c('2026-07-04', '2026-07-08', null)];
        $message  = Closures::rejectionMessage('2026-07-05', $closures, $this->fixtureStrings(), $this->enMonths());

        self::assertSame(
            'CLOSED:2026-07-05 LIST:Jul 4 – Jul 8, 2026 PICKANOTHER',
            $message
        );
    }

    // -------------------------------------------------------------------------
    // adminWarning()
    // -------------------------------------------------------------------------

    public function testAdminWarningEmptyWhenDateIsOpen(): void
    {
        self::assertSame('', Closures::adminWarning('2026-07-01', [$this->c('2026-07-04', '2026-07-08')], $this->enMonths()));
    }

    public function testAdminWarningIncludesReasonWhenPresent(): void
    {
        $closures = [$this->c('2026-07-04', '2026-07-08', 'Independence Day week')];
        self::assertSame(
            'Heads up: the event date (Jul 5, 2026) falls inside a store closure (Jul 4 – Jul 8, 2026 — Independence Day week).',
            Closures::adminWarning('2026-07-05', $closures, $this->enMonths())
        );
    }

    public function testAdminWarningOmitsReasonSuffixWhenReasonIsNull(): void
    {
        $closures = [$this->c('2026-07-04', '2026-07-08', null)];
        self::assertSame(
            'Heads up: the event date (Jul 5, 2026) falls inside a store closure (Jul 4 – Jul 8, 2026).',
            Closures::adminWarning('2026-07-05', $closures, $this->enMonths())
        );
    }

    // -------------------------------------------------------------------------
    // Timezone boundary (America/Chicago, set in setUp())
    // -------------------------------------------------------------------------

    public function testTimezoneBoundaryLateEvening(): void
    {
        // Under UTC this moment would format as 2026-07-05 and the assertion
        // below would fail; under America/Chicago it stays 2026-07-04.
        $now   = new DateTimeImmutable('2026-07-04 23:30');
        $today = $now->format('Y-m-d');

        self::assertTrue(Closures::isClosed($today, [$this->c('2026-07-04', '2026-07-04')]));
    }

    public function testTimezoneBoundaryEarlyMorning(): void
    {
        $now   = new DateTimeImmutable('2026-07-04 00:15');
        $today = $now->format('Y-m-d');

        self::assertTrue(Closures::isClosed($today, [$this->c('2026-07-04', '2026-07-04')]));
    }
}
