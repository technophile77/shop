<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\ShopOrderSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ShopOrderSnapshot::itemsSnapshot().
 *
 * Verifies that resolved names are embedded, missing IDs degrade gracefully
 * to null, line_total math is correct, and both language paths work.
 *
 * @see \App\Support\ShopOrderSnapshot
 */
class ShopOrderSnapshotTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeLine(array $overrides = []): array
    {
        return array_merge([
            'product_id'     => 1,
            'name_en'        => 'Rose Bouquet',
            'name_es'        => 'Ramo de Rosas',
            'image_path'     => 'roses.jpg',
            'unit_price'     => 45.00,
            'flower_count'   => 12,
            'qty'            => 1,
            'paper_color_id' => 1,
            'colors'         => [
                ['flower_type_id' => 1, 'color_ids' => [1, 2], 'mixed' => false],
            ],
            'addons'         => [],
        ], $overrides);
    }

    private function typeNames(): array  { return [1 => 'Rose', 2 => 'Lily']; }
    private function colorNames(): array { return [1 => 'Red', 2 => 'White', 3 => 'Pink']; }
    private function paperNames(): array { return [1 => 'White Kraft', 2 => 'Black']; }

    // -------------------------------------------------------------------------
    // Basic name resolution
    // -------------------------------------------------------------------------

    public function testPaperColorNameResolved(): void
    {
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$this->makeLine()], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertSame('White Kraft', $snapshot[0]['paper_color_name']);
    }

    public function testFlowerTypeNameResolved(): void
    {
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$this->makeLine()], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertSame('Rose', $snapshot[0]['colors'][0]['flower_type_name']);
    }

    public function testColorNamesResolved(): void
    {
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$this->makeLine()], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertSame(['Red', 'White'], $snapshot[0]['colors'][0]['color_names']);
    }

    // -------------------------------------------------------------------------
    // Graceful degradation for missing IDs
    // -------------------------------------------------------------------------

    public function testMissingPaperColorDegradesToNull(): void
    {
        $line     = $this->makeLine(['paper_color_id' => 99]);
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$line], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertNull($snapshot[0]['paper_color_name']);
    }

    public function testMissingFlowerTypeNameDegradesToNull(): void
    {
        $line = $this->makeLine([
            'colors' => [['flower_type_id' => 99, 'color_ids' => [], 'mixed' => false]],
        ]);
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$line], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertNull($snapshot[0]['colors'][0]['flower_type_name']);
    }

    public function testMissingColorIdDegradesToNull(): void
    {
        $line = $this->makeLine([
            'colors' => [['flower_type_id' => 1, 'color_ids' => [99], 'mixed' => false]],
        ]);
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$line], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertSame([null], $snapshot[0]['colors'][0]['color_names']);
    }

    public function testNoPaperColorIdResultsInNullName(): void
    {
        $line     = $this->makeLine(['paper_color_id' => null]);
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$line], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertNull($snapshot[0]['paper_color_name']);
        $this->assertNull($snapshot[0]['paper_color_id']);
    }

    // -------------------------------------------------------------------------
    // Line total math
    // -------------------------------------------------------------------------

    public function testLineTotalWithoutAddons(): void
    {
        $line     = $this->makeLine(['unit_price' => 45.00, 'qty' => 2]);
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$line], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertSame(90.00, $snapshot[0]['line_total']);
    }

    public function testLineTotalWithAddons(): void
    {
        // unit_price=45, addon price=5, qty=2 → (45+5)*2 = 100
        $line = $this->makeLine([
            'unit_price' => 45.00,
            'qty'        => 2,
            'addons'     => [
                ['addon_id' => 1, 'name_en' => 'Ribbon', 'name_es' => 'Listón', 'price' => 5.00, 'custom_text' => null],
            ],
        ]);
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$line], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertSame(100.00, $snapshot[0]['line_total']);
    }

    // -------------------------------------------------------------------------
    // Language selection
    // -------------------------------------------------------------------------

    public function testEnglishNameSelected(): void
    {
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$this->makeLine()], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );
        $this->assertSame('Rose Bouquet', $snapshot[0]['name']);
    }

    public function testSpanishNameSelected(): void
    {
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$this->makeLine()], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'es'
        );
        $this->assertSame('Ramo de Rosas', $snapshot[0]['name']);
    }

    public function testSpanishAddonNameSelected(): void
    {
        $line = $this->makeLine([
            'addons' => [
                ['addon_id' => 1, 'name_en' => 'Ribbon', 'name_es' => 'Listón', 'price' => 5.00, 'custom_text' => null],
            ],
        ]);
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$line], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'es'
        );
        $this->assertSame('Listón', $snapshot[0]['addons'][0]['name']);
    }

    // -------------------------------------------------------------------------
    // Add-on custom text
    // -------------------------------------------------------------------------

    public function testAddonCustomTextPreserved(): void
    {
        $line = $this->makeLine([
            'addons' => [
                ['addon_id' => 2, 'name_en' => 'Card', 'name_es' => null, 'price' => 3.00, 'custom_text' => 'Happy Birthday!'],
            ],
        ]);
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$line], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertSame('Happy Birthday!', $snapshot[0]['addons'][0]['custom_text']);
    }

    // -------------------------------------------------------------------------
    // Add-on quantity
    // -------------------------------------------------------------------------

    public function testAddonQuantityPreservedAndDefaultsToOne(): void
    {
        $line = $this->makeLine([
            'addons' => [
                ['addon_id' => 5, 'name_en' => 'Chocolates', 'name_es' => null, 'price' => 2.50, 'quantity' => 4, 'custom_text' => null],
                ['addon_id' => 1, 'name_en' => 'Ribbon', 'name_es' => null, 'price' => 5.00, 'custom_text' => null],
            ],
        ]);
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [$line], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );

        $this->assertSame(4, $snapshot[0]['addons'][0]['quantity']);
        // Missing quantity defaults to 1.
        $this->assertSame(1, $snapshot[0]['addons'][1]['quantity']);
    }

    // -------------------------------------------------------------------------
    // Empty cart
    // -------------------------------------------------------------------------

    public function testEmptyItemsReturnsEmptyArray(): void
    {
        $snapshot = ShopOrderSnapshot::itemsSnapshot(
            [], $this->typeNames(), $this->colorNames(), $this->paperNames(), 'en'
        );
        $this->assertSame([], $snapshot);
    }
}
