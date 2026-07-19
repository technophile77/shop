<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Pure, side-effect-free logic for store-closure date ranges.
 *
 * A "closure" is a raw `store_closures` model row: an assoc array with at
 * least `start_date` and `end_date` ('Y-m-d' strings, both endpoints
 * inclusive) and an optional `reason`. This class never touches the
 * database, the clock, the session, or any translation/config layer — every
 * input (dates, existing closures, "now", locale strings) is supplied by the
 * caller, which keeps every boundary case here deterministically
 * unit-testable and keeps this class reusable from both the admin UI and
 * customer-facing checkout/order-form validation.
 *
 * Two flavours of output are produced:
 *   - Admin-facing methods ({@see validateRange()}, {@see adminWarning()})
 *     return English literals, since the admin UI is English-only.
 *   - Customer-facing output ({@see rejectionMessage()}) never hardcodes
 *     copy; the caller supplies already-translated templates.
 *
 * @see \App\Models\StoreClosure     Data-access layer that supplies the raw rows this class consumes.
 * @see \App\Support\Fulfillment     Sibling pure-logic class for the checkout time picker; same strict-parse idiom.
 * @see \App\Controllers\BaseController::closureStrings()  Builds the $strings/$months maps this class expects.
 */
final class Closures
{
    /** Maximum inclusive span, in days, a single closure may cover. */
    public const MAX_SPAN_DAYS = 366;

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Whether a date falls inside any of the given closures, endpoints inclusive.
     *
     * Implemented as a lexicographic string compare rather than date math: for
     * zero-padded ISO ('Y-m-d') strings, lexicographic order matches
     * chronological order exactly, so this stays cheap on what is expected to
     * be a hot path (checked on every order-form/checkout submission). A
     * malformed or empty date simply fails to lexicographically fall inside
     * any real closure and returns false — it never throws.
     *
     * @param string $date      Date to check, 'Y-m-d'.
     * @param array  $closures  Raw `store_closures` rows to check against.
     *
     * @return bool True when $date falls within any closure's [start_date, end_date].
     *
     * @example
     *   Closures::isClosed('2026-07-04', [['start_date' => '2026-07-04', 'end_date' => '2026-07-08']]); // true
     *   Closures::isClosed('2026-07-03', [['start_date' => '2026-07-04', 'end_date' => '2026-07-08']]); // false
     */
    public static function isClosed(string $date, array $closures): bool
    {
        return self::covering($date, $closures) !== null;
    }

    /**
     * The first closure row that covers a date, or null when the date is open.
     *
     * Used to surface the closure's `reason` to admins/customers once
     * {@see isClosed()} (or an equivalent lexicographic check) has already
     * confirmed the date is closed.
     *
     * @param string $date      Date to check, 'Y-m-d'.
     * @param array  $closures  Raw `store_closures` rows to check against.
     *
     * @return array|null The covering closure row, or null when no closure covers $date.
     *
     * @example
     *   Closures::covering('2026-07-05', [['id' => 1, 'start_date' => '2026-07-04', 'end_date' => '2026-07-08', 'reason' => 'Independence Day week']]);
     *   // ['id' => 1, 'start_date' => '2026-07-04', 'end_date' => '2026-07-08', 'reason' => 'Independence Day week']
     */
    public static function covering(string $date, array $closures): ?array
    {
        if ($date === '') {
            return null;
        }

        foreach ($closures as $closure) {
            $start = (string) ($closure['start_date'] ?? '');
            $end   = (string) ($closure['end_date'] ?? '');

            if ($start !== '' && $end !== '' && $date >= $start && $date <= $end) {
                return $closure;
            }
        }

        return null;
    }

