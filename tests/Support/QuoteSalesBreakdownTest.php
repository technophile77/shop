<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\QuoteSalesBreakdown;
use App\Support\SalesChannel;
use App\Support\SalesLineClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Unit and property tests for QuoteSalesBreakdown::fromQuote() and
 * ::partitionCloses().
 *
 * The example-based tests use quote 19's real production data (a mix of a
 * merchandise line, a pre-migration-018 delivery line inside items_json, and
 * a taxed subtotal) plus synthetic rows for the delivery_fee-column path, the
 * double-count warning, and malformed JSON. The property test generates
 * random line-item sets and asserts the two laws every breakdown must obey:
 * the bucket partition accounts for every line exactly once, and the
 * recognized-sales identity closes.
 *
 * @see \App\Support\QuoteSalesBreakdown
 * @see \App\Support\SalesChannel
 */
class QuoteSalesBreakdownTest extends TestCase
{
    /** Real production items_json for quote 19 (see task background). */
    private const QUOTE_19_ITEMS_JSON = '[{"qty":1,"unit_price":7,"description":"Ribbon","full_deposit":false},'
        . '{"qty":1,"unit_price":15,"description":"Delivery","full_deposit":false},'
        . '{"qty":30,"unit_price":3.5,"description":"Roses","full_deposit":false}]';

    /**
     * Quote 19's merchandise bucket is Ribbon + Roses only; the Delivery line
     * is excluded and reported separately.
     */
    public function testQuote19MerchandiseExcludesDeliveryLine(): void
    {
        $breakdown = QuoteSalesBreakdown::fromQuote($this->quote19Row());

        // Ribbon: 7 * 1 = 7.00; Roses: 3.5 * 30 = 105.00; 7.00 + 105.00 = 112.00
        $this->assertSame(112.00, $breakdown['merchandise']);
    }

    /**
     * Quote 19's delivery bucket is the $15 line item, since delivery_fee is
     * 0.00 on this pre-migration-018 quote.
     */
    public function testQuote19DeliveryFromLineItem(): void
    {
        $breakdown = QuoteSalesBreakdown::fromQuote($this->quote19Row());

        // Delivery: 15 * 1 = 15.00
        $this->assertSame(15.00, $breakdown['delivery']);
        $this->assertSame(0.00, $breakdown['other_fee']);
    }

    /**
     * Quote 19's recognized_sales folds merchandise, delivery, and tax
     * (there is no other_fee here) per the documented identity.
     */
    public function testQuote19RecognizedSales(): void
    {
        $breakdown = QuoteSalesBreakdown::fromQuote($this->quote19Row());

        // 112.00 (merchandise) + 15.00 (delivery) + 10.82 (tax) = 137.82
        $this->assertSame(137.82, $breakdown['recognized_sales']);
        $this->assertSame(10.82, $breakdown['tax']);
        $this->assertSame(63.50, $breakdown['deposit_collected']);
    }

    /**
     * Quote 19's tax_amount was computed on the whole subtotal (including the
     * $15 delivery line) since it predates migration 018, so a taxed-delivery
     * warning must be raised naming the quote id.
     */
    public function testQuote19RaisesTaxedDeliveryWarning(): void
    {
        $breakdown = QuoteSalesBreakdown::fromQuote($this->quote19Row());

        $matches = array_filter(
            $breakdown['warnings'],
            static fn (string $w): bool => str_contains($w, 'quote-19') && str_contains($w, 'taxed')
        );
        $this->assertNotEmpty($matches);
    }

    /**
     * Quote 19's classified-line total (7 + 15 + 105 = 127) reconciles with
     * the stored subtotal of 127.00.
     */
    public function testQuote19PartitionCloses(): void
    {
        $breakdown = QuoteSalesBreakdown::fromQuote($this->quote19Row());

        $this->assertTrue(QuoteSalesBreakdown::partitionCloses($breakdown, 127.00));
    }

