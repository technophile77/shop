<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\CartValidation;
use App\Support\Ribbon;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for App\Support\CartValidation — pure, fixture-based, no DB.
 *
 * Exercises every validation rule: qty, per-type color validity, mixed/single
 * cardinality, paper color validity, add-on existence, and ribbon text limits.
 *
 * @see \App\Support\CartValidation
 * @see \App\Support\Ribbon
 */
final class CartValidationTest extends TestCase
{
    /**
     * Standard catalog context: a product using flower type 1 (colors 1,2,3),
     * paper colors 10,11, a ribbon add-on (id 2, has_custom_text) and a plain
     * add-on (id 3), and a 20-stem flower count (ribbon limit 12).
     *
     * @return array<string, mixed>
     */
    private function context(int $flowerCount = 20): array
    {
        return [
            'productFlowerTypeIds' => [1],
            'flowerTypeColorMap'   => [1 => [1, 2, 3]],
            'paperColorIds'        => [10, 11],
            'addonsById'           => [
                2 => ['id' => 2, 'has_custom_text' => 1, 'has_quantity' => 0, 'price' => 5.0],
                3 => ['id' => 3, 'has_custom_text' => 0, 'has_quantity' => 0, 'price' => 3.0],
                4 => ['id' => 4, 'has_custom_text' => 0, 'has_quantity' => 1, 'price' => 2.5],
            ],
            'flowerCount'          => $flowerCount,
        ];
    }

    /**
     * A fully valid selection produces no errors.
     */
    public function testValidSelection(): void
    {
        $selection = [
            'product_id'     => 5,
            'qty'            => 1,
            'paper_color_id' => 10,
            'colors'         => [['flower_type_id' => 1, 'color_ids' => [2], 'mixed' => false]],
            'addons'         => [['addon_id' => 2, 'custom_text' => 'Happy Day']],
        ];
        self::assertSame([], CartValidation::validateSelection($selection, $this->context()));
    }

    /**
     * A quantity below 1 is rejected.
     */
    public function testQtyBelowOne(): void
    {
        $selection = ['qty' => 0, 'colors' => [], 'addons' => []];
        $errors = CartValidation::validateSelection($selection, $this->context());
        self::assertNotEmpty($errors);
    }

    /**
     * A color not available for the flower type is rejected.
     */
    public function testInvalidColorForType(): void
    {
        $selection = [
            'qty'    => 1,
            'colors' => [['flower_type_id' => 1, 'color_ids' => [99], 'mixed' => false]],
            'addons' => [],
        ];
        $errors = CartValidation::validateSelection($selection, $this->context());
        self::assertNotEmpty($errors);
    }

    /**
     * A flower type the product does not use is rejected.
     */
    public function testInvalidFlowerType(): void
    {
        $selection = [
            'qty'    => 1,
            'colors' => [['flower_type_id' => 7, 'color_ids' => [1], 'mixed' => false]],
            'addons' => [],
        ];
        $errors = CartValidation::validateSelection($selection, $this->context());
        self::assertNotEmpty($errors);
    }

    /**
     * mixed = true with only one color is rejected.
     */
    public function testMixedRequiresTwoColors(): void
    {
        $selection = [
            'qty'    => 1,
            'colors' => [['flower_type_id' => 1, 'color_ids' => [1], 'mixed' => true]],
            'addons' => [],
        ];
        self::assertNotEmpty(CartValidation::validateSelection($selection, $this->context()));
    }

    /**
     * mixed = false with two colors is rejected (single must be exactly one).
     */
    public function testSingleRejectsTwoColors(): void
    {
        $selection = [
            'qty'    => 1,
            'colors' => [['flower_type_id' => 1, 'color_ids' => [1, 2], 'mixed' => false]],
            'addons' => [],
        ];
        self::assertNotEmpty(CartValidation::validateSelection($selection, $this->context()));
    }

    /**
     * A mixed selection of two valid colors passes.
     */
    public function testMixedTwoColorsValid(): void
    {
        $selection = [
            'qty'    => 1,
            'colors' => [['flower_type_id' => 1, 'color_ids' => [1, 2], 'mixed' => true]],
            'addons' => [],
        ];
        self::assertSame([], CartValidation::validateSelection($selection, $this->context()));
    }

    /**
     * An unknown paper color is rejected.
     */
    public function testInvalidPaperColor(): void
    {
        $selection = [
            'qty'            => 1,
            'paper_color_id' => 999,
            'colors'         => [],
            'addons'         => [],
        ];
        self::assertNotEmpty(CartValidation::validateSelection($selection, $this->context()));
    }

    /**
     * An unknown add-on is rejected.
     */
    public function testUnknownAddon(): void
    {
        $selection = ['qty' => 1, 'colors' => [], 'addons' => [['addon_id' => 999]]];
        self::assertNotEmpty(CartValidation::validateSelection($selection, $this->context()));
    }

    /**
     * Custom text on a non-custom-text add-on is rejected.
     */
    public function testCustomTextOnPlainAddon(): void
    {
        $selection = ['qty' => 1, 'colors' => [], 'addons' => [['addon_id' => 3, 'custom_text' => 'Nope']]];
        self::assertNotEmpty(CartValidation::validateSelection($selection, $this->context()));
    }

    /**
     * Ribbon text exactly at the limit passes; one over fails.
     */
    public function testRibbonTextAtAndOverLimit(): void
    {
        $limit = Ribbon::ribbonCharLimit(20); // 12
        $atLimit = [
            'qty'    => 1, 'colors' => [],
            'addons' => [['addon_id' => 2, 'custom_text' => str_repeat('a', $limit)]],
        ];
        self::assertSame([], CartValidation::validateSelection($atLimit, $this->context(20)));

        $overLimit = [
            'qty'    => 1, 'colors' => [],
            'addons' => [['addon_id' => 2, 'custom_text' => str_repeat('a', $limit + 1)]],
        ];
        self::assertNotEmpty(CartValidation::validateSelection($overLimit, $this->context(20)));
    }

    /**
     * A quantity-enabled add-on accepts a quantity in the 1..99 range.
     */
    public function testQuantityAddonValid(): void
    {
        $sel = ['qty' => 1, 'colors' => [], 'addons' => [['addon_id' => 4, 'quantity' => 5]]];
        self::assertSame([], CartValidation::validateSelection($sel, $this->context()));
    }

    /**
     * A quantity other than 1 on a non-quantity add-on is rejected.
     */
    public function testQuantityOnNonQuantityAddonRejected(): void
    {
        $sel = ['qty' => 1, 'colors' => [], 'addons' => [['addon_id' => 3, 'quantity' => 2]]];
        self::assertNotEmpty(CartValidation::validateSelection($sel, $this->context()));
    }

    /**
     * Quantity below 1 and above 99 are both rejected.
     */
    public function testQuantityOutOfRangeRejected(): void
    {
        $below = ['qty' => 1, 'colors' => [], 'addons' => [['addon_id' => 4, 'quantity' => 0]]];
        self::assertNotEmpty(CartValidation::validateSelection($below, $this->context()));

        $above = ['qty' => 1, 'colors' => [], 'addons' => [['addon_id' => 4, 'quantity' => 100]]];
        self::assertNotEmpty(CartValidation::validateSelection($above, $this->context()));

        $max = ['qty' => 1, 'colors' => [], 'addons' => [['addon_id' => 4, 'quantity' => 99]]];
        self::assertSame([], CartValidation::validateSelection($max, $this->context()));
    }
}
