<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Pure, side-effect-free scheduling logic for the checkout fulfillment picker.
 *
 * Decides whether same-day delivery/pickup is still available given a same-day
 * cutoff time, what the earliest selectable date is, and whether a customer's
 * chosen type + date + time is valid (parseable, not in the past, and same-day
 * only before the cutoff). Nothing here reads the clock, environment, session,
 * or database — the caller passes in "now" and the cutoff — which keeps the
 * boundary behaviour deterministically unit-testable.
 *
 * The cutoff is a 24-hour "H:i" string (e.g. '13:00'), interpreted in whatever
 * timezone the supplied DateTimeImmutable carries (the app sets
 * America/Chicago in config/app.php).
 *
 * @see \App\Controllers\CheckoutController  Consumes validate()/earliestDate().
 */
final class Fulfillment
{
    /** Recognised fulfillment types. */
    public const TYPES = ['delivery', 'pickup'];

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Whether same-day fulfillment is still allowed at the given moment.
     *
     * True only when "now" is strictly before the cutoff time on its own date.
     * A malformed cutoff is treated conservatively as "not allowed".
     *
     * @param DateTimeImmutable $now    The current moment.
     * @param string            $cutoff Same-day cutoff as 'H:i' (e.g. '13:00').
     *
     * @return bool True when same-day is still open.
     *
     * @example
     *   Fulfillment::isSameDayAllowed(new DateTimeImmutable('2026-06-17 11:00'), '13:00'); // true
     *   Fulfillment::isSameDayAllowed(new DateTimeImmutable('2026-06-17 13:00'), '13:00'); // false
     */
    public static function isSameDayAllowed(DateTimeImmutable $now, string $cutoff): bool
    {
        $cut = self::cutoffOn($now, $cutoff);
        return $cut !== null && $now < $cut;
    }

    /**
     * The earliest date a customer may select, as 'Y-m-d'.
     *
     * Today when same-day is still allowed, otherwise tomorrow. Used as the
     * `min` attribute of the date input so past/closed dates aren't selectable.
     *
     * @param DateTimeImmutable $now    The current moment.
     * @param string            $cutoff Same-day cutoff as 'H:i'.
     *
     * @return string The earliest selectable date, 'Y-m-d'.
     *
     * @example
     *   Fulfillment::earliestDate(new DateTimeImmutable('2026-06-17 09:00'), '13:00'); // '2026-06-17'
     *   Fulfillment::earliestDate(new DateTimeImmutable('2026-06-17 18:00'), '13:00'); // '2026-06-18'
     */
    public static function earliestDate(DateTimeImmutable $now, string $cutoff): string
    {
        return self::isSameDayAllowed($now, $cutoff)
            ? $now->format('Y-m-d')
            : $now->modify('+1 day')->format('Y-m-d');
    }

    /**
     * Parse a date + time pair into a DateTimeImmutable, strictly.
     *
     * Accepts a 'Y-m-d' date and an 'H:i' time (the formats emitted by the
     * native <input type="date"> and <input type="time"> controls). Returns
     * null for anything that does not parse cleanly, so callers never persist a
     * silently-coerced value.
     *
     * @param string $date Date as 'Y-m-d'.
     * @param string $time Time as 'H:i'.
     *
     * @return DateTimeImmutable|null The combined moment, or null when malformed.
     *
     * @example
     *   Fulfillment::parse('2026-06-20', '14:30'); // DateTimeImmutable @ 2026-06-20 14:30:00
     *   Fulfillment::parse('2026-13-99', '14:30'); // null
     */
    public static function parse(string $date, string $time): ?DateTimeImmutable
    {
        $raw = trim($date) . ' ' . trim($time);
        $dt  = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $raw);
        $err = DateTimeImmutable::getLastErrors();

        if ($dt === false) {
            return null;
        }
        if (is_array($err) && (($err['warning_count'] ?? 0) > 0 || ($err['error_count'] ?? 0) > 0)) {
            return null;
        }

        return $dt;
    }

    /**
     * Validate a customer's chosen fulfillment type, date, and time.
     *
     * Returns a list of human-readable English problems (empty when valid):
     *   - type must be 'delivery' or 'pickup';
     *   - date + time must parse;
     *   - the chosen moment must not be in the past;
     *   - a same-day choice is only allowed before the cutoff.
     *
     * @param string            $type   Fulfillment type ('delivery'|'pickup').
     * @param string            $date   Chosen date, 'Y-m-d'.
     * @param string            $time   Chosen time, 'H:i'.
     * @param DateTimeImmutable $now    The current moment.
     * @param string            $cutoff Same-day cutoff as 'H:i'.
     *
     * @return list<string> Problems found; empty when the selection is valid.
     *
     * @example
     *   Fulfillment::validate('pickup', '2026-06-20', '14:00',
     *       new DateTimeImmutable('2026-06-17 09:00'), '13:00'); // []
     */
    public static function validate(
        string $type,
        string $date,
        string $time,
        DateTimeImmutable $now,
        string $cutoff
    ): array {
        $errors = [];

        if (!in_array($type, self::TYPES, true)) {
            $errors[] = 'Please choose delivery or pickup.';
        }

        $requested = self::parse($date, $time);
        if ($requested === null) {
            $errors[] = 'Please choose a valid date and time.';
            return $errors; // Nothing more we can check without a parseable moment.
        }

        if ($requested < $now) {
            $errors[] = 'Please choose a date and time in the future.';
        }

        if ($requested->format('Y-m-d') === $now->format('Y-m-d') && !self::isSameDayAllowed($now, $cutoff)) {
            $errors[] = 'Same-day orders must be placed before ' . $cutoff . '. Please choose a later date.';
        }

        return $errors;
    }

    /**
     * Build a DateTimeImmutable at the cutoff time on a reference date.
     *
     * @param DateTimeImmutable $ref    Reference moment (its date is used).
     * @param string            $cutoff Cutoff as 'H:i'.
     *
     * @return DateTimeImmutable|null The cutoff moment, or null for a malformed cutoff.
     */
    private static function cutoffOn(DateTimeImmutable $ref, string $cutoff): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($cutoff), $m) !== 1) {
            return null;
        }
        $hour   = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $ref->setTime($hour, $minute, 0);
    }
}
