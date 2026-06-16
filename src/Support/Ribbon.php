<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure helper for ribbon/custom-text character limits on bouquet add-ons.
 *
 * The limit scales with bouquet size so smaller arrangements cannot display
 * as much text as larger ones. No database access — all inputs are primitives.
 *
 * @see \App\Support\CartValidation::validateSelection()  Consumes ribbonCharLimit().
 */
final class Ribbon
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Return the maximum number of characters allowed on a ribbon for a bouquet.
     *
     * The formula is:
     *   max(10, min(15, 10 + intdiv(max(0, $flowerCount), 10)))
     *
     * Negative flower counts are treated as zero. The result is always in the
     * inclusive range [10, 15].
     *
     * @param int $flowerCount Number of flower stems in the bouquet.
     *
     * @return int Character limit in the range [10, 15].
     *
     * @example
     *   Ribbon::ribbonCharLimit(0);   // 10
     *   Ribbon::ribbonCharLimit(10);  // 11
     *   Ribbon::ribbonCharLimit(49);  // 14
     *   Ribbon::ribbonCharLimit(50);  // 15
     *   Ribbon::ribbonCharLimit(100); // 15
     */
    public static function ribbonCharLimit(int $flowerCount): int
    {
        return max(10, min(15, 10 + intdiv(max(0, $flowerCount), 10)));
    }
}