    /**
     * Every calendar date in an inclusive span, as a list of 'Y-m-d' strings.
     *
     * The only method in this class that performs date arithmetic; every
     * other method either delegates here or uses a lexicographic string
     * compare. Uses the same strict-parse idiom as
     * {@see Fulfillment::parse()} (createFromFormat + getLastErrors()) so a
     * date like '2026-13-99' is rejected rather than silently rolled over to
     * a different month.
     *
     * @param string $start Inclusive start date, 'Y-m-d'.
     * @param string $end   Inclusive end date, 'Y-m-d'.
     *
     * @return list<string> Every date from $start to $end inclusive, in order.
     *                       Empty when either date is unparseable, $end is
     *                       before $start, or the span exceeds
     *                       {@see MAX_SPAN_DAYS} (guards against building an
     *                       unbounded list from a typo'd year).
     *
     * @example
     *   Closures::datesInRange('2026-07-04', '2026-07-06'); // ['2026-07-04', '2026-07-05', '2026-07-06']
     *   Closures::datesInRange('2026-07-06', '2026-07-04'); // [] (inverted range)
     */
    public static function datesInRange(string $start, string $end): array
    {
        $startDt = self::strictParse($start);
        $endDt   = self::strictParse($end);

        if ($startDt === null || $endDt === null || $endDt < $startDt) {
            return [];
        }

        $spanDays = $startDt->diff($endDt)->days + 1;
        if ($spanDays > self::MAX_SPAN_DAYS) {
            return [];
        }

        $dates  = [];
        $cursor = $startDt;
        for ($i = 0; $i < $spanDays; $i++) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor  = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /**
     * Every closed day inside an inclusive window, de-duplicated and sorted ascending.
     *
     * Built by walking {@see datesInRange()} and keeping the dates that
     * {@see isClosed()} reports as closed. Because each date in the window
     * appears exactly once in datesInRange()'s output, the result is
     * naturally de-duplicated (even when two closures in $closures overlap
     * each other) and already in ascending order.
     *
     * @param string $from      Inclusive window start, 'Y-m-d'.
     * @param string $to        Inclusive window end, 'Y-m-d'.
     * @param array  $closures  Raw `store_closures` rows to check against.
     *
     * @return list<string> Closed dates within [$from, $to], ascending. Empty
     *                       when $from is after $to, either date is
     *                       unparseable, or the window exceeds MAX_SPAN_DAYS.
     *
     * @example
     *   Closures::closedDatesBetween('2026-07-01', '2026-07-10', [['start_date' => '2026-07-04', 'end_date' => '2026-07-05']]);
     *   // ['2026-07-04', '2026-07-05']
     */
    public static function closedDatesBetween(string $from, string $to, array $closures): array
    {
        $closed = [];

        foreach (self::datesInRange($from, $to) as $date) {
            if (self::isClosed($date, $closures)) {
                $closed[] = $date;
            }
        }

        return $closed;
    }

    /**
     * Whether two inclusive date ranges overlap.
     *
     * Touching endpoints DO count as overlapping (a range ending on the same
     * day another starts shares that day), but adjacent ranges with a gap of
     * zero whole days between them do NOT.
     *
     * @param string $aStart Inclusive start of the first range, 'Y-m-d'.
     * @param string $aEnd   Inclusive end of the first range, 'Y-m-d'.
     * @param string $bStart Inclusive start of the second range, 'Y-m-d'.
     * @param string $bEnd   Inclusive end of the second range, 'Y-m-d'.
     *
     * @return bool True when the two ranges share at least one day.
     *
     * @example
     *   Closures::overlaps('2026-01-01', '2026-01-05', '2026-01-05', '2026-01-09'); // true (touching)
     *   Closures::overlaps('2026-01-01', '2026-01-04', '2026-01-05', '2026-01-09'); // false (adjacent)
     */
    public static function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    /**
     * Validate an admin-submitted closure date range.
     *
     * Admin-facing, so problems are returned as English literals (the admin
     * UI does not localise). Checks, in order: both dates present; both
     * dates parseable; end on or after start; span within
     * {@see MAX_SPAN_DAYS}; and no overlap with any row in $existing (the
     * caller is responsible for excluding the row being edited from
     * $existing, e.g. via {@see \App\Models\StoreClosure::overlapping()}'s
     * $excludeId).
     *
     * @param string $start    Proposed inclusive start date, 'Y-m-d'.
     * @param string $end      Proposed inclusive end date, 'Y-m-d'.
     * @param array  $existing Raw `store_closures` rows already saved, checked for overlap.
     * @param array  $months   12 short month names (index 0 = January), used only to format overlap messages.
     *
     * @return list<string> Human-readable problems; empty when the range is valid.
     *
     * @example
     *   Closures::validateRange('2026-07-04', '2026-07-08', [], ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']); // []
     *   Closures::validateRange('2026-07-08', '2026-07-04', [], [...]); // ['The end date must be on or after the start date.']
     */
    public static function validateRange(string $start, string $end, array $existing, array $months): array
    {
        $start = trim($start);
        $end   = trim($end);

        if ($start === '' || $end === '') {
            return ['Please choose a start date and an end date.'];
        }

        $startDt = self::strictParse($start);
        $endDt   = self::strictParse($end);

        if ($startDt === null || $endDt === null) {
            return ['Please choose valid dates.'];
        }

        $errors = [];

        if ($endDt < $startDt) {
            $errors[] = 'The end date must be on or after the start date.';
        } elseif ($startDt->diff($endDt)->days + 1 > self::MAX_SPAN_DAYS) {
            $errors[] = 'A closure cannot be longer than 366 days.';
        }

        foreach ($existing as $row) {
            $rowStart = (string) ($row['start_date'] ?? '');
            $rowEnd   = (string) ($row['end_date'] ?? '');

            if ($rowStart !== '' && $rowEnd !== '' && self::overlaps($start, $end, $rowStart, $rowEnd)) {
                $errors[] = 'That range overlaps an existing closure (' . self::formatRange($row, $months) . ').';
            }
        }

        return $errors;
    }

    /**
     * Format a single closure's date range for display.
     *
     * $months carries the caller's locale-specific short month names so this
     * class remains locale-free — an English caller passes
     * ['Jan', 'Feb', ...] and a Spanish caller passes ['Ene', 'Feb', ...]
     * and the shape of the output is identical either way. Uses an en dash
     * (–) to join the two ends of a multi-day range.
     *
     * @param array $closure Raw `store_closures` row (start_date, end_date required).
     * @param array $months  12 short month names, index 0 = January.
     *
     * @return string The formatted range, or '' when start_date/end_date is missing or unparseable.
     *
     * @example
     *   Closures::formatRange(['start_date' => '2026-07-04', 'end_date' => '2026-07-04'], $enMonths); // 'Jul 4, 2026'
     *   Closures::formatRange(['start_date' => '2026-07-04', 'end_date' => '2026-07-08'], $enMonths); // 'Jul 4 – Jul 8, 2026'
     *   Closures::formatRange(['start_date' => '2026-12-30', 'end_date' => '2027-01-02'], $enMonths); // 'Dec 30, 2026 – Jan 2, 2027'
     */
    public static function formatRange(array $closure, array $months): string
    {
        $start = self::strictParse((string) ($closure['start_date'] ?? ''));
        $end   = self::strictParse((string) ($closure['end_date'] ?? ''));

        if ($start === null || $end === null) {
            return '';
        }

        $startLabel = self::monthDay($start, $months);
        $startYear  = $start->format('Y');

        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return "{$startLabel}, {$startYear}";
        }

        $endLabel = self::monthDay($end, $months);
        $endYear  = $end->format('Y');

        if ($startYear === $endYear) {
            return "{$startLabel} \u{2013} {$endLabel}, {$startYear}";
        }

        return "{$startLabel}, {$startYear} \u{2013} {$endLabel}, {$endYear}";
    }

