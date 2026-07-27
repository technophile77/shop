# Weekly Sales Report

A repeatable report that combines web-store sales (MySQL) with DoorDash sales
(a manually downloaded CSV) into one row per ISO week, from the first sale
(2026-05-30) through the current week. It keeps **flower sales separate from
delivery fees, sales tax, and DoorDash commission**, so the owner can see what
was actually earned from flowers versus what was collected and passed through
or withheld.

This document is written for whoever has to run this in six months and has
forgotten everything. Every command below is meant to be copy-pasted, not
paraphrased.

## 1. The accounting identity

Every channel row, and every aggregated weekly row, closes on:

```
merchandise + delivery + tax − channel_fees = recognized_sales
```

`channel_fees` is a positive magnitude that gets *subtracted*. It is only ever
non-zero for DoorDash — commission and marketing charges withheld from the
payout. See `App\Support\SalesChannel::recognize()`.

| Channel  | merchandise                          | delivery                                                  | tax                  | channel_fees                  | recognized_sales                     |
|----------|---------------------------------------|-------------------------------------------------------------|----------------------|--------------------------------|----------------------------------------|
| Shop     | `orders.subtotal`                     | `orders.delivery_fee`                                        | `orders.tax_amount`  | 0                              | `orders.total`                         |
| Quote    | classified merchandise lines          | `quotes.delivery_fee` + classified delivery lines             | `quotes.tax_amount`  | 0                              | subtotal + tax + delivery              |
| DoorDash | CSV subtotal                          | CSV delivery fee                                              | CSV tax               | commission + marketing + other | net payout                             |

For quotes, "classified" means every `items_json` line has been run through
`App\Support\SalesLineClassifier::classify()` and bucketed into merchandise,
delivery, or another fee — see §6 for why this is a heuristic, not a lookup.
The quote row's `other_fee` bucket (setup fees, rush charges, etc.) is folded
into the merchandise side of the identity when computing `recognized_sales`,
because it is money the customer paid rather than money withheld from the
business; it is still reported as its own figure in the audit output. See
`App\Support\QuoteSalesBreakdown`.

`deposit_collected` is tracked on quote rows but sits **outside** this
identity — it is cash flow (how much of the total has actually been
collected so far), not revenue, and is never summed into `recognized_sales`.

## 2. Two recognition conventions

These are decisions, made for specific reasons — not oversights:

- **A quote books its full value in the week its deposit was confirmed**
  (`quotes.deposit_confirmed_at`), not the week the event happened or the
  week the balance was paid. This is forced by the schema: there is no
  balance-payment date recorded anywhere for a quote, so the deposit's
  timestamp is the only date that exists to hang the whole quote on.

- **A DoorDash order's recognized sale is the net payout**, with commission
  broken out as its own `fees` category rather than being stacked into (and
  hiding inside) `recognized_sales`. This makes the DoorDash row look
  structurally different from a shop or quote row, but it's the only figure
  that reconciles to money DoorDash actually deposits.

## 3. Running it

```bash
php bin/sales-report.php [--doordash=FILE.csv] [--out=DIR] [--since=YYYY-MM-DD] [--html] [--dry-run]
```

| Flag              | Meaning                                                                                          |
|-------------------|---------------------------------------------------------------------------------------------------|
| `--doordash=FILE` | Path to the DoorDash Transaction-details CSV to import (see §5). Omit to run web-store only.      |
| `--out=DIR`       | Output directory for the CSVs (and HTML, with `--html`). Defaults to `~/private/flowers-sales/`.  |
| `--since=YYYY-MM-DD` | Override the report's start week (defaults to the first sale, 2026-05-30).                     |
| `--html`          | Also write a shareable HTML rendering of the report, alongside the CSVs.                          |
| `--dry-run`       | Print the terminal table only; write nothing to disk.                                             |

**A run with no `--doordash` still succeeds, but it is web-store only.** Say
so prominently in the terminal output every time — a web-store-only report
must never be circulated as a complete picture of sales, since DoorDash is a
real, separate revenue channel that simply isn't in the number yet.

