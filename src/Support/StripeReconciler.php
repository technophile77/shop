<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure matcher that reconciles Stripe charges against this codebase's own
 * record of what it believes it collected — the `quotes` and `orders`
 * tables — so a human can answer "which Stripe payments correspond to which
 * quotes/orders, and what doesn't line up" without trusting either side
 * blindly.
 *
 * Every charge is matched to at most one local record (a quote or a shop
 * order), and vice versa, using three rules tried **in strict priority
 * order**, each only considering pairs still unmatched by the rule before
 * it:
 *
 *   1. `'payment_intent'` — the local record's stored
 *      `payment_intent_id` equals the charge's `payment_intent`. This is
 *      the strongest signal: a PaymentIntent belongs to exactly one charge.
 *   2. `'session'` — the local record's stored `session_id` names a
 *      Checkout Session whose `payment_intent` equals the charge's
 *      `payment_intent`. Needed because some older/edited quotes only ever
 *      had a session id written, never a payment intent id (see the
 *      quote-5 case in `docs/stripe-reconciliation.md`).
 *   3. `'amount_and_date'` — **last resort**: the local's `expected` dollar
 *      amount equals the charge's `amount` within half a cent, and the
 *      charge's `created_utc` falls within {@see self::MATCH_WINDOW_DAYS}
 *      days of the local's `recognized_at`. This rule exists because a
 *      payment taken outside the site's own checkout flow — a mobile
 *      card-reader app, a payment link, a Stripe Dashboard-initiated charge
 *      — never writes a session or PaymentIntent id back onto the quote or
 *      order row, so identity matching (rules 1 and 2) structurally cannot
 *      reach it; amount and date are the only signal left. Because it is a
 *      heuristic rather than an identity match, every `amount_and_date`
 *      match is also recorded as a warning, and an ambiguous case (two
 *      equally-close candidate charges) is left unmatched rather than
 *      guessed — a wrong silent match would be worse than an honest gap.
 *
 * A match whose amounts disagree by more than half a cent is not treated as
 * a non-match: it is real information (the same payment, recorded with
 * different amounts on each side) and is placed in **both** `matched` and
 * `amount_mismatches`.
 *
 * This class takes no filesystem, network, or database action, so it is
 * fully unit-testable: {@see \App\Services\StripeService::listCharges()}
 * and {@see \App\Services\StripeService::listCheckoutSessions()} own all
 * Stripe API access, and `bin/stripe-reconcile.php` owns building
 * `$localPayments` from the database.
 *
 * @example
 *   // Quote 5 was paid through Checkout but only its session id was ever
 *   // stored (its payment_intent_id column is empty), so rule 1 cannot
 *   // reach it and rule 2 must:
 *   $result = StripeReconciler::reconcile(
 *       [['id' => 'ch_1', 'created_utc' => '2026-06-06 20:37:29', 'status' => 'succeeded',
 *         'paid' => true, 'amount' => 189.90, 'amount_refunded' => 0.00, 'refunded' => false,
 *         'disputed' => false, 'payment_intent' => 'pi_1', 'billing_name' => 'Jane Doe',
 *         'description' => '', 'metadata' => []]],
 *       [['id' => 'cs_1', 'created_utc' => '2026-06-06 20:37:20', 'status' => 'complete',
 *         'payment_status' => 'paid', 'amount_total' => 189.90, 'amount_subtotal' => 175.00,
 *         'amount_tax' => 14.90, 'payment_intent' => 'pi_1', 'metadata' => [], 'email' => '']],
 *       [['kind' => 'quote', 'id' => 5, 'label' => 'quote 5', 'expected' => 189.90,
 *         'status' => 'deposit_confirmed', 'recognized_at' => '2026-06-06 20:40:00',
 *         'session_id' => 'cs_1', 'payment_intent_id' => '', 'customer_name' => 'Jane Doe']]
 *   );
 *   // $result['matched'][0]['method'] === 'session'
 *   // $result['amount_mismatches'] === []
 *
 * @see \App\Services\StripeService::listCharges()
 * @see \App\Services\StripeService::listCheckoutSessions()
 * @see \docs\stripe-reconciliation.md
 */
