<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\FlowerColorResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FlowerColorResolver — pure fixture-based, no DB.
 *
 * All tests exercise in-memory fixture data so they run without a database
 * connection and are fully deterministic. The fifth argument is the per-type
 * pictured-color map (flower_type_id => int[] color_ids).
 *
 * @see \App\Support\FlowerColorResolver
 */
final class FlowerColorResolverTest extends TestCase
{
    /**
     * Small fixture: two flower types — Roses (id 1) and Carnations (id 2).
     *
     * @return array<int, array> Map of id => flower_type row.
     */
    private function types(): array
    {
        return [
            1 => ['id' => 1, 'name_en' => 'Roses',     'name_es' => 'Rosas'],
            2 => ['id' => 2, 'name_en' => 'Carnations', 'name_es' => 'Claveles'],
        ];
    }

    /**
     * Small fixture: color IDs per flower type.
     *   Roses (1):      Red(1), Pink(2), White(3)
     *   Carnations (2): Red(1), White(3), Purple(4)
     *
     * @return array<int, int[]> Map of flower_type_id => color_ids.
     */
    private function colorMap(): array
    {
        return [
            1 => [1, 2, 3],
            2 => [1, 3, 4],
        ];
    }

    /**
     * Small fixture: four flower colors by ID.
     *
     * @return array<int, array> Map of id => flower_color row.
     */
    private function colors(): array
    {
        return [
            1 => ['id' => 1, 'name_en' => 'Red',    'name_es' => 'Rojo',   'hex' => '#D32F2F'],
            2 => ['id' => 2, 'name_en' => 'Pink',   'name_es' => 'Rosa',   'hex' => '#EC407A'],
            3 => ['id' => 3, 'name_en' => 'White',  'name_es' => 'Blanco', 'hex' => '#FFFFFF'],
            4 => ['id' => 4, 'name_en' => 'Purple', 'name_es' => 'Morado', 'hex' => '#8E24AA'],
        ];
    }

    /**
     * A single pictured color available for the type becomes the sole default.
     */
    public function testSinglePicturedColorAvailable(): void
    {
        // Pink (id 2) is available for Roses (type 1)
        $result = FlowerColorResolver::availableColorsForProduct(
            [1],
            $this->types(),
            $this->colorMap(),
            $this->colors(),
            [1 => [2]]
        );

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(1, $row['flower_type_id']);
        self::assertSame('Roses', $row['name_en']);
        self::assertSame([2], $row['default_color_ids']);
        self::assertFalse($row['differs_from_photo']);
        self::assertCount(3, $row['colors']);
    }

    /**
     * Multiple pictured colors of the same type are all defaulted, in the
     * type's available-color order (e.g. red + white roses).
     */
    public function testMultiplePicturedColorsAvailable(): void
    {
        // Roses pictured as Red(1) + White(3); available order is Red,Pink,White.
        $result = FlowerColorResolver::availableColorsForProduct(
            [1],
            $this->types(),
            $this->colorMap(),
            $this->colors(),
            [1 => [3, 1]] // order in the map should not matter
        );

        self::assertSame([1, 3], $result[0]['default_color_ids']);
        self::assertFalse($result[0]['differs_from_photo']);
    }

    /**
     * Pictured colors are intersected with availability — unavailable picks drop.
     */
    public function testPicturedSubsetWhenSomeUnavailable(): void
    {
        // Roses pictured Red(1) + Purple(4); Purple is not available for Roses.
        $result = FlowerColorResolver::availableColorsForProduct(
            [1],
            $this->types(),
            $this->colorMap(),
            $this->colors(),
            [1 => [1, 4]]
        );

        self::assertSame([1], $result[0]['default_color_ids']);
        self::assertFalse($result[0]['differs_from_photo']);
    }