    /**
     * Malformed items_json returns an all-zero breakdown and a warning,
     * rather than throwing — one bad row must not kill the whole report.
     */
    public function testMalformedItemsJsonReturnsZerosAndWarning(): void
    {
        $quote = $this->quote19Row();
        $quote['items_json'] = '{not valid json';

        $breakdown = QuoteSalesBreakdown::fromQuote($quote);

        $this->assertSame(0.0, $breakdown['merchandise']);
        $this->assertSame(0.0, $breakdown['delivery']);
        $this->assertSame(0.0, $breakdown['other_fee']);
        $this->assertSame(0.0, $breakdown['recognized_sales']);
        $this->assertSame([], $breakdown['lines']);
        $this->assertNotEmpty($breakdown['warnings']);
    }

    /**
     * A non-list JSON value (e.g. an object) is also treated as malformed.
     */
    public function testNonListItemsJsonReturnsZerosAndWarning(): void
    {
        $quote = $this->quote19Row();
        $quote['items_json'] = '{"description":"Roses","qty":1,"unit_price":10}';

        $breakdown = QuoteSalesBreakdown::fromQuote($quote);

        $this->assertSame(0.0, $breakdown['merchandise']);
        $this->assertNotEmpty($breakdown['warnings']);
    }

    /**
     * A post-migration-018 quote with delivery_fee set and no delivery line
     * item puts the column value in the delivery bucket.
     */
    public function testDeliveryFeeColumnWithNoDeliveryLine(): void
    {
        $quote = [
            'id'             => 20,
            'items_json'     => '[{"qty":2,"unit_price":25,"description":"Tulips","full_deposit":false}]',
            'subtotal'       => '50.00',
            'tax_amount'     => '4.26',
            'delivery_fee'   => '12.00',
            'deposit_amount' => '25.00',
            'status'         => 'deposit_confirmed',
        ];

        $breakdown = QuoteSalesBreakdown::fromQuote($quote);

        $this->assertSame(50.00, $breakdown['merchandise']);
        $this->assertSame(12.00, $breakdown['delivery']);
        // No fee lines and delivery_fee column isn't a items_json line, so no
        // taxed-fee or double-count warnings.
        $this->assertSame([], $breakdown['warnings']);
    }

    /**
     * A quote with BOTH a non-zero delivery_fee column AND a classified
     * delivery line raises a double-count warning naming the quote id.
     */
    public function testBothDeliveryFeeColumnAndLineRaisesDoubleCountWarning(): void
    {
        $quote = [
            'id'             => 21,
            'items_json'     => '[{"qty":1,"unit_price":9.00,"description":"Delivery","full_deposit":false},'
                . '{"qty":1,"unit_price":40,"description":"Vase","full_deposit":false}]',
            'subtotal'       => '49.00',
            'tax_amount'     => '0.00',
            'delivery_fee'   => '9.00',
            'deposit_amount' => '24.50',
            'status'         => 'deposit_confirmed',
        ];

        $breakdown = QuoteSalesBreakdown::fromQuote($quote);

        $matches = array_filter(
            $breakdown['warnings'],
            static fn (string $w): bool => str_contains($w, 'quote-21') && str_contains($w, 'double count')
        );
        $this->assertNotEmpty($matches);
        // 9.00 (line) + 9.00 (column) = 18.00
        $this->assertSame(18.00, $breakdown['delivery']);
    }

    /**
     * Every line of the input is echoed back in the audit trail, in order,
     * each with a bucket and a non-empty reason.
     */
    public function testLinesAreAuditedWithBucketAndReason(): void
    {
        $breakdown = QuoteSalesBreakdown::fromQuote($this->quote19Row());

        $this->assertCount(3, $breakdown['lines']);
        $this->assertSame('Ribbon', $breakdown['lines'][0]['description']);
        $this->assertSame(SalesLineClassifier::MERCHANDISE, $breakdown['lines'][0]['bucket']);
        $this->assertSame('Delivery', $breakdown['lines'][1]['description']);
        $this->assertSame(SalesLineClassifier::DELIVERY, $breakdown['lines'][1]['bucket']);
        $this->assertSame('Roses', $breakdown['lines'][2]['description']);
        $this->assertSame(SalesLineClassifier::MERCHANDISE, $breakdown['lines'][2]['bucket']);

        foreach ($breakdown['lines'] as $line) {
            $this->assertNotSame('', $line['reason']);
        }
    }

