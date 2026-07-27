# Stripe Payment Reconciliation

A repeatable check of "which Stripe payments correspond to which
quotes/orders, and what doesn't line up" — run on demand, not on a
schedule. It exists because the weekly sales report (`docs/weekly-sales-report.md`)
deliberately reports *intent* (what `quotes`/`orders` say was charged), never
*settled cash* (what Stripe actually took, net of refunds); this tool is the
other half, for when someone needs to know whether the two agree.

This document is written for whoever has to run this in six months and has
forgotten everything. Every command below is meant to be copy-pasted, not
paraphrased.

## 1. Running it

```bash
php bin/stripe-reconcile.php [--since=YYYY-MM-DD] [--out=DIR] [--csv] [--dry-run|-n] [--help|-h]
```

| Flag                 | Meaning                                                                                                   |
|-----------------------|-------------------------------------------------------------------------------------------------------------|
| `--since=YYYY-MM-DD`  | Only include Stripe charges/sessions and local quotes/orders recognized on or after this UTC date.          |
| `--out=DIR`           | Output directory for the CSV (with `--csv`). Defaults to `SALES_REPORT_DIR`, or `~/private/flowers-sales/` — the same directory the weekly sales report uses. Refused if it resolves inside the project (see §4 of the weekly sales report doc: the project root is the public docroot). |
| `--csv`               | Also write `stripe-reconciliation-YYYY-MM-DD.csv` to `--out`.                                                |
| `--dry-run`, `-n`     | Print the terminal summary only; write nothing to disk.                                                      |
| `--help`, `-h`        | Show usage and exit.                                                                                         |

Exit codes: `0` clean run (no warnings), `1` reconciliation produced with at
least one warning, `2` usage/configuration error (nothing was read from
Stripe or the database).

The script is read-only in both directions: it uses
`App\Core\Database::ro()` for the database and only Stripe's `charges.list`
and `checkout.sessions.list` endpoints — nothing is ever written back to
either system.

## 2. The matching strategy

All matching logic lives in `App\Support\StripeReconciler::reconcile()`, a
pure, DB-free, network-free class — `bin/stripe-reconcile.php`'s only job is
fetching each side and rendering the result, so the matching rules
themselves are fully unit-tested against a real, verified fixture (see
`tests/Support/StripeReconcilerTest.php`).

Every charge is matched to at most one local record (a quote or a shop
order), and vice versa, using three rules tried **in strict priority order**,
each only considering pairs still unmatched by the rule before it:

1. **`payment_intent`** — the local record's stored `payment_intent_id`
   equals the charge's `payment_intent`. This is the strongest signal: a
   PaymentIntent belongs to exactly one charge.
2. **`session`** — the local record's stored `session_id` names a Checkout
   Session whose `payment_intent` equals the charge's `payment_intent`.
   Covers a quote/order that got a session id written but never a
   PaymentIntent id — possible because `stripe_checkout_session_id` is
   written when checkout *starts* and `stripe_payment_intent_id` only when
   the webhook confirming payment lands. **No live record needs this rule
   today** (as of the 2026-07-27 run every record either stores a
   PaymentIntent id or stores neither id), so it is covered by a constructed
   test case rather than a real one.
3. **`amount_and_date`** — **last resort**: the local's expected dollar
   amount equals the charge's amount within half a cent, and the charge's
   date falls within `StripeReconciler::MATCH_WINDOW_DAYS` (4 days, by
   default) of the local's `recognized_at`. If two candidate charges are
   equally close in time, the match is left **unmatched with a warning**
   rather than guessed — a wrong silent match would be worse than an honest
   gap.

**Why `amount_and_date` exists at all:** a payment taken outside the site's
own Stripe Checkout flow — the owner's iPhone card-reader app
(`com.pocketvendor.payment`), a Stripe Dashboard-initiated charge, a payment
link — never writes a session id or PaymentIntent id back onto the quote or
order row. Identity matching (rules 1 and 2) structurally cannot reach that
payment; amount and date are the only signal left. Because it is a
heuristic, not an identity match, **every `amount_and_date` match is also
recorded as a warning** — it should be treated as "probably this, worth a
glance," never as certain.

A match whose amounts disagree by more than half a cent is not treated as a
non-match: it is real information (the same payment, recorded with different
amounts on each side) and appears in **both** the matched table and the
amount-mismatch section (see quote 17 in §5).

## 3. Reading the output

The terminal output has, in order:

- **`MATCHED (n)`** — one row per matched pair: the local record's label,
  customer name, expected amount, the Stripe charge's amount, the signed
  delta (`local.expected − charge.amount`; positive means the database
  expected more than Stripe actually took), which rule matched them, and the
  charge id.
- **`UNMATCHED STRIPE CHARGES (n)`** — money Stripe settled with no local
  quote/order record explaining it. Every entry here is worth a look: it's
  either a payment for something outside `quotes`/`orders` entirely (a
  one-off Dashboard charge, a test charge), or a local record that's missing
  or mis-dated.
