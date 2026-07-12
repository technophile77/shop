<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Analytics;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure GA4 + Meta Pixel + Google Ads event builders in App\Support\Analytics.
 *
 * Each builder must return the expected GA4 event name and Pixel event with the
 * right ids, value, currency, and item shape. Conversion events (purchase, lead)
 * must also carry the symbolic Google Ads 'ads' key; funnel events must not.
 *
 * @see \App\Support\Analytics
 */
final class AnalyticsTest extends TestCase
{
    public function testViewItem(): void
    {
        $spec = Analytics::viewItem(['id' => 12, 'name_en' => 'Rose Bouquet', 'price_from' => 45.0]);

        self::assertSame('view_item', $spec['ga4']['name']);
        self::assertSame('ViewContent', $spec['pixel']['event']);
        self::assertSame('USD', $spec['ga4']['params']['currency']);
        self::assertSame(45.0, $spec['ga4']['params']['value']);
        self::assertArrayNotHasKey('ads', $spec);

        $item = $spec['ga4']['params']['items'][0];
        self::assertSame('12', $item['item_id']);
        self::assertSame('Rose Bouquet', $item['item_name']);
        self::assertSame(45.0, $item['price']);

        self::assertSame(['12'], $spec['pixel']['params']['content_ids']);
        self::assertSame('product', $spec['pixel']['params']['content_type']);
        self::assertSame(45.0, $spec['pixel']['params']['value']);
        self::assertSame('USD', $spec['pixel']['params']['currency']);
    }

    public function testAddToCartMultipliesValueByQuantity(): void
    {
        $spec = Analytics::addToCart(['id' => 7, 'name_en' => 'Tulips', 'unit_price' => 30.0, 'qty' => 3]);

        self::assertSame('add_to_cart', $spec['ga4']['name']);
        self::assertSame('AddToCart', $spec['pixel']['event']);
        // value = unit_price × qty
        self::assertSame(90.0, $spec['ga4']['params']['value']);
        self::assertSame(90.0, $spec['pixel']['params']['value']);
        self::assertArrayNotHasKey('ads', $spec);

        $item = $spec['ga4']['params']['items'][0];
        self::assertSame('7', $item['item_id']);
        self::assertSame(3, $item['quantity']);

        self::assertSame(['7'], $spec['pixel']['params']['content_ids']);
        self::assertSame(
            [['id' => '7', 'quantity' => 3]],
            $spec['pixel']['params']['contents'],
        );
    }

    public function testBeginCheckoutAggregatesItems(): void
    {
        $items = [
            ['id' => 1, 'name_en' => 'A', 'unit_price' => 20.0, 'qty' => 2],
            ['id' => 2, 'name_en' => 'B', 'unit_price' => 50.0, 'qty' => 1],
        ];
        $spec = Analytics::beginCheckout($items, 90.0);

        self::assertSame('begin_checkout', $spec['ga4']['name']);
        self::assertSame('InitiateCheckout', $spec['pixel']['event']);
        self::assertSame(90.0, $spec['ga4']['params']['value']);
        self::assertCount(2, $spec['ga4']['params']['items']);

        self::assertSame(['1', '2'], $spec['pixel']['params']['content_ids']);
        // num_items sums the per-line quantities (2 + 1).
        self::assertSame(3, $spec['pixel']['params']['num_items']);
        self::assertSame('USD', $spec['pixel']['params']['currency']);
        self::assertArrayNotHasKey('ads', $spec);
    }

    public function testPurchaseUsesOrderTotalAndId(): void
    {
        $order = ['id' => 87, 'total' => 96.83];
        $items = [
            ['item_id' => 1, 'item_name' => 'A', 'price' => 20.0, 'quantity' => 2],
            ['item_id' => 2, 'item_name' => 'B', 'price' => 50.0, 'quantity' => 1],
        ];
        $spec = Analytics::purchase($order, $items);

        self::assertSame('purchase', $spec['ga4']['name']);
        self::assertSame('Purchase', $spec['pixel']['event']);
        self::assertSame('87', $spec['ga4']['params']['transaction_id']);
        self::assertSame(96.83, $spec['ga4']['params']['value']);
        self::assertSame(96.83, $spec['pixel']['params']['value']);
        self::assertSame('USD', $spec['ga4']['params']['currency']);

        self::assertSame(['1', '2'], $spec['pixel']['params']['content_ids']);
        self::assertSame(3, $spec['pixel']['params']['num_items']);

        self::assertSame('purchase', $spec['ads']['conversion']);
        self::assertSame(96.83, $spec['ads']['params']['value']);
        self::assertSame('USD', $spec['ads']['params']['currency']);
        self::assertSame('87', $spec['ads']['params']['transaction_id']);
    }

    public function testLeadHasNoValueButCarriesAdsConversion(): void
    {
        $spec = Analytics::lead();

        self::assertSame('generate_lead', $spec['ga4']['name']);
        self::assertSame('Lead', $spec['pixel']['event']);
        self::assertSame('lead', $spec['ads']['conversion']);
        self::assertSame([], $spec['ads']['params']);
    }

    public function testItemMapperFallsBackAcrossKeyNames(): void
    {
        // Snapshot-style row (item_id/price/quantity) and product-style row
        // (id/price_from) should both normalize identically in shape.
        $fromSnapshot = Analytics::viewItem(['item_id' => 'x9', 'item_name' => 'Z', 'price' => 12.5, 'quantity' => 4]);
        $item = $fromSnapshot['ga4']['params']['items'][0];

        self::assertSame('x9', $item['item_id']);
        self::assertSame('Z', $item['item_name']);
        self::assertSame(12.5, $item['price']);
        self::assertSame(4, $item['quantity']);
    }
}