    /**
     * When none of the pictured colors are available, the default falls back to
     * the first color for the type and differs_from_photo is true.
     */
    public function testPicturedNoneAvailableFallsBack(): void
    {
        // Carnations (type 2) has Red(1), White(3), Purple(4) — pictured Pink(2) only.
        $result = FlowerColorResolver::availableColorsForProduct(
            [2],
            $this->types(),
            $this->colorMap(),
            $this->colors(),
            [2 => [2]]
        );

        $row = $result[0];
        self::assertSame([1], $row['default_color_ids']); // first color for Carnations = Red(1)
        self::assertTrue($row['differs_from_photo']);
    }

    /**
     * With no pictured colors recorded, the default falls back to the first
     * available color and differs_from_photo is true.
     */
    public function testNoPicturedColors(): void
    {
        $result = FlowerColorResolver::availableColorsForProduct(
            [1],
            $this->types(),
            $this->colorMap(),
            $this->colors(),
            []
        );

        $row = $result[0];
        self::assertSame([1], $row['default_color_ids']); // first color = Red
        self::assertTrue($row['differs_from_photo']);
    }

    /**
     * Multiple flower types each resolve their own pictured colors independently.
     */
    public function testMultipleFlowerTypes(): void
    {
        // Roses pictured Red+White; Carnations pictured White only.
        $result = FlowerColorResolver::availableColorsForProduct(
            [1, 2],
            $this->types(),
            $this->colorMap(),
            $this->colors(),
            [1 => [1, 3], 2 => [3]]
        );

        self::assertCount(2, $result);
        self::assertSame([1, 3], $result[0]['default_color_ids']);
        self::assertFalse($result[0]['differs_from_photo']);
        self::assertSame([3], $result[1]['default_color_ids']);
        self::assertFalse($result[1]['differs_from_photo']);
    }

    /**
     * A product with no flower types produces an empty result array.
     */
    public function testNoFlowerTypes(): void
    {
        $result = FlowerColorResolver::availableColorsForProduct(
            [],
            $this->types(),
            $this->colorMap(),
            $this->colors(),
            []
        );

        self::assertSame([], $result);
    }

    /**
     * A flower type ID that does not exist in $flowerTypesById is silently skipped.
     */
    public function testUnknownFlowerTypeSkipped(): void
    {
        $result = FlowerColorResolver::availableColorsForProduct(
            [99],
            $this->types(),
            $this->colorMap(),
            $this->colors(),
            []
        );

        self::assertSame([], $result);
    }

    /**
     * When a flower type has no colors configured, default_color_ids is empty
     * and differs_from_photo is false (nothing to differ from).
     */
    public function testTypeWithNoColors(): void
    {
        $colorMap = [1 => []]; // Roses has no colors configured

        $result = FlowerColorResolver::availableColorsForProduct(
            [1],
            $this->types(),
            $colorMap,
            $this->colors(),
            [1 => [1]]
        );

        self::assertCount(1, $result);
        self::assertSame([], $result[0]['default_color_ids']);
        self::assertFalse($result[0]['differs_from_photo']);
    }

    /**
     * normalizeIdList casts to int, drops zeros and negatives, deduplicates,
     * and reindexes from 0.
     */
    public function testNormalizeIdList(): void
    {
        $raw = ['3', '0', '-1', '3', '5', 'abc', 2];
        $out = FlowerColorResolver::normalizeIdList($raw);
        sort($out);

        self::assertSame([2, 3, 5], $out);
    }

    /**
     * normalizeIdList returns an empty array for empty input.
     */
    public function testNormalizeIdListEmpty(): void
    {
        self::assertSame([], FlowerColorResolver::normalizeIdList([]));
    }

    /**
     * Each color entry in the result has the expected struct keys.
     */
    public function testColorStructKeys(): void
    {
        $result = FlowerColorResolver::availableColorsForProduct(
            [1],
            $this->types(),
            $this->colorMap(),
            $this->colors(),
            [1 => [1]]
        );

        $color = $result[0]['colors'][0];
        self::assertArrayHasKey('id', $color);
        self::assertArrayHasKey('name_en', $color);
        self::assertArrayHasKey('name_es', $color);
        self::assertArrayHasKey('hex', $color);
    }
}
