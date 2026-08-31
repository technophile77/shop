<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free splitter that turns one `quotes` table row into a
 * merchandise/delivery/other-fee/tax breakdown for the weekly sales report.
 *
 * `quotes.delivery_fee` (added by `migrations/018_add_quote_delivery_fee.sql`)
 * is `0.00` on every quote created before that migration — for those quotes,
 * delivery (and occasionally other fees) is a hand-typed line inside
 * `quotes.items_json`, mixed in with the flowers, and `quotes.subtotal`
 * includes it. This class classifies every line with
 * {@see \App\Support\SalesLineClassifier::classify()}, sums the buckets, and
 * folds in the `delivery_fee` column so both eras of quote produce the same
 * shape. Because the line classification is a text heuristic, every line is
 * echoed back in the `lines` output with its bucket and reason so a human can
 * audit the split, and anything that looks off (a taxed fee line, a possible
 * double-counted delivery, a partition that doesn't reconcile to the stored
 * subtotal, invalid JSON) is surfaced in `warnings` rather than silently
 * swallowed.
 *
 * `other_fee` is folded into the merchandise argument when deriving
 * `recognized_sales` via {@see \App\Support\SalesChannel::recognize()}, which
 * takes only four arguments (merchandise, delivery, tax, fees) and reserves
 * its `fees` slot for a withheld amount like DoorDash commission — a
 * quote-line fee is money the customer paid, not money withheld from the
 * business, so it belongs on the "revenue" side of the identity rather than
 * being subtracted. Concretely:
 *
 *   recognized_sales = SalesChannel::recognize($merchandise + $otherFee, $delivery, $tax, 0.0)
 *
 * which keeps the report-level identity
 * `merchandise + delivery + other_fee + tax = recognized_sales` true, while
 * `other_fee` is still reported as its own figure for the line-level audit.
 *
 * No database access, no side effects.
 *
 * @see \App\Support\SalesLineClassifier Classifies each individual line.
 * @see \App\Support\SalesChannel        Defines the accounting identity used here.
 */
