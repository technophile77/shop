<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\SalesLineClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SalesLineClassifier::classify() and ::isFee().
 *
 * Covers each pattern category (delivery, other-fee, merchandise default),
 * the precedence of explicit overrides over pattern matching, the documented
 * false-positive limitation for a product name containing a fee word (and how
 * the override map corrects it), and that every classification carries a
 * non-empty audit reason.
 *
 * @see \App\Support\SalesLineClassifier
 */
class SalesLineClassifierTest extends TestCase
{
    /**
     * A range of common delivery-fee wordings all classify as DELIVERY.
     */
    public function testDeliveryWordingsClassifyAsDelivery(): void
    {
        foreach (['Delivery', 'delivery fee', 'Local Delivery', 'Drop-off', 'Courier', 'Shipping'] as $description) {
            $result = SalesLineClassifier::classify($description);
            $this->assertSame(SalesLineClassifier::DELIVERY, $result['bucket'], $description);
        }
    }

    /**
     * Plain product names with no fee wording classify as MERCHANDISE.
     */
    public function testProductNamesClassifyAsMerchandise(): void
    {
        foreach (['Roses', 'Ribbon', 'Tulips', 'Vase'] as $description) {
            $result = SalesLineClassifier::classify($description);
            $this->assertSame(SalesLineClassifier::MERCHANDISE, $result['bucket'], $description);
        }
    }

    /**
     * Non-delivery fee wordings classify as OTHER_FEE.
     */
    public function testFeeWordingsClassifyAsOtherFee(): void
    {
        foreach (['Setup fee', 'Gratuity', 'Rush order'] as $description) {
            $result = SalesLineClassifier::classify($description);
            $this->assertSame(SalesLineClassifier::OTHER_FEE, $result['bucket'], $description);
        }
    }

    /**
     * Known limitation: a product whose name happens to contain "Delivery"
     * misclassifies as DELIVERY when text is the only signal available. This
     * is documented (not silently accepted) because it's exactly why the
     * override map and the per-line audit listing exist — this test also
     * proves an override entry cleanly wins over the pattern match.
     */
    public function testProductNameContainingFeeWordMisclassifiesWithoutOverride(): void
    {
        $result = SalesLineClassifier::classify('Rose Delivery Bouquet');

        $this->assertSame(SalesLineClassifier::DELIVERY, $result['bucket']);
    }

    /**
     * With an override entry keyed to the specific line, the same description
     * correctly returns MERCHANDISE instead of the pattern-matched DELIVERY.
     */
    public function testOverrideCorrectsFalsePositive(): void
    {
        $overrides = ['quote-42:1' => SalesLineClassifier::MERCHANDISE];

        $result = SalesLineClassifier::classify('Rose Delivery Bouquet', 'quote-42:1', $overrides);

        $this->assertSame(SalesLineClassifier::MERCHANDISE, $result['bucket']);
        $this->assertSame('explicit override', $result['reason']);
    }

    /**
     * An override key that isn't present in the map is ignored, falling
     * through to the pattern rules as normal.
     */
    public function testUnmatchedOverrideKeyFallsThroughToPatterns(): void
    {
        $overrides = ['quote-42:1' => SalesLineClassifier::MERCHANDISE];

        $result = SalesLineClassifier::classify('Delivery', 'quote-99:0', $overrides);

        $this->assertSame(SalesLineClassifier::DELIVERY, $result['bucket']);
    }

    /**
     * Every classification, across all buckets, carries a non-empty reason
     * string so the split is auditable by a human.
     */
    public function testReasonIsAlwaysNonEmpty(): void
    {
        $descriptions = ['Delivery', 'Roses', 'Setup fee', 'Rose Delivery Bouquet', 'Anything else'];

        foreach ($descriptions as $description) {
            $result = SalesLineClassifier::classify($description);
            $this->assertNotSame('', $result['reason'], $description);
            $this->assertIsString($result['reason']);
        }
    }

    /**
     * isFee() is true for DELIVERY and OTHER_FEE, false for MERCHANDISE.
     */
    public function testIsFee(): void
    {
        $this->assertTrue(SalesLineClassifier::isFee(SalesLineClassifier::DELIVERY));
        $this->assertTrue(SalesLineClassifier::isFee(SalesLineClassifier::OTHER_FEE));
        $this->assertFalse(SalesLineClassifier::isFee(SalesLineClassifier::MERCHANDISE));
    }
}