## 4. Where output goes, and why it must not move

Default output directory: `~/private/flowers-sales/`, with the DoorDash
exports themselves archived under `~/private/flowers-sales/doordash/`.

This is a real security constraint, not a tidiness preference. This
project's docroot **is the project root** — `index.php` and `.htaccess` live
at the top level of `~/public_html/flowers.cresswell.org`, not under a
further `public/` subfolder that Apache alone serves. The `.htaccess` in
this project contains:

```
RewriteCond %{REQUEST_FILENAME} -f [OR]
```

which tells Apache to serve any file that exists on disk directly, bypassing
`index.php` entirely. `Options -Indexes` (set here and account-wide on
pair.com) only stops a directory from being *listed* — it does nothing to
stop a file from being *fetched* once its name is known or guessed. A CSV
full of customer names, order amounts, and DoorDash order IDs written
anywhere inside this project would therefore be downloadable at a guessable
URL like `https://flowers.cresswell.org/reports/2026-W29.csv`, with no
authentication at all.

Verify after every deploy that nothing has landed in the webroot:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://flowers.cresswell.org/reports/
```

Expect **404**. Also confirm there is no CSV anywhere under
`~/public_html/` — the report output belongs exclusively under
`~/private/flowers-sales/`, which sits outside `public_html` and is not
web-accessible at all.

## 5. Downloading the DoorDash report

This is the step most likely to be forgotten or done wrong, since it's a
manual, browser-driven export with no code behind it.

> Merchant Portal → **Reports** (left menu) → **Create report** → report type
> **Financial** → include **Transaction details** → date range starting
> **2026-05-01** → export **CSV**.

**Use Transaction details, not the Sales report.** Only Transaction details
itemises delivery fees, taxes, and tips **per order** — that per-order detail
is what makes the flowers/delivery/tax split in §1 possible at all. DoorDash
itself warns that the Sales report should not be used to reconcile fees; it's
a coarser, aggregated view that can't be un-mixed back into merchandise vs.
delivery vs. tax.

Notes on the export itself:

- The date picker covers the last two years, so the entire sales history
  (since 2026-05-30) fits in a single export — there's no need to stitch
  multiple date ranges together.
- Generated reports are **downloadable for 7 days only**. Save every export
  into `~/private/flowers-sales/doordash/` immediately — that archived copy
  *is* the permanent record; DoorDash will not keep it available past the
  7-day window.
- **Financials → Payouts** in the Merchant Portal is the authority for
  reconciling against the bank deposit. Transaction details is what gets
  imported here specifically because it carries the merchandise/delivery/tax
  split that Payouts doesn't.

### The expected first-run failure

DoorDash's column headers are undocumented and change over time without
notice (they restructured the financial report columns in April 2025, for
one example). **The first time a newly-shaped export is imported, expect the
script to fail loudly**, listing every field it couldn't find a header for
and every header it actually found in the file. This is the intended
behaviour, not a bug: `App\Support\DoorDashSalesCsv::parse()` deliberately
refuses to guess, because silently treating a renamed column as `$0.00` would
understate revenue without any indication that anything was wrong.

The fix is a one-line config edit to `config/doordash-report-map.php` — add
the new header spelling to the relevant field's list. Real excerpt of its
structure:

```php
return [
    'fields' => [
        'order_id'    => ['Order ID', 'Delivery ID', 'Transaction ID', 'DoorDash Order ID'],
        'occurred_at' => ['Timestamp local date', 'Order date', 'Transaction date', 'Date'],
        'merchandise' => ['Subtotal', 'Food subtotal', 'Item subtotal'],
        'delivery'    => ['Delivery fee', 'Fulfillment fee'],
        'tax'         => ['Tax subtotal', 'Tax', 'Taxes'],
        'net_payout'  => ['Net total', 'Net payout', 'Payout', 'Net'],
    ],

    'fee_columns' => [
        'Commission',
        'Marketing fees',
        'Marketing fee',
        'Error charges',
        'Adjustments',
        'Merchant tablet fee',
        'Other fees',
    ],

    'ignored_columns' => [
        'Store ID', 'Store name', 'Business name', 'Timezone',
        'Order status', 'Payout ID', 'Currency', 'Tip', 'Customer name',
    ],

    'optional_fields' => ['delivery', 'tax'],
];
```

Add the new spelling to the appropriate list (e.g. a new merchandise-column
name goes under `fields.merchandise`) and re-run the import. Nothing else
needs to change.

## 6. The warnings / audit section

The report prints a non-fatal warnings/audit section every time it runs —
never only on failure — because the report is expected to report its own
uncertainty rather than hide it behind a clean-looking total. It surfaces:

- **Every line item the classifier called "delivery"** — with its id,
  description, and dollar amount — so the text-matching heuristic in
  `App\Support\SalesLineClassifier` can be eyeballed against real line items
  (e.g. confirming "Rose Delivery Bouquet" wasn't misread as a fee).
- **Quotes where a delivery (or other-fee) line was taxed** — pre-
  migration-018 behaviour, from before `quotes.delivery_fee` existed as its
  own untaxed column; see §9.
- **Quotes with both a non-zero `delivery_fee` *and* a classified delivery
  line** — a double-count risk, since both would otherwise be added into the
  delivery figure.
- **Quotes in a revenue status (`deposit_confirmed`/`completed`) with a
  `NULL deposit_confirmed_at`**, and **quotes with a deposit timestamp but a
  `cancelled` status** — the first is a data-integrity gap (nothing to
  bucket the quote's week by), the second is a possible refund that was
  never reflected back in the timestamp.
- **DoorDash headers detected in the file but left unmapped** — anything not
  claimed by `fields`, `fee_columns`, or `ignored_columns` in the map file,
  so a genuinely new column doesn't sail through unnoticed just because it
  wasn't required.
- **Any row failing the closing identity** in §1 — a component breakdown
  that doesn't sum back to its own `recognized_sales`.

None of these stop the report from producing numbers. They are printed
alongside the totals every run so a human can decide whether a given week's
figures need a second look.

Each warning is listed **once** per occurrence in each output. The companion
`-warnings.csv` is the only output that also says *which week* a warning
belongs to: warnings raised by a sale carry that sale's `week_start`, and
warnings with no week to attach to — an orphaned quote, a DoorDash header
problem, a `--through` range notice — carry an empty `week_start`. The
terminal summary and the HTML report list the same warnings without the week
column, since every warning names its own `source_id` in its text.

## 7. Deployment

Follow `CLAUDE.md`'s deployment conventions exactly:

1. **Upload** the new/changed files via SFTP to
   `~/public_html/flowers.cresswell.org` (per `CLAUDE.md` / the shared
   pair-hosting doc — plain SFTP, one call per file, never `ssh_deploy`).

2. Regenerate the autoloader classmap:

   ```bash
   cd ~/public_html/flowers.cresswell.org && composer dump-autoload --optimize
   ```

   `composer.json` sets `"optimize-autoloader": true`, which bakes a static
   classmap at `vendor/composer/autoload_classmap.php`. Every class this
   report adds under `src/Support/` (`WeeklySalesAggregator`,
   `SalesReportCsv`, `SalesReportHtml`, etc.) is invisible to the autoloader
   until that classmap is rebuilt — and a missing class produces a **silent
   HTTP 500 with an empty body**, since pair.com's Apache swallows PHP error
   output on 500 responses.

3. Verify the classmap actually picked up the new class:

   ```bash
   grep "WeeklySalesAggregator" vendor/composer/autoload_classmap.php
   ```

4. Apply the schema migration this report depends on:

   ```bash
   php bin/migrate.php
   ```

   (`migrations/019_add_orders_paid_at.sql` adds `orders.paid_at`, which the
   report reads via `COALESCE(paid_at, created_at)` so it works whether or
   not the column has been backfilled yet.)

5. Create the private output directory (outside the webroot — see §4):

   ```bash
   mkdir -p ~/private/flowers-sales/doordash
   ```

## 8. Timezone handling

The report pins its MySQL session to a fixed numeric offset before reading
any timestamp:

```sql
SET SESSION time_zone = '+00:00'
```

**A numeric offset is used instead of a named zone** (`'UTC'`) because named
timezones require the `mysql.time_zone*` tables to be populated on the
server, and they are not populated on pair's shared MySQL — `CONVERT_TZ()`
with a named zone silently returns `NULL` there rather than erroring, which
would quietly blank out every timestamp.

Once every `TIMESTAMP` comes back as a true UTC instant, `App\Support\WeekBucket`
is the single place that converts it into `America/Chicago` wall-clock time
before bucketing it into a week. This conversion is not cosmetic: the live
MySQL server's `@@session.time_zone` is `SYSTEM`, which resolves to **US
Eastern**, while the app itself runs in `America/Chicago` — a full hour of
skew. Left uncorrected, a sale made late in the evening business-time could
be bucketed into the wrong ISO week entirely.

Weeks are **ISO-8601, Monday-start** (`WeekBucket::isoWeekKey()` uses PHP's
`o`/`W` format specifiers, not `Y`, specifically so a week spanning a
year boundary — e.g. `2026-W53` — is never mislabelled into the wrong
calendar year).

## 9. Documented limitations

These are properties of the underlying data and schema, not bugs to fix as
part of this report:

- **Intent, not settled cash.** `App\Controllers\StripeController` reads
  Stripe's `amount_total` off the checkout session (for the owner-email/SMS
  notification and the ad-platform conversion event) but never persists it.
  The report therefore reflects `orders.total` and the quote's computed
  total — what was *intended* to be charged — not necessarily the exact
  amount Stripe settled.

- **No refund handling.** There is no `charge.refunded` or dispute webhook
  anywhere in this codebase, and `payment_status = 'paid'` is never reversed
  once set. Refunds and disputes therefore do not reduce reported sales at
  all — cross-check the Stripe dashboard directly for those.

- **Quote balances are invisible.** Only the deposit's confirmation
  timestamp exists in the schema (`quotes.deposit_confirmed_at`); there is
  no column recording when — or whether — the remaining balance was paid.
  This is exactly why §2 books a quote's full value at deposit confirmation
  rather than trying to split it across two dates that don't both exist.

- **Pre-migration-018 quotes taxed delivery.** Before `quotes.delivery_fee`
  existed, delivery was a hand-typed line inside `items_json` and
  `quotes.subtotal` (and therefore `quotes.tax_amount`) included it. For
  those older quotes, the report's tax column includes tax that was actually
  charged on delivery, even though delivery itself is reported as its own,
  separate figure — see the taxed-delivery warning in §6.

- **The admin dashboard disagrees with this report by design — and that's
  worth its own follow-up issue, not a fix folded into this one.**
  `App\Controllers\Admin\DashboardController::index()` computes "revenue
  this month" as `SUM(subtotal)` over quotes in `deposit_confirmed`/
  `completed` status, and `App\Models\Customer::refreshLifetimeSpend()` does
  the same per-customer. Both drop tax and delivery entirely and only look
  at quotes (never shop orders or DoorDash), so neither figure will ever
  match this report's `recognized_sales` — that's expected, not a
  discrepancy to chase down here.

## 10. Worked verification checklist

Known live figures as of this report's construction, to sanity-check any
future run against:

- **2 paid shop orders**, both in 2026-07, totalling **$145.65**
  (subtotal 125.00 + delivery 10.00 + tax 10.65).
- **Quotes by `deposit_confirmed_at`:**
  - 2026-05: 1 quote — subtotal 75.00 / tax 6.39
  - 2026-06: 4 quotes — subtotal 360.00 / tax 30.66
  - 2026-07: 5 quotes — subtotal 582.60 / tax 48.44
- **Grand total recognized (web store only): $1,248.74.**

The sharpest single check: **the delivery column must be non-zero.** Every
quote currently has `delivery_fee = 0.00` in its own column, yet quote 19
carries a $15 line classified as delivery inside `items_json`. If a report
run shows a delivery column of `$0.00`, the line classifier is not firing —
treat that as a broken report, not a quiet zero week.
