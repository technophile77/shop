<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\QuoteDraft;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuoteDraft — the bouquet-request → draft-quote mapper.
 *
 * Covers budget-label parsing (every form option plus edge cases and a
 * stochastic midpoint check), line-item construction (bouquet pricing, add-on
 * lines, the empty-style fallback), and notes assembly (omitting empties,
 * including a delivery summary).
 *
 * @see \App\Support\QuoteDraft
 */
class QuoteDraftTest extends TestCase
{
    /**
     * Each of the public order form's budget options maps to the expected price.
     *
     * Values mirror views/public/order.php:227-232 exactly, including the en-dash.
     */
    public function testBudgetMidpointForEveryFormOption(): void
    {
        $this->assertSame(25.0,  QuoteDraft::budgetMidpoint('Under $50'));
        $this->assertSame(75.0,  QuoteDraft::budgetMidpoint('$50–$100'));
        $this->assertSame(150.0, QuoteDraft::budgetMidpoint('$100–$200'));
        $this->assertSame(275.0, QuoteDraft::budgetMidpoint('$200–$350'));
        $this->assertSame(350.0, QuoteDraft::budgetMidpoint('$350+'));
        $this->assertSame(0.0,   QuoteDraft::budgetMidpoint('Custom / Tell me more in notes'));
    }

    /**
     * A plain hyphen range parses the same as the en-dash the form uses.
     */
    public function testBudgetMidpointAcceptsPlainHyphen(): void
    {
        $this->assertSame(150.0, QuoteDraft::budgetMidpoint('$100-$200'));
    }

    /**
     * Empty, null, and word-only labels yield 0.0 (owner sets the price).
     */
    public function testBudgetMidpointZeroForUnparseable(): void
    {
        $this->assertSame(0.0, QuoteDraft::budgetMidpoint(''));
        $this->assertSame(0.0, QuoteDraft::budgetMidpoint(null));
        $this->assertSame(0.0, QuoteDraft::budgetMidpoint('Custom'));
    }

    /**
     * Stochastic: for random A < B, "$A–$B" must return exactly (A + B) / 2.
     *
     * Guards against a parser that picks the wrong group or mishandles the
     * separator across a wide spread of inputs rather than a few fixed cases.
     */
    public function testBudgetMidpointStochasticRanges(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $a = random_int(1, 500);
            $b = random_int($a + 1, $a + 500);

            $expected = round(($a + $b) / 2, 2);
            $actual   = QuoteDraft::budgetMidpoint("\${$a}–\${$b}");

            $this->assertSame(
                $expected,
                $actual,
                "Midpoint of \${$a}–\${$b} should be {$expected}, got {$actual}",
            );
        }
    }

    /**
     * With no add-ons there is a single bouquet line at the budget midpoint.
     */
    public function testLineItemsBouquetOnly(): void
    {
        $items = QuoteDraft::lineItems(
            ['arrangement_style' => 'Money bouquet', 'budget_range' => '$100–$200'],
            [],
        );

        $this->assertCount(1, $items);
        $this->assertSame('Money bouquet', $items[0]['description']);
        $this->assertSame(1, $items[0]['qty']);
        $this->assertSame(150.0, $items[0]['unit_price']);
    }

    /**
     * Add-ons each become a line at their catalogue price, after the bouquet.
     */
    public function testLineItemsWithAddons(): void
    {
        $items = QuoteDraft::lineItems(
            ['arrangement_style' => 'Money bouquet', 'budget_range' => '$100–$200'],
            [
                ['name' => 'Customized Ribbon', 'price' => 7.0],
                ['name' => 'Crown',             'price' => 15.0],
            ],
        );

        $this->assertCount(3, $items);
        $this->assertSame('Customized Ribbon', $items[1]['description']);
        $this->assertSame(7.0,  $items[1]['unit_price']);
        $this->assertSame('Crown', $items[2]['description']);
        $this->assertSame(15.0, $items[2]['unit_price']);
    }

    /**
     * A blank or missing arrangement style falls back to "Custom bouquet".
     */
    public function testLineItemsBlankStyleFallback(): void
    {
        $blank   = QuoteDraft::lineItems(['arrangement_style' => '   ', 'budget_range' => '$100–$200'], []);
        $missing = QuoteDraft::lineItems(['budget_range' => '$100–$200'], []);

        $this->assertSame('Custom bouquet', $blank[0]['description']);
        $this->assertSame('Custom bouquet', $missing[0]['description']);
    }

    /**
     * A "Custom" budget leaves the bouquet at $0.00 for the owner to price.
     */
    public function testLineItemsZeroPricedCustomBudget(): void
    {
        $items = QuoteDraft::lineItems(
            ['arrangement_style' => 'Surprise me', 'budget_range' => 'Custom'],
            [],
        );

        $this->assertSame(0.0, $items[0]['unit_price']);
    }

    /**
     * Notes collect every supplied field as a labelled line in order.
     */
    public function testNotesIncludesAllFields(): void
    {
        $notes = QuoteDraft::notes(
            'Quinceañera',
            'Pink and white',
            'Es para el regalo sorpresa',
            'Delivery: 123 Main St',
        );

        $this->assertSame(
            "Occasion: Quinceañera\n"
            . "Colors: Pink and white\n"
            . "Delivery: 123 Main St\n"
            . 'Customer notes: Es para el regalo sorpresa',
            $notes,
        );
    }

    /**
     * Empty/null fields are omitted; an all-empty input yields an empty string.
     */
    public function testNotesOmitsEmptyFields(): void
    {
        $this->assertSame('Occasion: Birthday', QuoteDraft::notes('Birthday', '', null, ''));
        $this->assertSame('', QuoteDraft::notes(null, null, null, ''));
        $this->assertSame('', QuoteDraft::notes('  ', '  ', '  ', '  '));
    }
}
