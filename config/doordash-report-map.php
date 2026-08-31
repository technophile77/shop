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
 *     summed into the single `fees` figure on each parsed row. DoorDash signs
 *     these from the merchant's point of view — negative when withheld from
 *     the payout, positive when paid back — and the importer negates them, so
 *     `fees` is the amount App\Support\SalesChannel::recognize() subtracts and
 *     goes negative when DoorDash owes the merchant money.
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
        'tax'         => ['Subtotal tax passed to merchant', 'Tax subtotal', 'Tax', 'Taxes'],
        'net_payout'  => ['Net total', 'Net payout', 'Payout', 'Net'],
    ],

    // Every column here is summed into the single `fees` figure, negated as it
    // is read: DoorDash signs these from the merchant's point of view, so a
    // withheld fee is negative and money paid back to the merchant (a
    // marketing credit, an Adjustment) is positive.
    //
    // The marketing block has to be taken as a whole. A discount funded by
    // DoorDash appears as a negative "customer discounts … (funded by
    // DoorDash)" and an equal, positive "DoorDash marketing credit"; the same
    // pairing exists for third-party-funded discounts. Including one side
    // without the other would overstate fees by the full discount, so every
    // member of the block is listed even though the pairs normally cancel to
    // zero. Only the "funded by you" line is a real cost to the business.
    'fee_columns' => [
        'Commission',
        'Marketing fees | (including any applicable taxes)',
        'Marketing fees',
        'Marketing fee',
        'Customer discounts from marketing | (funded by you)',
        'Customer discounts from marketing | (funded by DoorDash)',
        'Customer discounts from marketing | (funded by a third-party)',
        'DoorDash marketing credit',
        'Third-party contribution',
        'Error charges',
        'Adjustments',
        'Merchant tablet fee',
        'Other fees',
    ],

    // Columns known to exist and deliberately ignored — keeps them out of the
    // "unmapped headers" warning so real surprises stand out. `Tip` is here
    // because a customer tip passes through to the Dasher and is not
    // merchant flower revenue.
    //
    // The tax columns need care. `Subtotal tax passed to merchant` is the only
    // tax the business actually receives and is mapped to `tax` above. On a
    // Marketplace order DoorDash is the marketplace facilitator and remits
    // sales tax itself, so that column reads 0.00 and
    // `Subtotal tax remitted by DoorDash to tax authorities` carries the tax
    // instead — that money never reaches the business's bank account and must
    // stay out of the report's tax column, or the report would claim tax
    // revenue the business never saw and can't be liable for.
    //
    // `Pre-adjusted subtotal`/`Pre-adjusted tax subtotal` are the before-
    // adjustment values of columns already counted, and `Subtotal for tax` is
    // the taxable base, not a payment. Counting any of them would double-count.
    'ignored_columns' => [
        'Timestamp UTC time',
        'Timestamp UTC date',
        'Timestamp local time',
        'Order received local time',
        'Order pickup local time',
        'Payout time',
        'Payout date',
        'Business ID',
        'Business name',
        'Store ID',
        'Store name',
        'Merchant store ID',
        'Transaction type',
        'Delivery UUID',
        'DoorDash transaction ID',
        'Merchant delivery ID',
        'POS order ID',
        'Channel',
        'Description',
        'Final order status',
        'Order status',
        'Currency',
        'Timezone',
        'Payout ID',
        'Pre-adjusted subtotal',
        'Pre-adjusted tax subtotal',
        'Subtotal for tax',
        'Subtotal tax remitted by DoorDash to tax authorities',
        'Tip',
        'Customer name',
    ],

    'optional_fields' => ['delivery', 'tax'],
];