    /**
     * Format many closures as a single semicolon-separated list.
     *
     * @param array $closures Raw `store_closures` rows.
     * @param array $months   12 short month names, index 0 = January.
     *
     * @return string Each closure's {@see formatRange()} joined with '; '.
     *
     * @example
     *   Closures::formatList([
     *       ['start_date' => '2026-07-04', 'end_date' => '2026-07-04'],
     *       ['start_date' => '2026-12-24', 'end_date' => '2026-12-26'],
     *   ], $enMonths); // 'Jul 4, 2026; Dec 24 – Dec 26, 2026'
     */
    public static function formatList(array $closures, array $months): string
    {
        return implode('; ', array_map(
            static fn (array $closure): string => self::formatRange($closure, $months),
            $closures
        ));
    }

    /**
     * Build the customer-facing message shown when a requested date is closed.
     *
     * Carries no hardcoded English/Spanish copy: $strings supplies
     * already-translated sprintf templates (typically built by
     * {@see \App\Controllers\BaseController::closureStrings()}) with keys
     * 'rejected' (one %s = the requested date), 'rejected_reason' (two %s =
     * date, reason), 'upcoming' (one %s = the formatted closure list), and
     * 'choose_another' (no placeholder). Uses the 'rejected_reason' template
     * when the covering closure has a non-empty reason, otherwise 'rejected'.
     *
     * @param string $requestedDate The date the customer asked for, 'Y-m-d'.
     * @param array  $closures      Raw `store_closures` rows (used both to find the covering
     *                              reason and to build the "upcoming closures" list).
     * @param array  $strings       Translated templates; see keys above.
     * @param array  $months        12 short month names, index 0 = January.
     *
     * @return string The assembled, translated rejection message.
     *
     * @example
     *   Closures::rejectionMessage('2026-07-05', $closures, [
     *       'rejected'        => 'Sorry, %s is closed.',
     *       'rejected_reason' => 'Sorry, %s is closed for %s.',
     *       'upcoming'        => 'Upcoming closures: %s.',
     *       'choose_another'  => 'Please choose another date.',
     *   ], $enMonths);
     *   // 'Sorry, 2026-07-05 is closed for Independence Day week. Upcoming closures: Jul 4 – Jul 8, 2026. Please choose another date.'
     */
    public static function rejectionMessage(string $requestedDate, array $closures, array $strings, array $months): string
    {
        $covering = self::covering($requestedDate, $closures);
        $reason   = trim((string) ($covering['reason'] ?? ''));

        $intro = $reason !== ''
            ? sprintf($strings['rejected_reason'] ?? '', $requestedDate, $reason)
            : sprintf($strings['rejected'] ?? '', $requestedDate);

        $upcoming = sprintf($strings['upcoming'] ?? '', self::formatList($closures, $months));

        return $intro . ' ' . $upcoming . ' ' . ($strings['choose_another'] ?? '');
    }

