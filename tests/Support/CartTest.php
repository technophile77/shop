<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Cart;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Support\Cart — pure cart mutation logic, no session/DB.
 *
 * Covers signature stability/merging, add (new + merge + distinct), updateQty
 * (including remove-on-zero), remove, and totalQuantity.
 *
 * @see \App\Support\Cart
 */
final class CartTest extends TestCase
{
    /**
     * Build a representative line item; overrides merge over the defaults.
     *
     * @param array<string, mixed> $overrides Keys to override on the base line.
     * @return array<string, mixed>
     */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'product_id'     => 5,
            'name_en'        => 'Garden Bouquet',
            'name_es'        => null,
            'image_path'     => null,
            'unit_price'     => 45.00,
            'flower_count'   => 20,
            'qty'            => 1,
            'paper_color_id' => 2,
            'colors'         => [['flower_type_id' => 1, 'color_ids' => [3, 1], 'mixed' => true]],
            'addons'         => [['addon_id' => 2, 'price' => 5.0, 'custom_text' => 'Hi']],
        ], $overrides);
    }

    /**
     * The signature is deterministic regardless of color_id order within a type.
     */
    public function testSignatureIsOrderIndependentForColors(): void
    {
        $a = Cart::signature($this->line(['colors' => [['flower_type_id' => 1, 'color_ids' => [1, 3], 'mixed' => true]]]));
        $b = Cart::signature($this->line(['colors' => [['flower_type_id' => 1, 'color_ids' => [3, 1], 'mixed' => true]]]));
        self::assertSame($a, $b);
    }

    /**
     * Differing paper color produces a different signature.
     */
    public function testSignatureDiffersOnPaperColor(): void
    {
        $a = Cart::signature($this->line(['paper_color_id' => 2]));
        $b = Cart::signature($this->line(['paper_color_id' => 3]));
        self::assertNotSame($a, $b);
    }

    /**
     * Differing ribbon custom text produces a different signature.
     */
    public function testSignatureDiffersOnCustomText(): void
    {
        $a = Cart::signature($this->line(['addons' => [['addon_id' => 2, 'custom_text' => 'Hi']]]));
        $b = Cart::signature($this->line(['addons' => [['addon_id' => 2, 'custom_text' => 'Bye']]]));
        self::assertNotSame($a, $b);
    }

    /**
     * Adding to an empty cart appends one line and stamps the signature.
     */
    public function testAddToEmptyCart(): void
    {
        $items = Cart::add([], $this->line());
        self::assertCount(1, $items);
        self::assertArrayHasKey('signature', $items[0]);
        self::assertSame(1, $items[0]['qty']);
    }

    /**
     * Adding an identical configuration merges quantities rather than appending.
     */
    public function testAddMergesIdenticalConfig(): void
    {
        $items = Cart::add([], $this->line(['qty' => 1]));
        $items = Cart::add($items, $this->line(['qty' => 2]));
        self::assertCount(1, $items);
        self::assertSame(3, $items[0]['qty']);
    }

    /**
     * Adding a differing configuration appends a separate line.
     */
    public function testAddDistinctConfigAppends(): void
    {
        $items = Cart::add([], $this->line());
        $items = Cart::add($items, $this->line(['paper_color_id' => 99]));
        self::assertCount(2, $items);
    }

    /**
     * updateQty sets a new quantity on the matching line.
     */
    public function testUpdateQty(): void
    {
        $items = Cart::add([], $this->line());
        $sig   = $items[0]['signature'];
        $items = Cart::updateQty($items, $sig, 4);
        self::assertSame(4, $items[0]['qty']);
    }

    /**
     * updateQty with a non-positive quantity removes the line.
     */
    public function testUpdateQtyZeroRemoves(): void
    {
        $items = Cart::add([], $this->line());
        $sig   = $items[0]['signature'];
        $items = Cart::updateQty($items, $sig, 0);
        self::assertSame([], $items);
    }

    /**
     * remove drops the matching line and re-indexes.
     */
    public function testRemove(): void
    {
        $items = Cart::add([], $this->line());
        $items = Cart::add($items, $this->line(['paper_color_id' => 99]));
        $sig   = $items[0]['signature'];
        $items = Cart::remove($items, $sig);
        self::assertCount(1, $items);
        self::assertSame(0, array_key_first($items));
    }

    /**
     * totalQuantity sums qty across all lines.
     */
    public function testTotalQuantity(): void
    {
        self::assertSame(0, Cart::totalQuantity([]));
        $items = Cart::add([], $this->line(['qty' => 2]));
        $items = Cart::add($items, $this->line(['paper_color_id' => 99, 'qty' => 3]));
        self::assertSame(5, Cart::totalQuantity($items));
    }

    /**
     * The input items array is never mutated by add/remove/updateQty.
     */
    public function testOperationsDoNotMutateInput(): void
    {
        $original = Cart::add([], $this->line());
        $snapshot = $original;
        Cart::add($original, $this->line(['paper_color_id' => 7]));
        Cart::updateQty($original, $original[0]['signature'], 9);
        Cart::remove($original, $original[0]['signature']);
        self::assertEquals($snapshot, $original);
    }
}
