<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\MerchantProduct;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MerchantProduct::eligible() and ::toProductInput().
 *
 * Verifies the eligibility predicate (active + priced), the productInput shape,
 * stable offerId, per-language title/description/link selection with English
 * fallback, the micros price encoding (using price_from even for a range),
 * placeholder-image substitution, and the constant identity fields.
 *
 * @see \App\Support\MerchantProduct
 */
class MerchantProductTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** Non-row config passed to toProductInput(). */
    private function cfg(): array
    {
        return [
            'app_url'           => 'https://flowers.cresswell.org',
            'brand'             => "Perla's Flowers",
            'google_category'   => 'Home & Garden > Decor > Flowers',
            'placeholder_image' => 'https://flowers.cresswell.org/public/img/placeholder.jpg',
        ];
    }

    /** Build a minimal product row; override any field via $overrides. */
    private function makeProduct(array $overrides = []): array
    {
        return array_merge([
            'id'             => 12,
            'category_id'    => 2,
            'name_en'        => 'Whisper of Love',
            'name_es'        => 'Susurro de Amor',
            'description_en' => 'A dozen red roses.',
            'description_es' => 'Una docena de rosas rojas.',
            'image_path'     => 'abc123.jpg',
            'price_from'     => 45.00,
            'price_to'       => null,
            'featured'       => 0,
            'active'         => 1,
            'sort_order'     => 0,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // eligible()
    // -------------------------------------------------------------------------

    public function testEligibleActiveAndPriced(): void
    {
        $this->assertTrue(MerchantProduct::eligible($this->makeProduct()));
    }

    public function testEligibleFalseWhenInactive(): void
    {
        $this->assertFalse(MerchantProduct::eligible($this->makeProduct(['active' => 0])));
    }

    public function testEligibleFalseWhenPriceZero(): void
    {
        $this->assertFalse(MerchantProduct::eligible($this->makeProduct(['price_from' => 0])));
    }

    public function testEligibleFalseWhenPriceNull(): void
    {
        $this->assertFalse(MerchantProduct::eligible($this->makeProduct(['price_from' => null])));
    }

    public function testEligibleFalseWhenPriceMissing(): void
    {
        $product = $this->makeProduct();
        unset($product['price_from']);

        $this->assertFalse(MerchantProduct::eligible($product));
    }

    public function testEligibleFalseWhenActiveMissing(): void
    {
        $product = $this->makeProduct();
        unset($product['active']);

        $this->assertFalse(MerchantProduct::eligible($product));
    }

    // -------------------------------------------------------------------------
    // toProductInput() — identity / constant fields
    // -------------------------------------------------------------------------

    public function testOfferIdIsProdDashId(): void
    {
        $result = MerchantProduct::toProductInput($this->makeProduct(['id' => 7]), 'en', $this->cfg());
        $this->assertSame('prod-7', $result['offerId']);
    }

    public function testOfferIdStableAcrossLanguages(): void
    {
        $en = MerchantProduct::toProductInput($this->makeProduct(), 'en', $this->cfg());
        $es = MerchantProduct::toProductInput($this->makeProduct(), 'es', $this->cfg());
        $this->assertSame($en['offerId'], $es['offerId']);
    }

    public function testConstantIdentityFields(): void
    {
        $result = MerchantProduct::toProductInput($this->makeProduct(), 'en', $this->cfg());
        $this->assertSame('ONLINE', $result['channel']);
        $this->assertSame('US', $result['feedLabel']);
    }

    public function testContentLanguageMatchesLangEn(): void
    {
        $result = MerchantProduct::toProductInput($this->makeProduct(), 'en', $this->cfg());
        $this->assertSame('en', $result['contentLanguage']);
    }

    public function testContentLanguageMatchesLangEs(): void
    {
        $result = MerchantProduct::toProductInput($this->makeProduct(), 'es', $this->cfg());
        $this->assertSame('es', $result['contentLanguage']);
    }

    public function testIdentifierExistsIsFalse(): void
    {
        $result = MerchantProduct::toProductInput($this->makeProduct(), 'en', $this->cfg());
        $this->assertFalse($result['productAttributes']['identifierExists']);
    }

    public function testStaticAttributes(): void
    {
        $attrs = MerchantProduct::toProductInput($this->makeProduct(), 'en', $this->cfg())['productAttributes'];
        $this->assertSame('in_stock', $attrs['availability']);
        $this->assertSame('new', $attrs['condition']);
        $this->assertSame("Perla's Flowers", $attrs['brand']);
        $this->assertSame('Home & Garden > Decor > Flowers', $attrs['googleProductCategory']);
    }

    // -------------------------------------------------------------------------
    // toProductInput() — language selection
    // -------------------------------------------------------------------------

    public function testEnglishTitleAndDescription(): void
    {
        $attrs = MerchantProduct::toProductInput($this->makeProduct(), 'en', $this->cfg())['productAttributes'];
        $this->assertSame('Whisper of Love', $attrs['title']);
        $this->assertSame('A dozen red roses.', $attrs['description']);
    }

    public function testSpanishTitleAndDescription(): void
    {
        $attrs = MerchantProduct::toProductInput($this->makeProduct(), 'es', $this->cfg())['productAttributes'];
        $this->assertSame('Susurro de Amor', $attrs['title']);
        $this->assertSame('Una docena de rosas rojas.', $attrs['description']);
    }

    public function testEnglishLinkUsesEnSegment(): void
    {
        $attrs = MerchantProduct::toProductInput($this->makeProduct(), 'en', $this->cfg())['productAttributes'];
        $this->assertSame(
            'https://flowers.cresswell.org/en/flowers/whisper-of-love-12',
            $attrs['link']
        );
    }

    public function testSpanishLinkUsesEsSegment(): void
    {
        $attrs = MerchantProduct::toProductInput($this->makeProduct(), 'es', $this->cfg())['productAttributes'];
        $this->assertSame(
            'https://flowers.cresswell.org/es/flowers/whisper-of-love-12',
            $attrs['link']
        );
    }

    public function testSpanishTitleFallsBackToEnglishWhenMissing(): void
    {
        $product = $this->makeProduct();
        unset($product['name_es']);

        $attrs = MerchantProduct::toProductInput($product, 'es', $this->cfg())['productAttributes'];
        $this->assertSame('Whisper of Love', $attrs['title']);
    }

    public function testSpanishTitleFallsBackToEnglishWhenBlank(): void
    {
        $attrs = MerchantProduct::toProductInput(
            $this->makeProduct(['name_es' => '']),
            'es',
            $this->cfg()
        )['productAttributes'];
        $this->assertSame('Whisper of Love', $attrs['title']);
    }

    public function testDescriptionFallsBackToEnglishWhenLangBlank(): void
    {
        $attrs = MerchantProduct::toProductInput(
            $this->makeProduct(['description_es' => '']),
            'es',
            $this->cfg()
        )['productAttributes'];
        $this->assertSame('A dozen red roses.', $attrs['description']);
    }

    public function testDescriptionGeneratedBlurbWhenAllBlank(): void
    {
        $attrs = MerchantProduct::toProductInput(
            $this->makeProduct(['description_en' => '', 'description_es' => '']),
            'en',
            $this->cfg()
        )['productAttributes'];
        $this->assertSame("Whisper of Love — handcrafted by Perla's Flowers.", $attrs['description']);
    }

    // -------------------------------------------------------------------------
    // toProductInput() — price
    // -------------------------------------------------------------------------

    public function testPriceAmountMicrosAndCurrency(): void
    {
        $attrs = MerchantProduct::toProductInput(
            $this->makeProduct(['price_from' => 45.00]),
            'en',
            $this->cfg()
        )['productAttributes'];

        $this->assertSame('45000000', $attrs['price']['amountMicros']);
        $this->assertSame('USD', $attrs['price']['currencyCode']);
    }

    public function testAmountMicrosIsString(): void
    {
        $attrs = MerchantProduct::toProductInput(
            $this->makeProduct(['price_from' => 45.00]),
            'en',
            $this->cfg()
        )['productAttributes'];

        $this->assertIsString($attrs['price']['amountMicros']);
    }

    public function testPriceRangeUsesPriceFrom(): void
    {
        $attrs = MerchantProduct::toProductInput(
            $this->makeProduct(['price_from' => 45.00, 'price_to' => 75.00]),
            'en',
            $this->cfg()
        )['productAttributes'];

        // Uses the FROM price, not the TO price, even when a range exists.
        $this->assertSame('45000000', $attrs['price']['amountMicros']);
    }

    public function testPriceWithCentsRoundsToMicros(): void
    {
        $attrs = MerchantProduct::toProductInput(
            $this->makeProduct(['price_from' => 39.99]),
            'en',
            $this->cfg()
        )['productAttributes'];

        $this->assertSame('39990000', $attrs['price']['amountMicros']);
    }

    // -------------------------------------------------------------------------
    // toProductInput() — image
    // -------------------------------------------------------------------------

    public function testImageLinkBuiltFromImagePath(): void
    {
        $attrs = MerchantProduct::toProductInput($this->makeProduct(), 'en', $this->cfg())['productAttributes'];
        $this->assertSame(
            'https://flowers.cresswell.org/public/uploads/products/abc123.jpg',
            $attrs['imageLink']
        );
    }

    public function testMissingImageUsesPlaceholder(): void
    {
        $attrs = MerchantProduct::toProductInput(
            $this->makeProduct(['image_path' => null]),
            'en',
            $this->cfg()
        )['productAttributes'];

        $this->assertSame(
            'https://flowers.cresswell.org/public/img/placeholder.jpg',
            $attrs['imageLink']
        );
    }

    public function testBlankImageUsesPlaceholder(): void
    {
        $attrs = MerchantProduct::toProductInput(
            $this->makeProduct(['image_path' => '']),
            'en',
            $this->cfg()
        )['productAttributes'];

        $this->assertSame(
            'https://flowers.cresswell.org/public/img/placeholder.jpg',
            $attrs['imageLink']
        );
    }

    public function testAppUrlTrailingSlashTrimmed(): void
    {
        $cfg = $this->cfg();
        $cfg['app_url'] = 'https://flowers.cresswell.org/';

        $attrs = MerchantProduct::toProductInput($this->makeProduct(), 'en', $cfg)['productAttributes'];
        $this->assertSame(
            'https://flowers.cresswell.org/en/flowers/whisper-of-love-12',
            $attrs['link']
        );
        $this->assertSame(
            'https://flowers.cresswell.org/public/uploads/products/abc123.jpg',
            $attrs['imageLink']
        );
    }
}