final class QuoteSalesBreakdown
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Split one quote row's money into merchandise, delivery, other fees, and
     * tax, with a line-level audit trail and data-quality warnings.
     *
     * @param array{
     *     id: int|string,
     *     items_json: string,
     *     subtotal: float|string,
     *     tax_amount: float|string,
     *     delivery_fee: float|string,
     *     deposit_amount: float|string,
     *     status: string,
     * } $quote A row from the `quotes` table. Decimal columns arrive from PDO
     *          as numeric strings, so they are cast defensively.
     *
     * @return array{merchandise: float, delivery: float, other_fee: float, tax: float,
     *               recognized_sales: float, deposit_collected: float,
     *               lines: list<array{description: string, amount: float, bucket: string, reason: string}>,
     *               warnings: list<string>}
     *         The bucketed figures (dollars, 2dp), the classified line-by-line
     *         audit trail, and any warnings raised while producing it.
     *
     * @example
     *   // Quote 19: Ribbon $7, Delivery $15, 30 Roses at $3.50 — pre-migration-018,
     *   // so delivery_fee is 0.00 and the $15 lives inside items_json/subtotal.
     *   QuoteSalesBreakdown::fromQuote([
     *       'id' => 19,
     *       'items_json' => '[{"qty":1,"unit_price":7,"description":"Ribbon","full_deposit":false},'
     *           . '{"qty":1,"unit_price":15,"description":"Delivery","full_deposit":false},'
     *           . '{"qty":30,"unit_price":3.5,"description":"Roses","full_deposit":false}]',
     *       'subtotal' => '127.00',
     *       'tax_amount' => '10.82',
     *       'delivery_fee' => '0.00',
     *       'deposit_amount' => '63.50',
     *       'status' => 'deposit_confirmed',
     *   ]);
     *   // ['merchandise' => 112.00, 'delivery' => 15.00, 'other_fee' => 0.00, 'tax' => 10.82,
     *   //  'recognized_sales' => 137.82, 'deposit_collected' => 63.50, 'lines' => [...],
     *   //  'warnings' => ['quote-19: delivery line "Delivery" ($15.00) was taxed (pre-migration-018 behaviour)']]
     */
    public static function fromQuote(array $quote): array
    {
        $id       = (string) $quote['id'];
        $subtotal = (float) $quote['subtotal'];
        $tax      = (float) $quote['tax_amount'];
        $deliveryFeeColumn = (float) $quote['delivery_fee'];
        $depositCollected  = (float) $quote['deposit_amount'];

        $items = json_decode((string) $quote['items_json'], true);

        if (!is_array($items) || !array_is_list($items)) {
            return [
                'merchandise'        => 0.0,
                'delivery'           => 0.0,
                'other_fee'          => 0.0,
                'tax'                => 0.0,
                'recognized_sales'   => 0.0,
                'deposit_collected'  => 0.0,
                'lines'              => [],
                'warnings'           => [sprintf('quote-%s: items_json is not a valid JSON list; treating quote as zero.', $id)],
            ];
        }

        [$lines, $merchandise, $lineDelivery, $otherFee] = self::classifyLines($id, $items);

        $warnings = self::buildWarnings($id, $lines, $tax, $deliveryFeeColumn, $lineDelivery);

        $delivery = round($lineDelivery + $deliveryFeeColumn, 2);
        $merchandise = round($merchandise, 2);
        $otherFee    = round($otherFee, 2);

        $breakdown = [
            'merchandise'       => $merchandise,
            'delivery'          => $delivery,
            'other_fee'         => $otherFee,
            'tax'               => round($tax, 2),
            'recognized_sales'  => SalesChannel::recognize($merchandise + $otherFee, $delivery, round($tax, 2), 0.0),
            'deposit_collected' => round($depositCollected, 2),
            'lines'             => $lines,
            'warnings'          => $warnings,
        ];

        if (!self::partitionCloses($breakdown, $subtotal)) {
            $breakdown['warnings'][] = sprintf(
                'quote-%s: bucket partition (%.2f) does not reconcile with subtotal (%.2f).',
                $id,
                $merchandise + $lineDelivery + $otherFee,
                $subtotal
            );
        }

        return $breakdown;
    }

    /**
     * Classify every line of a quote and sum the merchandise/delivery/other-fee
     * buckets.
     *
     * @param string                $id    The quote id, for building override keys.
     * @param list<array<mixed>>    $items Decoded `items_json` line items.
     *
     * @return array{0: list<array{description: string, amount: float, bucket: string, reason: string}>,
     *               1: float, 2: float, 3: float}
     *         The audited line list, followed by the merchandise, delivery, and
     *         other-fee subtotals (unrounded).
     */
    private static function classifyLines(string $id, array $items): array
    {
        $lines       = [];
        $merchandise = 0.0;
        $delivery    = 0.0;
        $otherFee    = 0.0;

        foreach (array_values($items) as $index => $item) {
            $description = (string) ($item['description'] ?? '');
            $amount      = (float) ($item['unit_price'] ?? 0) * (int) ($item['qty'] ?? 0);

            $classification = SalesLineClassifier::classify($description, "quote-{$id}:{$index}");

            $lines[] = [
                'description' => $description,
                'amount'      => round($amount, 2),
                'bucket'      => $classification['bucket'],
                'reason'      => $classification['reason'],
            ];

            match ($classification['bucket']) {
                SalesLineClassifier::DELIVERY   => $delivery += $amount,
                SalesLineClassifier::OTHER_FEE  => $otherFee += $amount,
                default                         => $merchandise += $amount,
            };
        }

        return [$lines, $merchandise, $delivery, $otherFee];
    }

    /**
     * Build the data-quality warnings for one quote's line classification.
     *
     * @param string $id                 The quote id, named in every warning.
     * @param list<array{description: string, amount: float, bucket: string, reason: string}> $lines
     *        The classified lines to inspect for taxed-fee and double-count risks.
     * @param float  $tax                The quote's `tax_amount`, in dollars.
     * @param float  $deliveryFeeColumn  The quote's `delivery_fee` column value, in dollars.
     * @param float  $lineDelivery       Delivery dollars found among the classified lines.
     *
     * @return list<string> Self-contained warning sentences, each naming the quote id.
     */
    private static function buildWarnings(
        string $id,
        array $lines,
        float $tax,
        float $deliveryFeeColumn,
        float $lineDelivery
    ): array {
        $warnings = [];

        if ($tax > 0) {
            foreach ($lines as $line) {
                if (SalesLineClassifier::isFee($line['bucket'])) {
                    $warnings[] = sprintf(
                        'quote-%s: %s line "%s" ($%.2f) was taxed (pre-migration-018 behaviour)',
                        $id,
                        $line['bucket'] === SalesLineClassifier::DELIVERY ? 'delivery' : 'other-fee',
                        $line['description'],
                        $line['amount']
                    );
                }
            }
        }

        if ($deliveryFeeColumn > 0 && $lineDelivery > 0) {
            $warnings[] = sprintf(
                'quote-%s: both delivery_fee ($%.2f) and a classified delivery line ($%.2f) are present — possible double count.',
                $id,
                $deliveryFeeColumn,
                $lineDelivery
            );
        }

        return $warnings;
    }

    /**
     * Check whether a breakdown's merchandise/delivery/other-fee buckets sum
     * back to the quote's stored subtotal, within a cent.
     *
     * `quotes.subtotal` includes any fee lines typed into `items_json` but
     * never the dedicated `delivery_fee` column (see the class DocBlock), so
     * the check compares against the classified-line total only — the same
     * total {@see self::fromQuote()} uses when raising its own reconciliation
     * warning.
     *
     * @param array{merchandise: float, delivery: float, other_fee: float, tax: float,
     *              recognized_sales: float, deposit_collected: float,
     *              lines: list<array{description: string, amount: float, bucket: string, reason: string}>,
     *              warnings: list<string>} $breakdown The output of {@see self::fromQuote()}.
     * @param float $subtotal The quote's `subtotal` column, in dollars.
     *
     * @return bool True when the classified lines' amounts sum to `$subtotal`
     *              within $0.01.
     *
     * @example
     *   $breakdown = QuoteSalesBreakdown::fromQuote($quote19Row);
     *   QuoteSalesBreakdown::partitionCloses($breakdown, 127.00); // true
     */
    public static function partitionCloses(array $breakdown, float $subtotal): bool
    {
        $lineTotal = array_reduce(
            $breakdown['lines'],
            static fn (float $carry, array $line): float => $carry + $line['amount'],
            0.0
        );

        return abs(round($lineTotal, 2) - round($subtotal, 2)) <= 0.01;
    }
}
