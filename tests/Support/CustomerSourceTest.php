<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Models\Customer;
use App\Support\CustomerSource;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure acquisition-source resolution in
 * App\Support\CustomerSource.
 *
 * Covers the ad-attribution-wins-over-fallback rule, case/whitespace
 * normalisation of the UTM source, the invalid-fallback safety net, and a
 * full round-trip through Customer::SOURCES so the two stay in sync.
 *
 * @see \App\Support\CustomerSource
 */
final class CustomerSourceTest extends TestCase
{
    public function testGoogleUtmSourceMapsToGoogleAds(): void
    {
        self::assertSame('google_ads', CustomerSource::resolve(['source' => 'google'], 'shop_checkout'));
    }

    public function testGoogleUtmSourceIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame('google_ads', CustomerSource::resolve(['source' => 'GOOGLE'], 'shop_checkout'));
        self::assertSame('google_ads', CustomerSource::resolve(['source' => ' Google '], 'shop_checkout'));
    }

    public function testFacebookAliasesMapToFacebook(): void
    {
        self::assertSame('facebook', CustomerSource::resolve(['source' => 'fb'], 'order_form'));
        self::assertSame('facebook', CustomerSource::resolve(['source' => 'facebook'], 'order_form'));
    }

    public function testInstagramAliasesMapToInstagram(): void
    {
        self::assertSame('instagram', CustomerSource::resolve(['source' => 'ig'], 'order_form'));
        self::assertSame('instagram', CustomerSource::resolve(['source' => 'instagram'], 'order_form'));
    }

    public function testUnknownUtmSourceFallsBackToFlowDefault(): void
    {
        self::assertSame('order_form', CustomerSource::resolve(['source' => 'newsletter'], 'order_form'));
    }

    public function testEmptyUtmArrayFallsBackToFlowDefault(): void
    {
        self::assertSame('order_form', CustomerSource::resolve([], 'order_form'));
    }

    public function testEmptyStringUtmSourceFallsBackToFlowDefault(): void
    {
        self::assertSame('shop_checkout', CustomerSource::resolve(['source' => ''], 'shop_checkout'));
    }

    public function testInvalidFallbackDegradesToOther(): void
    {
        self::assertSame('other', CustomerSource::resolve([], 'not_a_real_source'));
    }

    public function testMappedOutputsAreAllValidCustomerSources(): void
    {
        self::assertContains('google_ads', Customer::SOURCES);
        self::assertContains('facebook', Customer::SOURCES);
        self::assertContains('instagram', Customer::SOURCES);
    }

    /**
     * Every member of Customer::SOURCES must pass through resolve() unchanged
     * when used as the fallback with no UTM attribution present. This guards
     * against future drift between the ENUM (Customer::SOURCES) and this
     * class's validation guard.
     */
    public function testEveryCustomerSourceRoundTripsAsFallback(): void
    {
        foreach (Customer::SOURCES as $source) {
            self::assertSame($source, CustomerSource::resolve([], $source));
        }
    }
}