    /**
     * Build an admin-facing warning when an event date falls inside a closure.
     *
     * Admin-facing, so the copy is a hardcoded English literal (the admin UI
     * does not localise). Returns '' when $eventDate is open, so callers can
     * conditionally render a banner without an extra isClosed() check.
     *
     * @param string $eventDate Date to check, 'Y-m-d'.
     * @param array  $closures  Raw `store_closures` rows to check against.
     * @param array  $months    12 short month names, index 0 = January.
     *
     * @return string The warning sentence, or '' when the date is not closed.
     *
     * @example
     *   Closures::adminWarning('2026-07-05', [
     *       ['start_date' => '2026-07-04', 'end_date' => '2026-07-08', 'reason' => 'Independence Day week'],
     *   ], $enMonths);
     *   // 'Heads up: the event date (Jul 5, 2026) falls inside a store closure (Jul 4 – Jul 8, 2026 — Independence Day week).'
     */
    public static function adminWarning(string $eventDate, array $closures, array $months): string
    {
        $closure = self::covering($eventDate, $closures);
        if ($closure === null) {
            return '';
        }

        $eventDt = self::strictParse($eventDate);
        if ($eventDt === null) {
            return '';
        }

        $eventLabel   = self::monthDay($eventDt, $months) . ', ' . $eventDt->format('Y');
        $rangeLabel   = self::formatRange($closure, $months);
        $reason       = trim((string) ($closure['reason'] ?? ''));
        $reasonSuffix = $reason !== '' ? " \u{2014} {$reason}" : '';

        return "Heads up: the event date ({$eventLabel}) falls inside a store closure ({$rangeLabel}{$reasonSuffix}).";
    }

    /**
     * Parse a 'Y-m-d' date strictly, rejecting anything that doesn't parse cleanly.
     *
     * Mirrors {@see Fulfillment::parse()}'s idiom: createFromFormat() with a
     * leading '!' (reset all unspecified fields to the Unix epoch, avoiding
     * "now" leaking in) followed by getLastErrors(), so a date like
     * '2026-13-99' is rejected instead of silently rolling over to a
     * different month.
     *
     * @param string $date Date to parse, expected 'Y-m-d'.
     *
     * @return DateTimeImmutable|null The parsed date at midnight, or null when malformed.
     */
    private static function strictParse(string $date): ?DateTimeImmutable
    {
        $dt  = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
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
     * Format a date as "{Mon} {d}" using the caller's injected month names.
     *
     * @param DateTimeImmutable $date   The date to format.
     * @param array             $months 12 short month names, index 0 = January.
     *
     * @return string E.g. 'Jul 4' (day is not zero-padded).
     */
    private static function monthDay(DateTimeImmutable $date, array $months): string
    {
        $index = ((int) $date->format('n')) - 1;
        $month = $months[$index] ?? $date->format('M');
        $day   = (int) $date->format('j');

        return "{$month} {$day}";
    }
}