- **`UNMATCHED LOCAL RECORDS (n)`** — a quote/order this codebase believes
  was paid, with no Stripe charge found to explain it. This can mean the
  payment was recorded locally without ever actually being charged (a manual
  status change), or it was paid through a channel this reconciliation
  doesn't see (cash, check).
- **`AMOUNT MISMATCHES (n)`** — matched pairs whose amounts disagree by more
  than half a cent. Always a subset of `MATCHED`, surfaced again here so a
  disagreement isn't buried in a long matched table.
- **`REFUNDS (n)`** — every charge with a nonzero `amount_refunded`, whether
  or not it was otherwise matched. `TOTALS.stripe_settled` is already net of
  refunds, so a fully-refunded charge contributes `$0.00` there.
- **`DISPUTES (n)`** — every charge Stripe flags as disputed, for the same
  reason: surfaced regardless of match status.
- **`TOTALS`** — `Stripe settled (net of refunds)`, `Local expected` (the sum
  of every local record's expected amount, matched or not), and their signed
  `Delta`. This is the headline "do the two sides agree" figure.
- **`WARNINGS (n)`** — always printed, even when empty (`(none)`), so a
  clean run is never mistakable for one that wasn't checked. Collects every
  heuristic match, every ambiguous `amount_and_date` tie, every refund, every
  dispute, and every unexplained charge or local record.

`--csv` writes the same matched/unmatched-Stripe/unmatched-local data as
rows in `stripe-reconciliation-YYYY-MM-DD.csv`, for pasting into a
spreadsheet or filing alongside the weekly sales report's own CSVs.

## 4. Timezone handling

Same convention as the weekly sales report, for the same reason: the
database connection is pinned to

```sql
SET SESSION time_zone = '+00:00'
```

before any read, because named timezones require the `mysql.time_zone*`
tables, which are not populated on this shared host. This makes every
`TIMESTAMP` read back as a true UTC instant, which
`StripeReconciler`'s `amount_and_date` date-window matching requires — the
live MySQL server's `SYSTEM` zone resolves to US Eastern, a skew large
enough to push a charge outside the match window incorrectly.

Stripe's own `created` timestamps are always UTC Unix time; `StripeService`
converts them to the same `'Y-m-d H:i:s'` UTC string format via `gmdate()`
before they ever reach `StripeReconciler`, so both sides of every comparison
are on the same clock.

## 5. Known findings (2026-07-27 live reconciliation)

The first full run against the live Stripe account, covering every payment
since the first sale, produced 11 matches (9 via `payment_intent`, 2 via
`amount_and_date`) and zero unmatched records on either side, with zero
refunds and zero disputes across the whole account:

```
Stripe settled (net of refunds): $1,168.53
Local expected:                  $1,167.35
Delta (settled - expected):      $1.18
```

The entire $1.18 is quote 17, below. Two data issues are worth recording:

- **Quote 17's stored `tax_amount` ($8.18) and `deposit_amount` are stale.**
  They were computed against a $96.00 subtotal that was later edited to
  $110.00 directly in the database — `App\Models\Quote::update()` normally
  recomputes `subtotal`/`tax_amount`/`deposit_amount` together via
  `App\Support\QuotePricing::compute()`, so a row where they disagree with
  the current subtotal was almost certainly hand-edited outside that path.
  Stripe still charged the *correct* $119.36, because
  `StripeService::createQuoteCheckoutSession()` attaches
  `STRIPE_TAX_RATE_ID` and lets Stripe compute tax from the actual line
  items at checkout time, independent of the stored `tax_amount` column. The
  money collected was right; only the stored column — which the weekly
  sales report reads — is wrong, understating quote 17 by **$1.18**. This is
  exactly the kind of drift `App\Support\StripeReconciler`'s
  `amount_mismatches` section exists to catch.

- **Quotes 4 and 5 have NULL in both `stripe_checkout_session_id` and
  `stripe_payment_intent_id`,** so no identity rule can reach them — only
  `amount_and_date` could, and did, each with its heuristic warning. Quote 4
  was taken through a third-party iPhone card-reader app
  (`com.pocketvendor.payment`) rather than the site's own Checkout, which is
  why nothing was ever written back. Expect any future off-site payment (the
  same card-reader app, a Stripe Dashboard charge, a payment link) to
  reconcile the same way. Two heuristic warnings on a clean run is therefore
  the current expected steady state, and the script exits `1` because of
  them — not because anything is wrong.

  A caveat worth knowing: both were matched on amount alone within a 4-day
  window. Quote 4's $81.39 is also the amount of cancelled DEMO quote 2, so
  if a future duplicate-amount payment lands in the same window the reconciler
  will report an ambiguity rather than pick one. That is deliberate.

@see docs/weekly-sales-report.md — §9 "Intent, not settled cash" and "No
refund handling" name exactly the gaps this tool fills.
