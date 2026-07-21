<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\StripeLineItems;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeLineItems::fromCart().
 *
 * Verifies cent conversion, one-line-per-bouquet, add-on lines with qty
 * mirroring the bouquet qty, delivery and tax line presence/absence, and
 * correct handling of an empty cart.
 *
 * @see \App\Support\StripeLineItems
 */
class StripeLineItemsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Build a minimal canonical cart line item. */
    private function makeLine(
        string $name = 'Rose Bouquet',
        float  $unitPrice = 45.00,
        int    $qty = 1,
        array  $addons = [],
    ): array {
        return [
            'product_id' => 1,
            'name_en'    => $name,
            'unit_price' => $unitPrice,
            'qty'        => $qty,
            'addons'     => $addons,
            'colors'     => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Empty cart
    // -------------------------------------------------------------------------

    public function testEmptyCartNoDeliveryNoTax(): void
    {
        $result = StripeLineItems::fromCart([], 0.00, 0.00);
        $this->assertSame([], $result);
    }

    public function testEmptyCartWithDeliveryAndTax(): void
    {
        $result = StripeLineItems::fromCart([], 10.00, 3.83);

        // Should still include delivery + tax lines even with no bouquets
        $this->assertCount(2, $result);
        $this->assertSame('Delivery',   $result[0]['price_data']['product_data']['name']);
        $this->assertSame('Sales Tax',  $result[1]['price_data']['product_data']['name']);
    }

    // -------------------------------------------------------------------------
    // Cents conversion
    // -------------------------------------------------------------------------

    public function testCentsConversionForBouquet(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine('Bouquet', 45.00)], 0.00, 0.00);

        $this->assertCount(1, $result);
        $this->assertSame(4500, $result[0]['price_data']['unit_amount']);
    }

    public function testCentsConversionForDelivery(): void
    {
        $result = StripeLineItems::fromCart([], 12.50, 0.00);

        $this->assertSame(1250, $result[0]['price_data']['unit_amount']);
    }

    public function testCentsConversionForTax(): void
    {
        $result = StripeLineItems::fromCart([], 0.00, 3.83);

        $this->assertSame(383, $result[0]['price_data']['unit_amount']);
    }

    // -------------------------------------------------------------------------
    // One line per bouquet
    // -------------------------------------------------------------------------

    public function testOneBouquetOneLine(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine('Rose Bouquet', 45.00, 2)], 0.00, 0.00);

        $this->assertCount(1, $result);
        $this->assertSame('Rose Bouquet', $result[0]['price_data']['product_data']['name']);
        $this->assertSame(4500,           $result[0]['price_data']['unit_amount']);
        $this->assertSame(2,              $result[0]['quantity']);
    }

    public function testTwoBouquetsProduceTwoLines(): void
    {
        $items  = [
            $this->makeLine('Rose Bouquet', 45.00),
            $this->makeLine('Lily Bouquet', 55.00),
        ];
        $result = StripeLineItems::fromCart($items, 0.00, 0.00);

        $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // Add-on lines
    // -------------------------------------------------------------------------

    public function testAddonLineCarriesQtyOfBouquet(): void
    {
        $addon = ['addon_id' => 1, 'name_en' => 'Ribbon', 'price' => 5.00, 'custom_text' => null];
        $line  = $this->makeLine('Rose Bouquet', 45.00, 3, [$addon]);

        $result = StripeLineItems::fromCart([$line], 0.00, 0.00);

        // Line 0 = bouquet, Line 1 = addon
        $this->assertCount(2, $result);
        $this->assertSame(3,   $result[1]['quantity']);
        $this->assertSame(500, $result[1]['price_data']['unit_amount']);
    }

    public function testPerUnitAddonQuantityTimesBouquetQty(): void
    {
        $addon = ['addon_id' => 5, 'name_en' => 'Chocolates', 'price' => 2.50, 'quantity' => 3, 'custom_text' => null];
        $line  = $this->makeLine('Bouquet', 45.00, 2, [$addon]); // 2 bouquets

        $result = StripeLineItems::fromCart([$line], 0.00, 0.00);

        // Line 1 = chocolates: per-unit 250 cents, quantity = 3 × 2 = 6.
        $this->assertCount(2, $result);
        $this->assertSame(250, $result[1]['price_data']['unit_amount']);
        $this->assertSame(6,   $result[1]['quantity']);
    }

    public function testZeroPriceAddonSkippedWithoutCustomText(): void
    {
        $addon = ['addon_id' => 2, 'name_en' => 'Free Card', 'price' => 0.00, 'custom_text' => null];
        $line  = $this->makeLine('Bouquet', 45.00, 1, [$addon]);

        $result = StripeLineItems::fromCart([$line], 0.00, 0.00);

        $this->assertCount(1, $result); // only the bouquet
    }

    public function testZeroPriceAddonIncludedWithCustomText(): void
    {
        $addon = ['addon_id' => 3, 'name_en' => 'Card', 'price' => 0.00, 'custom_text' => 'Happy Birthday!'];
        $line  = $this->makeLine('Bouquet', 45.00, 1, [$addon]);

        $result = StripeLineItems::fromCart([$line], 0.00, 0.00);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('Happy Birthday!', $result[1]['price_data']['product_data']['name']);
    }

    public function testAddonNameIncludesCustomText(): void
    {
        $addon = ['addon_id' => 4, 'name_en' => 'Card', 'price' => 3.00, 'custom_text' => 'With Love'];
        $line  = $this->makeLine('Bouquet', 45.00, 1, [$addon]);

        $result = StripeLineItems::fromCart([$line], 0.00, 0.00);

        $this->assertSame('Card: With Love', $result[1]['price_data']['product_data']['name']);
    }

    // -------------------------------------------------------------------------
    // Delivery and Tax lines
    // -------------------------------------------------------------------------

    public function testDeliveryLineAbsentWhenZero(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine()], 0.00, 5.00);

        $names = array_column(array_column($result, 'price_data'), 'product_data');
        $names = array_column($names, 'name');
        $this->assertNotContains('Delivery', $names);
    }

    public function testDeliveryLinePresentWhenPositive(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine()], 10.00, 0.00);

        $names = array_map(
            static fn ($li) => $li['price_data']['product_data']['name'],
            $result
        );
        $this->assertContains('Delivery', $names);
    }

    public function testTaxLineAbsentWhenZero(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine()], 0.00, 0.00);

        $names = array_map(
            static fn ($li) => $li['price_data']['product_data']['name'],
            $result
        );
        $this->assertNotContains('Sales Tax', $names);
    }

    public function testTaxLinePresentWhenPositive(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine()], 0.00, 3.83);

        $names = array_map(
            static fn ($li) => $li['price_data']['product_data']['name'],
            $result
        );
        $this->assertContains('Sales Tax', $names);
    }

    public function testDeliveryAndTaxBothPresent(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine()], 10.00, 3.83);

        $names = array_map(
            static fn ($li) => $li['price_data']['product_data']['name'],
            $result
        );
        $this->assertContains('Delivery',  $names);
        $this->assertContains('Sales Tax', $names);
    }

    /**
     * Delivery always precedes tax (ordering matters for Stripe receipt display).
     */
    public function testDeliveryBeforeTax(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine()], 10.00, 3.83);

        $names  = array_map(
            static fn ($li) => $li['price_data']['product_data']['name'],
            $result
        );
        $deliveryIdx = array_search('Delivery',   $names, true);
        $taxIdx      = array_search('Sales Tax',  $names, true);
        $this->assertGreaterThan($deliveryIdx, $taxIdx);
    }

    // -------------------------------------------------------------------------
    // Stripe Tax Rate object (STRIPE_TAX_RATE_ID) attachment
    // -------------------------------------------------------------------------

    public function testTaxRateAttachedToBouquetLineWhenIdSupplied(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine('Rose Bouquet', 45.00)], 0.00, 3.83, 'txr_123');

        $this->assertSame(['txr_123'], $result[0]['tax_rates'] ?? null);
    }

    public function testTaxRateAttachedToAddonLine(): void
    {
        $addon  = ['addon_id' => 1, 'name_en' => 'Ribbon', 'price' => 5.00, 'custom_text' => null];
        $result = StripeLineItems::fromCart([$this->makeLine('Bouquet', 45.00, 1, [$addon])], 0.00, 3.83, 'txr_123');

        // Line 0 = bouquet, Line 1 = addon — both taxed.
        $this->assertSame(['txr_123'], $result[0]['tax_rates'] ?? null);
        $this->assertSame(['txr_123'], $result[1]['tax_rates'] ?? null);
    }

    public function testDeliveryNeverCarriesTaxRate(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine('Bouquet', 45.00)], 10.00, 3.83, 'txr_123');

        $delivery = null;
        foreach ($result as $li) {
            if ($li['price_data']['product_data']['name'] === 'Delivery') {
                $delivery = $li;
            }
        }
        $this->assertNotNull($delivery);
        $this->assertArrayNotHasKey('tax_rates', $delivery);
    }

    public function testNoSalesTaxLineWhenTaxRateSupplied(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine('Bouquet', 45.00)], 10.00, 3.83, 'txr_123');

        $names = array_map(
            static fn ($li) => $li['price_data']['product_data']['name'],
            $result
        );
        // Tax is expressed via tax_rates on merchandise, not a fake product line.
        $this->assertNotContains('Sales Tax', $names);
    }

    public function testNoTaxRateAttachedWhenTaxAmountZero(): void
    {
        $result = StripeLineItems::fromCart([$this->makeLine('Bouquet', 45.00)], 0.00, 0.00, 'txr_123');

        $this->assertArrayNotHasKey('tax_rates', $result[0]);
    }

    public function testFallsBackToSalesTaxLineWhenNoRateId(): void
    {
        // Empty rate id but tax owed → plain Sales Tax line, no tax_rates key.
        $result = StripeLineItems::fromCart([$this->makeLine('Bouquet', 45.00)], 0.00, 3.83, '');

        $this->assertArrayNotHasKey('tax_rates', $result[0]);
        $names = array_map(
            static fn ($li) => $li['price_data']['product_data']['name'],
            $result
        );
        $this->assertContains('Sales Tax', $names);
    }

    // -------------------------------------------------------------------------
    // No double taxation — exactly one tax mechanism is ever active
    // -------------------------------------------------------------------------

    /**
     * Count the tax mechanisms present in a line_items array: a flat "Sales Tax"
     * product line, plus every line carrying a tax_rates entry. Used to assert
     * tax is applied by exactly one path, never both.
     *
     * @return array{sales_tax_lines: int, taxed_lines: int}
     */
    private function taxMechanisms(array $lineItems): array
    {
        $salesTaxLines = 0;
        $taxedLines    = 0;
        foreach ($lineItems as $li) {
            if (($li['price_data']['product_data']['name'] ?? '') === 'Sales Tax') {
                $salesTaxLines++;
            }
            if (!empty($li['tax_rates'])) {
                $taxedLines++;
            }
        }
        return ['sales_tax_lines' => $salesTaxLines, 'taxed_lines' => $taxedLines];
    }

    public function testCartNeverBothTaxRateAndSalesTaxLine(): void
    {
        $addon = ['addon_id' => 1, 'name_en' => 'Ribbon', 'price' => 5.00, 'custom_text' => null];
        $items = [$this->makeLine('Bouquet', 45.00, 1, [$addon])];

        $withRate = $this->taxMechanisms(StripeLineItems::fromCart($items, 10.00, 3.83, 'txr_123'));
        // Rate path: merchandise lines taxed, but zero flat Sales Tax lines.
        $this->assertSame(0, $withRate['sales_tax_lines'], 'no flat tax line when rate is used');
        $this->assertGreaterThan(0, $withRate['taxed_lines']);

        $fallback = $this->taxMechanisms(StripeLineItems::fromCart($items, 10.00, 3.83, ''));
        // Fallback path: exactly one flat Sales Tax line, and no tax_rates anywhere.
        $this->assertSame(1, $fallback['sales_tax_lines']);
        $this->assertSame(0, $fallback['taxed_lines'], 'no tax_rates when falling back to a flat line');
    }

    public function testQuoteNeverBothTaxRateAndSalesTaxLine(): void
    {
        $items = [
            ['description' => 'Tulips',     'qty' => 1, 'unit_price' => 30.00],
            ['description' => 'Hydrangeas', 'qty' => 1, 'unit_price' => 45.00],
            ['description' => 'Delivery',   'qty' => 1, 'unit_price' => 10.00],
        ];

        $withRate = $this->taxMechanisms(StripeLineItems::fromQuoteItems($items, 7.24, 'txr_123'));
        $this->assertSame(0, $withRate['sales_tax_lines'], 'no flat tax line when rate is used');
        $this->assertSame(3, $withRate['taxed_lines'], 'quote taxes every line, including Delivery');

        $fallback = $this->taxMechanisms(StripeLineItems::fromQuoteItems($items, 7.24, ''));
        $this->assertSame(1, $fallback['sales_tax_lines']);
        $this->assertSame(0, $fallback['taxed_lines']);
    }

    public function testQuoteNoTaxAppliesNeither(): void
    {
        $items = [['description' => 'Tulips', 'qty' => 1, 'unit_price' => 30.00]];
        $m = $this->taxMechanisms(StripeLineItems::fromQuoteItems($items, 0.00, 'txr_123'));
        $this->assertSame(0, $m['sales_tax_lines']);
        $this->assertSame(0, $m['taxed_lines']);
    }

    public function testQuoteFromQuoteItemsCentsAndTaxRate(): void
    {
        $items  = [['description' => "baby's breath and roses", 'qty' => 1, 'unit_price' => 25.00]];
        $result = StripeLineItems::fromQuoteItems($items, 2.13, 'txr_123');

        $this->assertCount(1, $result);
        $this->assertSame(2500, $result[0]['price_data']['unit_amount']);
        $this->assertSame(['txr_123'], $result[0]['tax_rates']);
    }

    // -------------------------------------------------------------------------
    // fromQuoteItems() delivery fee (separately-stated, untaxed)
    // -------------------------------------------------------------------------

    public function testQuoteDeliveryLineAbsentWhenFeeZero(): void
    {
        $items  = [['description' => 'Tulips', 'qty' => 1, 'unit_price' => 30.00]];
        $result = StripeLineItems::fromQuoteItems($items, 0.00, null, 0.00);

        $names = array_map(
            static fn ($li) => $li['price_data']['product_data']['name'],
            $result
        );
        $this->assertNotContains('Delivery', $names);
    }

    public function testQuoteDeliveryLinePresentWhenFeePositive(): void
    {
        $items  = [['description' => 'Tulips', 'qty' => 1, 'unit_price' => 30.00]];
        $result = StripeLineItems::fromQuoteItems($items, 0.00, null, 15.00);

        $delivery = null;
        foreach ($result as $li) {
            if ($li['price_data']['product_data']['name'] === 'Delivery') {
                $delivery = $li;
            }
        }
        $this->assertNotNull($delivery, 'Expected a Delivery line when the fee is positive.');
        $this->assertSame(1500, $delivery['price_data']['unit_amount']);
    }

    public function testQuoteDeliveryLineNeverCarriesTaxRate(): void
    {
        $items  = [['description' => 'Tulips', 'qty' => 1, 'unit_price' => 30.00]];
        $result = StripeLineItems::fromQuoteItems($items, 2.56, 'txr_123', 15.00);

        $delivery = null;
        foreach ($result as $li) {
            if ($li['price_data']['product_data']['name'] === 'Delivery') {
                $delivery = $li;
            }
        }
        $this->assertNotNull($delivery);
        $this->assertArrayNotHasKey('tax_rates', $delivery);
    }

    public function testQuoteDeliveryLineOmittedByDefault(): void
    {
        // The $deliveryFee parameter defaults to 0.0 for backward compatibility
        // with callers (and existing quotes) that don't pass one.
        $items  = [['description' => 'Tulips', 'qty' => 1, 'unit_price' => 30.00]];
        $result = StripeLineItems::fromQuoteItems($items, 0.00);

        $this->assertCount(1, $result);
    }

    // -------------------------------------------------------------------------
    // Currency field
    // -------------------------------------------------------------------------

    public function testAllLineItemsCurrencyIsUsd(): void
    {
        $addon  = ['addon_id' => 1, 'name_en' => 'Ribbon', 'price' => 5.00, 'custom_text' => null];
        $result = StripeLineItems::fromCart([$this->makeLine('B', 45.00, 1, [$addon])], 10.00, 3.83);

        foreach ($result as $li) {
            $this->assertSame('usd', $li['price_data']['currency']);
        }
    }
}
