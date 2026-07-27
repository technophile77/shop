<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\StripeReconciler;
use PHPUnit\Framework\TestCase;

/**
 * Unit and property tests for StripeReconciler::reconcile().
 *
 * The example-based tests are grounded in a real, verified 2026-07-27
 * reconciliation against the live Stripe account (11 charges, 11 local
 * quotes/orders — see docs/stripe-reconciliation.md) so every expected
 * figure below is a hand-computed literal from that known-good data, never
 * a value recomputed by calling the reconciler itself. Covers: the full
 * fixture closing with zero unmatched on either side; the hand-summed
 * totals; a quote whose stale stored tax/subtotal produces a real amount
 * mismatch while still being a genuine match; a constructed session-only
 * match proving rule 2 is reachable at all (no live record exercises it);
 * a heuristic amount+date match with its warning; rule priority (a stored PaymentIntent id beats a
 * same-amount/same-date decoy); one-to-one matching when two locals compete
 * for a single charge; an equidistant ambiguity that is left unmatched
 * rather than guessed; refund and dispute handling; and an unmatched charge
 * warning. Stochastic tests then check one-to-one-ness, conservation, order
 * invariance, and identity-match dominance across seeded random fixtures.
 *
 * @see \App\Support\StripeReconciler
 */
class StripeReconcilerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fixture builders
    // -------------------------------------------------------------------------

    /**
     * Build one normalized Stripe charge, as {@see \App\Services\StripeService::listCharges()} returns it.
     */
    private function charge(string $id, string $createdUtc, float $amount, array $overrides = []): array
    {
        return array_merge([
            'id'              => $id,
            'created_utc'     => $createdUtc,
            'status'          => 'succeeded',
            'paid'            => true,
            'amount'          => $amount,
            'amount_refunded' => 0.00,
            'refunded'        => false,
            'disputed'        => false,
            'payment_intent'  => '',
            'billing_name'    => '',
            'description'     => '',
            'metadata'        => [],
        ], $overrides);
    }

    /**
     * Build one normalized Checkout Session, as
     * {@see \App\Services\StripeService::listCheckoutSessions()} returns it.
     */
    private function session(string $id, string $createdUtc, float $amountTotal, array $overrides = []): array
    {
        return array_merge([
            'id'              => $id,
            'created_utc'     => $createdUtc,
            'status'          => 'complete',
            'payment_status'  => 'paid',
            'amount_total'    => $amountTotal,
            'amount_subtotal' => $amountTotal,
            'amount_tax'      => 0.00,
            'payment_intent'  => '',
            'metadata'        => [],
            'email'           => '',
        ], $overrides);
    }

    /**
     * Build one normalized local payment record, as `bin/stripe-reconcile.php` builds it from quotes/orders.
     */
    private function local(string $kind, int $id, string $label, float $expected, array $overrides = []): array
    {
        return array_merge([
            'kind'               => $kind,
            'id'                 => $id,
            'label'              => $label,
            'expected'           => $expected,
            'status'             => 'deposit_confirmed',
            'recognized_at'      => null,
            'session_id'         => '',
            'payment_intent_id'  => '',
            'customer_name'      => '',
        ], $overrides);
    }

    /**
     * The real, verified 2026-07-27 reconciliation fixture: 11 charges and
     * their 11 matching local records, spanning three matching rules.
     * Quote 17's tax_amount/deposit_amount are stale ($1.18 short — see
     * docs/stripe-reconciliation.md's known findings). Quotes 4 and 5 have
     * neither a stored session id nor a payment intent id (both columns are
     * NULL in the live database), so only their amount and date link them to
     * their charges. There are therefore no Checkout Sessions in this
     * fixture: rule 2 does not fire on any real record today, and is covered
     * separately by {@see self::testSessionOnlyLocalMatchesViaRuleTwo()}.
     *
     * @return array{0: list<array>, 1: list<array>, 2: list<array>} charges, sessions, locals
     */
    private function realFixture(): array
    {
        $charges = [
            $this->charge('ch_13', '2026-07-24 00:13:55', 107.67, ['payment_intent' => 'pi_13']),
            $this->charge('ch_19', '2026-07-23 22:01:18', 137.82, ['payment_intent' => 'pi_19']),
            $this->charge('ch_17', '2026-07-20 22:51:16', 119.36, ['payment_intent' => 'pi_17']),
            $this->charge('ch_11', '2026-07-16 15:39:11', 37.98, ['payment_intent' => 'pi_11']),
            $this->charge('ch_15', '2026-07-11 23:18:32', 170.37, ['payment_intent' => 'pi_15']),
            $this->charge('ch_16', '2026-07-11 22:38:06', 140.64, ['payment_intent' => 'pi_16']),
            $this->charge('ch_14', '2026-07-09 21:59:41', 64.03, ['payment_intent' => 'pi_14']),
            $this->charge('ch_9', '2026-06-29 22:54:17', 27.13, ['payment_intent' => 'pi_9']),
            $this->charge('ch_7', '2026-06-24 00:15:28', 92.24, ['payment_intent' => 'pi_7']),
            $this->charge('ch_5', '2026-06-06 20:37:29', 189.90, ['payment_intent' => 'pi_5']),
            $this->charge('ch_4', '2026-06-04 18:55:20', 81.39, ['payment_intent' => '']),
        ];

        $sessions = [];

        $locals = [
            $this->local('order', 13, 'order 13', 107.67, [
                'recognized_at' => '2026-07-24 00:13:58', 'payment_intent_id' => 'pi_13',
            ]),
            $this->local('quote', 19, 'quote 19', 137.82, [
                'recognized_at' => '2026-07-23 22:01:20', 'payment_intent_id' => 'pi_19',
            ]),
            // Quote 17's stored tax_amount (8.18) and deposit_amount are stale — computed against
            // a $96.00 subtotal later edited to $110.00. Expected here reflects the STALE stored
            // column (110.00 + 8.18 = 118.18), which is $1.18 short of what Stripe actually charged.
            $this->local('quote', 17, 'quote 17', 118.18, [
                'recognized_at' => '2026-07-20 22:51:20', 'payment_intent_id' => 'pi_17',
            ]),
            $this->local('order', 11, 'order 11', 37.98, [
                'recognized_at' => '2026-07-16 15:39:15', 'payment_intent_id' => 'pi_11',
            ]),
            $this->local('quote', 15, 'quote 15', 170.37, [
                'recognized_at' => '2026-07-11 23:18:35', 'payment_intent_id' => 'pi_15',
            ]),
            $this->local('quote', 16, 'quote 16', 140.64, [
                'recognized_at' => '2026-07-11 22:38:10', 'payment_intent_id' => 'pi_16',
            ]),
            $this->local('quote', 14, 'quote 14', 64.03, [
                'recognized_at' => '2026-07-09 22:00:00', 'payment_intent_id' => 'pi_14',
            ]),
            $this->local('quote', 9, 'quote 9', 27.13, [
                'recognized_at' => '2026-06-29 22:54:20', 'payment_intent_id' => 'pi_9',
            ]),
            $this->local('quote', 7, 'quote 7', 92.24, [
                'recognized_at' => '2026-06-24 00:15:30', 'payment_intent_id' => 'pi_7',
            ]),
            // Quotes 4 and 5: both stripe id columns are NULL in the live database, so neither
            // rule 1 nor rule 2 can reach them — only the amount+date heuristic can.
            $this->local('quote', 5, 'quote 5', 189.90, [
                'recognized_at' => '2026-06-06 20:40:00',
            ]),
            $this->local('quote', 4, 'quote 4', 81.39, [
                'recognized_at' => '2026-06-04 22:50:30',
            ]),
        ];

        return [$charges, $sessions, $locals];
    }

    // -------------------------------------------------------------------------
    // 1. Full fixture
    // -------------------------------------------------------------------------

    public function testFullFixtureReconcilesElevenMatchesZeroUnmatched(): void
    {
        [$charges, $sessions, $locals] = $this->realFixture();

        $result = StripeReconciler::reconcile($charges, $sessions, $locals);

        $this->assertCount(11, $result['matched']);
        $this->assertSame([], $result['unmatched_stripe']);
        $this->assertSame([], $result['unmatched_local']);
    }

    // -------------------------------------------------------------------------
    // 2. Totals
    // -------------------------------------------------------------------------

    public function testTotalsMatchHandComputedFigures(): void
    {
        [$charges, $sessions, $locals] = $this->realFixture();

        $result = StripeReconciler::reconcile($charges, $sessions, $locals);

        // Hand-sum of the eleven charge amounts in the table.
        $this->assertSame(1168.53, $result['totals']['stripe_settled']);
        // Hand-sum of the eleven locals' expected amounts (quote 17 stale by $1.18).
        $this->assertSame(1167.35, $result['totals']['local_expected']);
        $this->assertSame(1.18, $result['totals']['delta']);
    }

    // -------------------------------------------------------------------------
    // 3. Quote 17: matched AND an amount mismatch
    // -------------------------------------------------------------------------

    public function testQuote17IsBothMatchedAndAnAmountMismatch(): void
    {
        [$charges, $sessions, $locals] = $this->realFixture();

        $result = StripeReconciler::reconcile($charges, $sessions, $locals);

        $matchedQuote17 = array_values(array_filter(
            $result['matched'],
            static fn (array $pair): bool => $pair['local']['label'] === 'quote 17'
        ));
        $this->assertCount(1, $matchedQuote17);
        $this->assertSame('payment_intent', $matchedQuote17[0]['method']);
        $this->assertSame(-1.18, $matchedQuote17[0]['delta']);

        $mismatchQuote17 = array_values(array_filter(
            $result['amount_mismatches'],
            static fn (array $pair): bool => $pair['local']['label'] === 'quote 17'
        ));
        $this->assertCount(1, $mismatchQuote17);
        $this->assertSame(-1.18, $mismatchQuote17[0]['delta']);
    }

    // -------------------------------------------------------------------------
    // 4. Rule 2: a session-only local matches, and ONLY rule 2 could have done it
    // -------------------------------------------------------------------------

    /**
     * No live record exercises rule 2 today (every quote either stores a
     * PaymentIntent id or stores neither id), so this case is constructed:
     * a local with a session id but no PaymentIntent id, whose charge sits
     * well outside {@see StripeReconciler::MATCH_WINDOW_DAYS} of its
     * `recognized_at`. Rule 1 cannot see it (no stored intent) and rule 3
     * cannot reach it (far outside the date window), so a successful match
     * proves rule 2 fired rather than something else covering for it.
     */
    public function testSessionOnlyLocalMatchesViaRuleTwo(): void
    {
        $daysApart = StripeReconciler::MATCH_WINDOW_DAYS + 10;

        $charge  = $this->charge('ch_S', '2026-05-01 12:00:00', 189.90, ['payment_intent' => 'pi_S']);
        $session = $this->session('cs_S', '2026-05-01 11:59:50', 189.90, ['payment_intent' => 'pi_S']);
        $local   = $this->local('quote', 905, 'quote session-only', 189.90, [
            'recognized_at'     => '2026-05-' . str_pad((string) (1 + $daysApart), 2, '0', STR_PAD_LEFT) . ' 12:00:00',
            'session_id'        => 'cs_S',
            'payment_intent_id' => '',
        ]);

        $result = StripeReconciler::reconcile([$charge], [$session], [$local]);

        $this->assertCount(1, $result['matched']);
        $this->assertSame('session', $result['matched'][0]['method']);
        $this->assertSame('ch_S', $result['matched'][0]['charge']['id']);
    }

    /**
     * Both quotes that reconcile heuristically in the live data have NULL in
     * both stripe id columns, so the fixture must carry no session at all —
     * a session invented for quote 5 would make rule 2 look like it fires on
     * real data when it never does.
     */
    public function testRealFixtureCarriesNoSessionsAndNoSessionMatches(): void
    {
        [$charges, $sessions, $locals] = $this->realFixture();

        $this->assertSame([], $sessions);

        $result = StripeReconciler::reconcile($charges, $sessions, $locals);

        $methods = array_map(static fn (array $pair): string => $pair['method'], $result['matched']);
        $this->assertNotContains('session', $methods);
        $this->assertSame(9, count(array_filter($methods, static fn (string $m): bool => $m === 'payment_intent')));
        $this->assertSame(2, count(array_filter($methods, static fn (string $m): bool => $m === 'amount_and_date')));
    }

    // -------------------------------------------------------------------------
    // 5. Quote 4: amount_and_date heuristic match, with warning
    // -------------------------------------------------------------------------

    public function testQuote4MatchesViaAmountAndDateAndWarnsAsHeuristic(): void
    {
        [$charges, $sessions, $locals] = $this->realFixture();

        $result = StripeReconciler::reconcile($charges, $sessions, $locals);

        $matchedQuote4 = array_values(array_filter(
            $result['matched'],
            static fn (array $pair): bool => $pair['local']['label'] === 'quote 4'
        ));
        $this->assertCount(1, $matchedQuote4);
        $this->assertSame('amount_and_date', $matchedQuote4[0]['method']);

        $heuristicWarnings = array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'quote 4') && str_contains($w, 'Heuristic')
        ));
        $this->assertCount(1, $heuristicWarnings);
    }

    // -------------------------------------------------------------------------
    // 6. Priority: payment_intent beats an amount+date decoy
    // -------------------------------------------------------------------------

    public function testStoredPaymentIntentWinsOverAnAmountAndDateDecoy(): void
    {
        // Local L's stored payment_intent_id points at charge A, but its amount and date would
        // also fit charge B exactly — rule 1 must claim A before rule 3 ever runs.
        $chargeA = $this->charge('ch_A', '2026-01-01 00:00:00', 250.00, ['payment_intent' => 'pi_A']);
        $chargeB = $this->charge('ch_B', '2026-07-10 12:00:00', 100.00, ['payment_intent' => 'pi_B']);

        $localL = $this->local('quote', 900, 'quote decoy', 100.00, [
            'recognized_at' => '2026-07-10 12:00:00', 'payment_intent_id' => 'pi_A',
        ]);

        $result = StripeReconciler::reconcile([$chargeA, $chargeB], [], [$localL]);

        $this->assertCount(1, $result['matched']);
        $this->assertSame('ch_A', $result['matched'][0]['charge']['id']);
        $this->assertSame('payment_intent', $result['matched'][0]['method']);

        // Charge B was never claimed — it's the decoy, left unexplained.
        $this->assertCount(1, $result['unmatched_stripe']);
        $this->assertSame('ch_B', $result['unmatched_stripe'][0]['charge']['id']);
    }

    // -------------------------------------------------------------------------
    // 7. One-to-one: two locals competing for one charge
    // -------------------------------------------------------------------------

    public function testTwoIdenticalLocalsCompetingForOneChargeProduceOneMatch(): void
    {
        $localA = $this->local('order', 901, 'order 50a', 50.00, ['recognized_at' => '2026-07-10 12:00:00']);
        $localB = $this->local('order', 902, 'order 50b', 50.00, ['recognized_at' => '2026-07-10 12:00:01']);
        $chargeC = $this->charge('ch_C', '2026-07-10 12:00:00', 50.00);

        $result = StripeReconciler::reconcile([$chargeC], [], [$localA, $localB]);

        $this->assertCount(1, $result['matched']);
        $this->assertSame('ch_C', $result['matched'][0]['charge']['id']);
        $this->assertCount(1, $result['unmatched_local']);
        // Never two matches to the same charge.
        $matchedChargeIds = array_map(static fn (array $pair): string => $pair['charge']['id'], $result['matched']);
        $this->assertCount(count($matchedChargeIds), array_unique($matchedChargeIds));
    }

    // -------------------------------------------------------------------------
    // 8. Equidistant ambiguity
    // -------------------------------------------------------------------------

    public function testEquidistantCandidatesLeaveLocalUnmatchedWithAmbiguityWarning(): void
    {
        $localM = $this->local('order', 903, 'order 75', 75.00, ['recognized_at' => '2026-07-10 12:00:00']);
        $chargeX = $this->charge('ch_X', '2026-07-08 12:00:00', 75.00); // 2 days before
        $chargeY = $this->charge('ch_Y', '2026-07-12 12:00:00', 75.00); // 2 days after, same distance

        $result = StripeReconciler::reconcile([$chargeX, $chargeY], [], [$localM]);

        $this->assertSame([], $result['matched']);
        $this->assertCount(1, $result['unmatched_local']);
        $this->assertCount(2, $result['unmatched_stripe']);

        $ambiguityWarnings = array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'Ambiguous')
        ));
        $this->assertCount(1, $ambiguityWarnings);
        $this->assertStringContainsString('ch_X', $ambiguityWarnings[0]);
        $this->assertStringContainsString('ch_Y', $ambiguityWarnings[0]);
    }

    // -------------------------------------------------------------------------
    // 9. Refund handling
    // -------------------------------------------------------------------------

    public function testRefundedChargeExcludedFromSettledAndListedInRefunds(): void
    {
        $chargeClean = $this->charge('ch_clean', '2026-03-01 00:00:00', 40.00, ['payment_intent' => 'pi_clean']);
        $localClean  = $this->local('order', 904, 'order clean', 40.00, [
            'recognized_at' => '2026-03-01 00:05:00', 'payment_intent_id' => 'pi_clean',
        ]);
        $chargeRefunded = $this->charge('ch_R', '2026-03-05 00:00:00', 60.00, [
            'amount_refunded' => 60.00, 'refunded' => true,
        ]);

        $result = StripeReconciler::reconcile([$chargeClean, $chargeRefunded], [], [$localClean]);

        // 40.00 (clean, matched) + (60.00 - 60.00) (refunded, net zero) = 40.00
        $this->assertSame(40.00, $result['totals']['stripe_settled']);

        $this->assertCount(1, $result['refunds']);
        $this->assertSame('ch_R', $result['refunds'][0]['charge']['id']);
        $this->assertSame(60.00, $result['refunds'][0]['amount_refunded']);

        $refundWarnings = array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'Refund') && str_contains($w, 'ch_R')
        ));
        $this->assertCount(1, $refundWarnings);
    }

    // -------------------------------------------------------------------------
    // 10. Dispute handling
    // -------------------------------------------------------------------------

    public function testDisputedChargeIsListedInDisputesWithWarning(): void
    {
        $chargeDisputed = $this->charge('ch_D', '2026-03-10 00:00:00', 30.00, ['disputed' => true]);

        $result = StripeReconciler::reconcile([$chargeDisputed], [], []);

        $this->assertCount(1, $result['disputes']);
        $this->assertSame('ch_D', $result['disputes'][0]['charge']['id']);

        $disputeWarnings = array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'ch_D') && str_contains($w, 'disputed')
        ));
        $this->assertCount(1, $disputeWarnings);
    }

    // -------------------------------------------------------------------------
    // 11. Unmatched Stripe charge warning
    // -------------------------------------------------------------------------

    public function testUnmatchedStripeChargeProducesAWarning(): void
    {
        $chargeOrphan = $this->charge('ch_orphan', '2026-04-01 00:00:00', 20.00);

        $result = StripeReconciler::reconcile([$chargeOrphan], [], []);

        $this->assertCount(1, $result['unmatched_stripe']);

        $orphanWarnings = array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'ch_orphan') && str_contains($w, 'Unmatched')
        ));
        $this->assertCount(1, $orphanWarnings);
    }

    // -------------------------------------------------------------------------
    // Stochastic / property tests
    // -------------------------------------------------------------------------

    /**
     * Generate a random mixed fixture: a random number of true
     * payment_intent-linked pairs (July dates), a random number of
     * unmatched-only charges (January dates), and a random number of
     * unmatched-only locals (December dates). The three date ranges are
     * kept far enough apart (different, non-adjacent months) that no
     * amount+date coincidence can cross groups regardless of the random
     * amounts drawn, so the *intended* pairing is always the only possible
     * payment_intent match and extras can never accidentally pair up.
     *
     * @return array{0: list<array>, 1: list<array>, 2: list<array>} charges, sessions, locals
     */
    private function randomMixedFixture(): array
    {
        $charges = [];
        $locals  = [];

        $pairCount = mt_rand(3, 6);
        for ($i = 0; $i < $pairCount; $i++) {
            $amount = round(mt_rand(2000, 20000) / 100, 2);
            $day    = mt_rand(1, 25);
            $created = sprintf('2026-07-%02d 12:00:00', $day);
            $pi     = "pi_pair_{$i}";

            $charges[] = $this->charge("ch_pair_{$i}", $created, $amount, ['payment_intent' => $pi]);
            $locals[]  = $this->local('quote', 1000 + $i, "pair quote {$i}", $amount, [
                'recognized_at' => $created, 'payment_intent_id' => $pi,
            ]);
        }

        $extraCharges = mt_rand(0, 3);
        for ($i = 0; $i < $extraCharges; $i++) {
            $amount = round(mt_rand(2000, 20000) / 100, 2);
            $day    = mt_rand(1, 25);
            $charges[] = $this->charge("ch_extra_{$i}", sprintf('2026-01-%02d 09:00:00', $day), $amount);
        }

        $extraLocals = mt_rand(0, 3);
        for ($i = 0; $i < $extraLocals; $i++) {
            $amount = round(mt_rand(2000, 20000) / 100, 2);
            $day    = mt_rand(1, 25);
            $locals[] = $this->local('order', 2000 + $i, "extra order {$i}", $amount, [
                'recognized_at' => sprintf('2026-12-%02d 09:00:00', $day),
            ]);
        }

        return [$charges, [], $locals];
    }

    /**
     * Law: no charge id appears in `matched` more than once, and no local
     * `label` appears more than once — every match is genuinely one-to-one.
     */
    public function testStochasticMatchesAreOneToOne(): void
    {
        mt_srand(20260727);

        for ($iteration = 0; $iteration < 100; $iteration++) {
            [$charges, $sessions, $locals] = $this->randomMixedFixture();

            $result = StripeReconciler::reconcile($charges, $sessions, $locals);

            $chargeIds = array_map(static fn (array $pair): string => $pair['charge']['id'], $result['matched']);
            $labels    = array_map(static fn (array $pair): string => $pair['local']['label'], $result['matched']);

            $this->assertSame(
                count($chargeIds),
                count(array_unique($chargeIds)),
                'law: no charge is matched more than once'
            );
            $this->assertSame(
                count($labels),
                count(array_unique($labels)),
                'law: no local is matched more than once'
            );
        }
    }

    /**
     * Law: `count(matched) + count(unmatched_local) === count(locals)`, and
     * `count(matched) + count(unmatched_stripe) === count(charges)` — every
     * input is accounted for exactly once, on exactly one side.
     */
    public function testStochasticConservationOfInputCounts(): void
    {
        mt_srand(20260727);

        for ($iteration = 0; $iteration < 100; $iteration++) {
            [$charges, $sessions, $locals] = $this->randomMixedFixture();

            $result = StripeReconciler::reconcile($charges, $sessions, $locals);

            $this->assertSame(
                count($locals),
                count($result['matched']) + count($result['unmatched_local']),
                'law: matched + unmatched_local == count(locals)'
            );
            $this->assertSame(
                count($charges),
                count($result['matched']) + count($result['unmatched_stripe']),
                'law: matched + unmatched_stripe == count(charges)'
            );
        }
    }

    /**
     * Law: shuffling the input charges and locals arrays does not change
     * *which* local matched *which* charge — only the input order, and
     * therefore iteration order, differs.
     */
    public function testStochasticOrderInvariance(): void
    {
        mt_srand(20260727);

        for ($iteration = 0; $iteration < 100; $iteration++) {
            [$charges, $sessions, $locals] = $this->randomMixedFixture();

            $resultA = StripeReconciler::reconcile($charges, $sessions, $locals);

            $shuffledCharges = $charges;
            $shuffledLocals  = $locals;
            shuffle($shuffledCharges);
            shuffle($shuffledLocals);

            $resultB = StripeReconciler::reconcile($shuffledCharges, $sessions, $shuffledLocals);

            $pairsOf = static function (array $result): array {
                $pairs = [];
                foreach ($result['matched'] as $pair) {
                    $pairs[$pair['local']['label']] = $pair['charge']['id'];
                }
                ksort($pairs);

                return $pairs;
            };

            $this->assertSame($pairsOf($resultA), $pairsOf($resultB), 'law: shuffling input does not change the matched set');
        }
    }

    /**
     * Law: when every local carries a correct `payment_intent_id`, every
     * match resolves via rule 1 — no `amount_and_date` heuristic match ever
     * fires, since identity matching always wins when it's available.
     */
    public function testStochasticIdentityMatchingDominatesWhenAvailable(): void
    {
        mt_srand(20260727);

        for ($iteration = 0; $iteration < 100; $iteration++) {
            $pairCount = mt_rand(2, 6);
            $charges   = [];
            $locals    = [];

            for ($i = 0; $i < $pairCount; $i++) {
                $amount  = round(mt_rand(2000, 20000) / 100, 2);
                $day     = mt_rand(1, 25);
                $created = sprintf('2026-07-%02d 12:00:00', $day);
                $pi      = "pi_dom_{$iteration}_{$i}";

                $charges[] = $this->charge("ch_dom_{$iteration}_{$i}", $created, $amount, ['payment_intent' => $pi]);
                $locals[]  = $this->local('quote', 3000 + $i, "dom quote {$i}", $amount, [
                    'recognized_at' => $created, 'payment_intent_id' => $pi,
                ]);
            }

            $result = StripeReconciler::reconcile($charges, [], $locals);

            $this->assertCount($pairCount, $result['matched']);

            foreach ($result['matched'] as $pair) {
                $this->assertSame('payment_intent', $pair['method'], 'law: identity matching wins when a correct id is stored');
            }
        }
    }
}
