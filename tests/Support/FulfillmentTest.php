<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Fulfillment;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure scheduling logic in App\Support\Fulfillment.
 *
 * "Now" and the cutoff are injected, so the cutoff boundary, past-date
 * rejection, and same-day window are exercised deterministically.
 *
 * @see \App\Support\Fulfillment
 */
final class FulfillmentTest extends TestCase
{
    public function testSameDayAllowedBeforeCutoff(): void
    {
        $now = new DateTimeImmutable('2026-06-17 11:00');
        self::assertTrue(Fulfillment::isSameDayAllowed($now, '13:00'));
    }

    public function testSameDayBlockedAtAndAfterCutoff(): void
    {
        // Exactly at the cutoff is closed (strictly-before semantics).
        self::assertFalse(Fulfillment::isSameDayAllowed(new DateTimeImmutable('2026-06-17 13:00'), '13:00'));
        self::assertFalse(Fulfillment::isSameDayAllowed(new DateTimeImmutable('2026-06-17 17:30'), '13:00'));
    }

    public function testMalformedCutoffIsConservativelyClosed(): void
    {
        self::assertFalse(Fulfillment::isSameDayAllowed(new DateTimeImmutable('2026-06-17 06:00'), 'not-a-time'));
        self::assertFalse(Fulfillment::isSameDayAllowed(new DateTimeImmutable('2026-06-17 06:00'), '99:99'));
    }

    public function testEarliestDateTodayBeforeCutoff(): void
    {
        $now = new DateTimeImmutable('2026-06-17 09:00');
        self::assertSame('2026-06-17', Fulfillment::earliestDate($now, '13:00'));
    }

    public function testEarliestDateTomorrowAfterCutoff(): void
    {
        $now = new DateTimeImmutable('2026-06-17 18:00');
        self::assertSame('2026-06-18', Fulfillment::earliestDate($now, '13:00'));
    }

    public function testEarliestDateRollsOverMonthBoundary(): void
    {
        $now = new DateTimeImmutable('2026-06-30 18:00');
        self::assertSame('2026-07-01', Fulfillment::earliestDate($now, '13:00'));
    }

    public function testParseValidAndInvalid(): void
    {
        $dt = Fulfillment::parse('2026-06-20', '14:30');
        self::assertNotNull($dt);
        self::assertSame('2026-06-20 14:30:00', $dt->format('Y-m-d H:i:s'));

        self::assertNull(Fulfillment::parse('2026-13-99', '14:30')); // impossible date
        self::assertNull(Fulfillment::parse('2026-06-20', '25:61')); // impossible time
        self::assertNull(Fulfillment::parse('', ''));                // empty
        self::assertNull(Fulfillment::parse('06/20/2026', '2:30 PM')); // wrong format
    }

    public function testValidateAcceptsFutureInRange(): void
    {
        $now = new DateTimeImmutable('2026-06-17 09:00');
        self::assertSame([], Fulfillment::validate('delivery', '2026-06-20', '14:00', $now, '13:00'));
        self::assertSame([], Fulfillment::validate('pickup', '2026-06-20', '10:00', $now, '13:00'));
    }

    public function testValidateAcceptsSameDayBeforeCutoff(): void
    {
        $now = new DateTimeImmutable('2026-06-17 09:00');
        // Same calendar day, later time, before cutoff → allowed.
        self::assertSame([], Fulfillment::validate('delivery', '2026-06-17', '15:00', $now, '13:00'));
    }

    public function testValidateRejectsBadType(): void
    {
        $now    = new DateTimeImmutable('2026-06-17 09:00');
        $errors = Fulfillment::validate('teleport', '2026-06-20', '14:00', $now, '13:00');
        self::assertNotEmpty($errors);
        self::assertStringContainsString('delivery or pickup', implode(' ', $errors));
    }

    public function testValidateRejectsUnparseableDateTime(): void
    {
        $now    = new DateTimeImmutable('2026-06-17 09:00');
        $errors = Fulfillment::validate('delivery', 'nope', 'nope', $now, '13:00');
        self::assertNotEmpty($errors);
        self::assertStringContainsString('valid date and time', implode(' ', $errors));
    }

    public function testValidateRejectsPastDate(): void
    {
        $now    = new DateTimeImmutable('2026-06-17 09:00');
        $errors = Fulfillment::validate('delivery', '2026-06-16', '14:00', $now, '13:00');
        self::assertNotEmpty($errors);
        self::assertStringContainsString('future', implode(' ', $errors));
    }

    public function testValidateRejectsPastTimeSameDay(): void
    {
        $now    = new DateTimeImmutable('2026-06-17 09:00');
        // Earlier today is in the past even though the date is today.
        $errors = Fulfillment::validate('delivery', '2026-06-17', '08:00', $now, '13:00');
        self::assertNotEmpty($errors);
        self::assertStringContainsString('future', implode(' ', $errors));
    }

    public function testValidateRejectsSameDayAfterCutoff(): void
    {
        $now    = new DateTimeImmutable('2026-06-17 14:00'); // past the 13:00 cutoff
        $errors = Fulfillment::validate('delivery', '2026-06-17', '17:00', $now, '13:00');
        self::assertNotEmpty($errors);
        self::assertStringContainsString('Same-day', implode(' ', $errors));
    }
}
