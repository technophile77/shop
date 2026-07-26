<?php

declare(strict_types=1);

/**
 * Column-header mapping for DoorDash Merchant Portal financial CSV exports,
 * consumed by App\Support\DoorDashSalesCsv::parse().
 *
 * The export comes from the DoorDash Merchant Portal: Reports → Create report
 * → Financial → Transaction details → CSV. DoorDash's column headers are not
 * publicly documented and change over time without notice (they restructured
 * the financial report columns in April 2025, for one) — this file exists so
 * a header rename is a one-line config edit here rather than a code change in
 * DoorDashSalesCsv.
 *
 * The first run against a genuinely new export format is *expected* to throw:
 * DoorDashSalesCsv::parse() fails loudly, rather than silently reading a
 * missing column as zero and understating revenue, and its exception message
 * lists every header actually found in the file. Paste the new header text
 * into the relevant list below and re-run.
 *
 * Top-level keys:
 *
 *   - fields           array<string, list<string>>  Canonical field name =>
 *     accepted header spellings, tried in order, matched case-insensitively
 *     with whitespace collapsed/trimmed. Every field listed here is REQUIRED
 *     unless it also appears in `optional_fields`; a required field with no
 *     matching header aborts the whole import.
 *
 *   - fee_columns      list<string>  Header spellings for columns that are
 *     summed into the single `fees` figure on each parsed row. DoorDash
 *     reports every one of these as a negative number (money paid out of the
 *     merchant's payout); the importer stores the positive magnitude, per the
 *     accounting identity in App\Support\SalesChannel.
 *
 *   - ignored_columns  list<string>  Headers known to exist in the export and
 *     deliberately excluded from money figures. Listed explicitly so they
 *     don't trip the "unmapped headers" warning, which exists to catch real
 *     surprises (an actual new fee column) rather than columns we've already
 *     reviewed and decided not to use.
 *
 *     Note on `Tip`: a customer tip passes straight through to the Dasher and
 *     is never merchant flower revenue, so it is intentionally ignored rather
 *     than folded into any money figure — including it anywhere would inflate
 *     recognized sales with money the business never receives.
 *
 *   - optional_fields  list<string>  Canonical field names from `fields` that
 *     may be absent from an export without aborting the import (their value
 *     defaults to 0.00 when missing).
 */
return [
    'fields' => [
        'order_id'    => ['Order ID', 'Delivery ID', 'Transaction ID', 'DoorDash Order ID'],
        'occurred_at' => ['Timestamp local date', 'Order date', 'Transaction date', 'Date'],
        'merchandise' => ['Subtotal', 'Food subtotal', 'Item subtotal'],
        'delivery'    => ['Delivery fee', 'Fulfillment fee'],
        'tax'         => ['Tax subtotal', 'Tax', 'Taxes'],
        'net_payout'  => ['Net total', 'Net payout', 'Payout', 'Net'],
    ],

    // Every column here is summed into the single `fees` figure. Fees arrive
    // from DoorDash as negative numbers; the importer stores a positive
    // magnitude.
    'fee_columns' => [
        'Commission',
        'Marketing fees',
        'Marketing fee',
        'Error charges',
        'Adjustments',
        'Merchant tablet fee',
        'Other fees',
    ],

    // Columns known to exist and deliberately ignored — keeps them out of the
    // "unmapped headers" warning so real surprises stand out. `Tip` is here
    // because a customer tip passes through to the Dasher and is not
    // merchant flower revenue.
    'ignored_columns' => [
        'Store ID',
        'Store name',
        'Business name',
        'Timezone',
        'Order status',
        'Payout ID',
        'Currency',
        'Tip',
        'Customer name',
    ],

    'optional_fields' => ['delivery', 'tax'],
];