    /**
     * Property test: for randomly generated item sets, the bucket partition
     * must account for every line exactly once and the recognized-sales
     * identity must close, for every one of 200 seeded trials.
     */
    public function testPartitionAndIdentityLawsHoldOverRandomQuotes(): void
    {
        mt_srand(20260725);

        $vocabulary = [
            'Roses', 'Tulips', 'Ribbon', 'Vase', 'Baby\'s Breath', 'Greenery',
            'Delivery', 'Local Delivery', 'Courier', 'Shipping',
            'Setup fee', 'Rush order', 'Gratuity', 'Surcharge',
        ];

        for ($trial = 0; $trial < 200; $trial++) {
            $itemCount = mt_rand(1, 6);
            $items = [];
            $expectedLineTotal = 0.0;

            for ($i = 0; $i < $itemCount; $i++) {
                $qty       = mt_rand(1, 30);
                $unitPrice = round(mt_rand(100, 20000) / 100, 2);
                $description = $vocabulary[array_rand($vocabulary)];

                $items[] = [
                    'qty'          => $qty,
                    'unit_price'   => $unitPrice,
                    'description'  => $description,
                    'full_deposit' => false,
                ];

                // Independently recomputed from the generator's own inputs,
                // not from any classifier or breakdown logic.
                $expectedLineTotal += round($unitPrice * $qty, 2);
            }

            $taxAmount = round($expectedLineTotal * 0.08517, 2);

            $quote = [
                'id'             => 1000 + $trial,
                'items_json'     => json_encode($items),
                'subtotal'       => (string) round($expectedLineTotal, 2),
                'tax_amount'     => (string) $taxAmount,
                'delivery_fee'   => '0.00',
                'deposit_amount' => '0.00',
                'status'         => 'deposit_confirmed',
            ];

            $breakdown = QuoteSalesBreakdown::fromQuote($quote);

            // Partition completeness: every line accounted for exactly once.
            $this->assertCount($itemCount, $breakdown['lines'], "trial {$trial}");
            $partitionTotal = round(
                $breakdown['merchandise'] + $breakdown['delivery'] + $breakdown['other_fee'],
                2
            );
            $this->assertEqualsWithDelta($expectedLineTotal, $partitionTotal, 0.01, "trial {$trial}");

            foreach ($breakdown['lines'] as $line) {
                $this->assertContains(
                    $line['bucket'],
                    [SalesLineClassifier::MERCHANDISE, SalesLineClassifier::DELIVERY, SalesLineClassifier::OTHER_FEE],
                    "trial {$trial}"
                );
                $this->assertNotSame('', $line['reason'], "trial {$trial}");
            }

            // Identity closure.
            $expectedRecognized = SalesChannel::recognize(
                $breakdown['merchandise'] + $breakdown['other_fee'],
                $breakdown['delivery'],
                $breakdown['tax'],
                0.0
            );
            $this->assertEqualsWithDelta(
                $expectedRecognized,
                $breakdown['recognized_sales'],
                SalesChannel::IDENTITY_TOLERANCE,
                "trial {$trial}"
            );
        }
    }

    /**
     * Quote 19's raw row shape, as it would arrive from the `quotes` table
     * (decimal columns as strings, per PDO's default fetch mode).
     *
     * @return array{id: int, items_json: string, subtotal: string, tax_amount: string,
     *               delivery_fee: string, deposit_amount: string, status: string}
     */
    private function quote19Row(): array
    {
        return [
            'id'             => 19,
            'items_json'     => self::QUOTE_19_ITEMS_JSON,
            'subtotal'       => '127.00',
            'tax_amount'     => '10.82',
            'delivery_fee'   => '0.00',
            'deposit_amount' => '63.50',
            'status'         => 'deposit_confirmed',
        ];
    }
}