final class StripeReconciler
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * The `amount_and_date` window, in days either side of a local record's
     * `recognized_at`, within which a charge is even considered a candidate.
     *
     * Four days is generous enough to cover a deposit confirmed a couple of
     * days after an off-site card-reader payment (the owner keying it into
     * the admin later) without being so wide that unrelated payments of the
     * same round dollar amount start colliding.
     */
    public const MATCH_WINDOW_DAYS = 4;

    /**
     * The largest amount difference, in dollars, still treated as "the same
     * amount" for both the `amount_and_date` candidate test and the
     * `amount_mismatches` classification — half a cent, to absorb ordinary
     * floating-point rounding without masking a genuine cent-level disagreement.
     */
    private const AMOUNT_TOLERANCE = 0.005;

    /**
     * Reconcile a list of Stripe charges against this codebase's own record
     * of expected payments (quotes and shop orders), using the
     * priority-ordered matching strategy documented on this class.
     *
     * @param list<array{id: string, created_utc: string, status: string, paid: bool,
     *              amount: float, amount_refunded: float, refunded: bool, disputed: bool,
     *              payment_intent: string, billing_name: string, description: string,
     *              metadata: array<string, string>}> $stripeCharges
     *        Every Stripe charge in the reconciliation window, as returned by
     *        {@see \App\Services\StripeService::listCharges()}.
     * @param list<array{id: string, created_utc: string, status: string, payment_status: string,
     *              amount_total: float, amount_subtotal: float, amount_tax: float,
     *              payment_intent: string, metadata: array<string, string>, email: string}> $sessions
     *        Every Checkout Session in the same window, as returned by
     *        {@see \App\Services\StripeService::listCheckoutSessions()}; used only by rule 2.
     * @param list<array{kind: string, id: int, label: string, expected: float, status: string,
     *              recognized_at: ?string, session_id: string, payment_intent_id: string,
     *              customer_name: string}> $localPayments
     *        Every quote/order this codebase believes was paid, normalized by the caller.
     *
     * @return array{
     *     matched: list<array{local: array, charge: array, method: string, delta: float}>,
     *     unmatched_stripe: list<array{charge: array, note: string}>,
     *     unmatched_local: list<array{local: array, note: string}>,
     *     amount_mismatches: list<array{local: array, charge: array, delta: float, method: string}>,
     *     refunds: list<array{charge: array, amount_refunded: float}>,
     *     disputes: list<array{charge: array}>,
     *     totals: array{stripe_settled: float, local_expected: float, matched_stripe: float,
     *         matched_local: float, delta: float},
     *     warnings: list<string>,
     * }
     *         See the property docs above for each key; `warnings` collects
     *         every heuristic match, ambiguity, refund, dispute, and
     *         unexplained charge/local record found during reconciliation.
     */
    public static function reconcile(array $stripeCharges, array $sessions, array $localPayments): array
    {
        $byPaymentIntent = self::matchByPaymentIntent($localPayments, $stripeCharges);
        $bySession       = self::matchBySession($byPaymentIntent['locals'], $byPaymentIntent['charges'], $sessions);
        $byAmountAndDate = self::matchByAmountAndDate($bySession['locals'], $bySession['charges']);

        $matched = array_map(
            self::withDelta(...),
            [...$byPaymentIntent['matched'], ...$bySession['matched'], ...$byAmountAndDate['matched']]
        );

        $amountMismatches = array_values(array_filter(
            $matched,
            static fn (array $pair): bool => abs($pair['delta']) > self::AMOUNT_TOLERANCE
        ));

        $remainingCharges = array_values($byAmountAndDate['charges']);
        $remainingLocals  = array_values($byAmountAndDate['locals']);

        $unmatchedStripe = array_map(
            static fn (array $charge): array => ['charge' => $charge, 'note' => self::unmatchedStripeNote($charge)],
            $remainingCharges
        );

        $unmatchedLocal = array_map(
            static fn (array $local): array => ['local' => $local, 'note' => self::unmatchedLocalNote($local)],
            $remainingLocals
        );

        $warnings = $byAmountAndDate['warnings'];

        foreach ($remainingCharges as $charge) {
            $warnings[] = sprintf(
                'Unmatched Stripe charge %s ($%.2f on %s): money received with no local quote/order record explaining it.',
                $charge['id'],
                $charge['amount'],
                $charge['created_utc']
            );
        }

        foreach ($remainingLocals as $local) {
            if ($local['expected'] > 0.0 && $local['recognized_at'] !== null) {
                $warnings[] = sprintf(
                    'Unmatched local record %s (expected $%.2f, recognized %s): no Stripe charge found for it.',
                    $local['label'],
                    $local['expected'],
                    $local['recognized_at']
                );
            }
        }

        [$refunds, $disputes, $refundDisputeWarnings] = self::findRefundsAndDisputes($stripeCharges);
        $warnings = [...$warnings, ...$refundDisputeWarnings];

        return [
            'matched'            => $matched,
            'unmatched_stripe'   => $unmatchedStripe,
            'unmatched_local'    => $unmatchedLocal,
            'amount_mismatches'  => $amountMismatches,
            'refunds'            => $refunds,
            'disputes'           => $disputes,
            'totals'             => self::computeTotals($stripeCharges, $localPayments, $matched),
            'warnings'           => $warnings,
        ];
    }

    /**
     * Rule 1: match every local record whose stored `payment_intent_id` is
     * non-empty to the charge sharing that PaymentIntent, before any other
     * rule runs.
     *
     * @param list<array{payment_intent_id: string}> $locals  Candidate local records.
     * @param list<array{payment_intent: string}>    $charges Candidate charges.
     *
     * @return array{matched: list<array{local: array, charge: array, method: string}>,
     *         locals: array<int, array>, charges: array<int, array>}
     *         `matched` pairs found by this rule; `locals`/`charges` are the
     *         remaining candidate pools (original keys preserved, so callers
     *         can safely feed them into the next rule).
     */
    private static function matchByPaymentIntent(array $locals, array $charges): array
    {
        $matched = [];

        foreach ($locals as $li => $local) {
            if ($local['payment_intent_id'] === '') {
                continue;
            }

            foreach ($charges as $ci => $charge) {
                if ($charge['payment_intent'] === $local['payment_intent_id']) {
                    $matched[] = ['local' => $local, 'charge' => $charge, 'method' => 'payment_intent'];
                    unset($locals[$li], $charges[$ci]);
                    break;
                }
            }
        }

        return ['matched' => $matched, 'locals' => $locals, 'charges' => $charges];
    }

    /**
     * Rule 2: match every remaining local record whose stored `session_id`
     * names a known Checkout Session to the charge sharing that session's
     * `payment_intent`.
     *
     * Only reached for local records rule 1 didn't already resolve — see
     * this class's DocBlock for why a session-only local record exists at
     * all (quote 5's payment_intent_id column was never populated).
     *
     * @param array<int, array{session_id: string}> $locals  Remaining candidate local records.
     * @param array<int, array{payment_intent: string}> $charges Remaining candidate charges.
     * @param list<array{id: string, payment_intent: string}> $sessions Every known Checkout Session.
     *
     * @return array{matched: list<array{local: array, charge: array, method: string}>,
     *         locals: array<int, array>, charges: array<int, array>}
     *         `matched` pairs found by this rule; `locals`/`charges` are the
     *         remaining candidate pools for rule 3.
     */
    private static function matchBySession(array $locals, array $charges, array $sessions): array
    {
        $sessionsById = [];
        foreach ($sessions as $session) {
            $sessionsById[$session['id']] = $session;
        }

        $matched = [];

        foreach ($locals as $li => $local) {
            if ($local['session_id'] === '') {
                continue;
            }

            $session = $sessionsById[$local['session_id']] ?? null;
            if ($session === null || $session['payment_intent'] === '') {
                continue;
            }

            foreach ($charges as $ci => $charge) {
                if ($charge['payment_intent'] === $session['payment_intent']) {
                    $matched[] = ['local' => $local, 'charge' => $charge, 'method' => 'session'];
                    unset($locals[$li], $charges[$ci]);
                    break;
                }
            }
        }

        return ['matched' => $matched, 'locals' => $locals, 'charges' => $charges];
    }

    /**
     * Rule 3 (last resort): match every remaining local record to the
     * nearest-in-time remaining charge of the same amount, within
     * {@see self::MATCH_WINDOW_DAYS} days — the only rule reached by
     * payments taken outside the site's own checkout flow.
     *
     * Processes local records in their given order. For each, every
     * remaining charge within tolerance on both amount and date is a
     * candidate; the closest-in-time candidate is chosen. When the two
     * closest candidates are exactly equidistant, the match is genuinely
     * ambiguous — the local record and both tied charges are left unmatched
     * (the charges remain available to a *different* local record later in
     * the loop) and a warning names the ambiguity, rather than guessing.
     *
     * @param array<int, array{label: string, expected: float, recognized_at: ?string}> $locals
     *        Remaining candidate local records after rules 1 and 2.
     * @param array<int, array{id: string, amount: float, created_utc: string}> $charges
     *        Remaining candidate charges after rules 1 and 2.
     *
     * @return array{matched: list<array{local: array, charge: array, method: string}>,
     *         locals: array<int, array>, charges: array<int, array>, warnings: list<string>}
     *         `matched` pairs found by this rule; `locals`/`charges` are
     *         whatever remains genuinely unexplained; `warnings` names every
     *         heuristic match (it is never presented as certain) and every
     *         ambiguity this rule refused to guess at.
     */
    private static function matchByAmountAndDate(array $locals, array $charges): array
    {
        $matched  = [];
        $warnings = [];

        foreach ($locals as $li => $local) {
            if ($local['recognized_at'] === null) {
                continue;
            }

            $localTimestamp = self::parseUtc($local['recognized_at']);
            if ($localTimestamp === null) {
                continue;
            }

            $candidates = [];
            foreach ($charges as $ci => $charge) {
                if (abs($local['expected'] - $charge['amount']) > self::AMOUNT_TOLERANCE) {
                    continue;
                }

                $chargeTimestamp = self::parseUtc($charge['created_utc']);
                if ($chargeTimestamp === null) {
                    continue;
                }

                $distance = abs($chargeTimestamp - $localTimestamp);
                if ($distance > self::MATCH_WINDOW_DAYS * 86400) {
                    continue;
                }

                $candidates[] = ['index' => $ci, 'distance' => $distance, 'charge' => $charge];
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

            if (count($candidates) > 1 && $candidates[0]['distance'] === $candidates[1]['distance']) {
                $warnings[] = sprintf(
                    'Ambiguous amount+date match for %s: charges %s and %s are equally close'
                        . ' (%d seconds) to %s; left unmatched rather than guessed — resolve manually.',
                    $local['label'],
                    $candidates[0]['charge']['id'],
                    $candidates[1]['charge']['id'],
                    $candidates[0]['distance'],
                    $local['recognized_at']
                );
                continue;
            }

            $best = $candidates[0];
            $matched[] = ['local' => $local, 'charge' => $best['charge'], 'method' => 'amount_and_date'];
            $warnings[] = sprintf(
                'Heuristic amount+date match for %s: matched to charge %s ($%.2f on %s).'
                    . ' No PaymentIntent or Checkout Session id links this payment (likely taken'
                    . ' outside the site\'s checkout), so this match is a heuristic, not certain.',
                $local['label'],
                $best['charge']['id'],
                $best['charge']['amount'],
                $best['charge']['created_utc']
            );

            unset($locals[$li], $charges[$best['index']]);
        }

        return ['matched' => $matched, 'locals' => $locals, 'charges' => $charges, 'warnings' => $warnings];
    }

    /**
     * Strictly parse a `'Y-m-d H:i:s'` string, already known to be UTC, into
     * a Unix timestamp.
     *
     * @param string $value The timestamp string to parse.
     *
     * @return int|null The Unix timestamp, or null when `$value` doesn't
     *         strictly match the expected format (treated as "no candidate"
     *         by the caller, never as a crash).
     */
    private static function parseUtc(string $value): ?int
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $parsed !== false ? $parsed->getTimestamp() : null;
    }

    /**
     * Attach the signed dollar delta (`local.expected - charge.amount`) to a
     * matched pair.
     *
     * @param array{local: array{expected: float}, charge: array{amount: float}, method: string} $pair
     *        A matched pair from any of the three matching rules.
     *
     * @return array{local: array, charge: array, method: string, delta: float}
     *         The same pair with `delta` added; positive means the local
     *         record expected more than Stripe actually charged.
     */
    private static function withDelta(array $pair): array
    {
        $pair['delta'] = round($pair['local']['expected'] - $pair['charge']['amount'], 2);

        return $pair;
    }

    /**
     * Build the explanatory note attached to an unmatched Stripe charge.
     *
     * @param array{id: string} $charge The unmatched charge.
     *
     * @return string A self-contained sentence naming the charge and every rule that failed.
     */
    private static function unmatchedStripeNote(array $charge): string
    {
        return sprintf(
            'No local quote/order matches charge %s by PaymentIntent, Checkout Session, or amount+date within %d days.',
            $charge['id'],
            self::MATCH_WINDOW_DAYS
        );
    }

    /**
     * Build the explanatory note attached to an unmatched local record.
     *
     * @param array{label: string, expected: float} $local The unmatched local record.
     *
     * @return string A self-contained sentence naming the record and every rule that failed.
     */
    private static function unmatchedLocalNote(array $local): string
    {
        return sprintf(
            'No Stripe charge matches %s (expected $%.2f) by PaymentIntent, Checkout Session, or amount+date within %d days.',
            $local['label'],
            $local['expected'],
            self::MATCH_WINDOW_DAYS
        );
    }

    /**
     * Find every refunded and every disputed charge in the full input list
     * (not just matched ones — a refund or dispute is worth surfacing
     * regardless of whether the charge was explained), and build one
     * warning per occurrence.
     *
     * @param list<array{id: string, amount_refunded: float, disputed: bool}> $stripeCharges
     *        Every charge passed to {@see self::reconcile()}.
     *
     * @return array{0: list<array{charge: array, amount_refunded: float}>,
     *         1: list<array{charge: array}>, 2: list<string>}
     *         In order: the `refunds` list, the `disputes` list, and one
     *         warning per entry in either (refund warnings first, in charge order).
     */
    private static function findRefundsAndDisputes(array $stripeCharges): array
    {
        $refunds  = [];
        $disputes = [];
        $warnings = [];

        foreach ($stripeCharges as $charge) {
            if ($charge['amount_refunded'] > 0.0) {
                $refunds[]  = ['charge' => $charge, 'amount_refunded' => $charge['amount_refunded']];
                $warnings[] = sprintf(
                    'Refund on charge %s: $%.2f refunded.',
                    $charge['id'],
                    $charge['amount_refunded']
                );
            }

            if ($charge['disputed']) {
                $disputes[] = ['charge' => $charge];
                $warnings[] = sprintf('Charge %s is disputed.', $charge['id']);
            }
        }

        return [$refunds, $disputes, $warnings];
    }

    /**
     * Compute the reconciliation's headline totals.
     *
     * `stripe_settled` and `local_expected` are computed over the *full*
     * input lists (not just matched pairs), so they answer "how much money
     * did each side think changed hands", independent of whether every
     * payment could be explained; `matched_stripe`/`matched_local` are the
     * matched subset of each, useful for seeing how much of each total is
     * still unexplained.
     *
     * @param list<array{amount: float, amount_refunded: float, paid: bool, status: string}> $stripeCharges
     *        Every charge passed to {@see self::reconcile()}.
     * @param list<array{expected: float}> $localPayments Every local record passed to {@see self::reconcile()}.
     * @param list<array{local: array{expected: float}, charge: array{amount: float}}> $matched
     *        Every matched pair (from all three rules).
     *
     * @return array{stripe_settled: float, local_expected: float, matched_stripe: float,
     *         matched_local: float, delta: float}
     *         `delta` is `stripe_settled - local_expected`; positive means
     *         Stripe settled more than the local records expected in total.
     */
    private static function computeTotals(array $stripeCharges, array $localPayments, array $matched): array
    {
        $stripeSettled = 0.0;
        foreach ($stripeCharges as $charge) {
            if ($charge['paid'] === true && $charge['status'] === 'succeeded') {
                $stripeSettled += $charge['amount'] - $charge['amount_refunded'];
            }
        }

        $localExpected = 0.0;
        foreach ($localPayments as $local) {
            $localExpected += $local['expected'];
        }

        $matchedStripe = 0.0;
        $matchedLocal  = 0.0;
        foreach ($matched as $pair) {
            $matchedStripe += $pair['charge']['amount'];
            $matchedLocal  += $pair['local']['expected'];
        }

        $stripeSettled = round($stripeSettled, 2);
        $localExpected = round($localExpected, 2);

        return [
            'stripe_settled' => $stripeSettled,
            'local_expected' => $localExpected,
            'matched_stripe' => round($matchedStripe, 2),
            'matched_local'  => round($matchedLocal, 2),
            'delta'          => round($stripeSettled - $localExpected, 2),
        ];
    }
}
