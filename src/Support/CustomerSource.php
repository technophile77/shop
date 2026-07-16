<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Customer;

/**
 * Pure resolution logic for a customer record's acquisition `source`.
 *
 * Ad attribution wins over the flow default: when the visitor's session UTM
 * data names a recognised ad channel, that channel is used regardless of
 * where in the app the customer is being created or updated. Only when no
 * recognised UTM source is present does the caller's flow-specific fallback
 * (e.g. 'shop_checkout', 'order_form') apply. The final value is always
 * checked against Customer::SOURCES before being returned, so a typo'd or
 * unmapped UTM source can never produce a value the `source` ENUM would
 * reject — it degrades to 'other' instead.
 *
 * No database access, no session access, no side effects: callers read
 * `$_SESSION['utm'] ?? []` themselves and pass it in.
 *
 * @see \App\Models\Customer   Owns the SOURCES enum this class validates against.
 * @see \App\Models\PageView   Records the UTM attribution this class consumes.
 */
final class CustomerSource
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Resolves the acquisition source to store for a customer record.
     *
     * Ad-channel UTM attribution takes priority over the flow's default
     * source. When $utm['source'] maps to a known ad channel ('google' →
     * 'google_ads'; 'facebook'/'fb' → 'facebook'; 'instagram'/'ig' →
     * 'instagram'), that channel is returned. Otherwise — including when the
     * UTM source is empty, absent, or unrecognised — $fallback is used. The
     * chosen value is always validated against Customer::SOURCES; a value
     * outside that list is replaced with 'other' so the ENUM column can never
     * be handed a value it would reject.
     *
     * @param array  $utm      The visitor's first-touch attribution, keyed as
     *                         source, medium, campaign, content, term. Only
     *                         'source' is read.
     * @param string $fallback The flow's default source when no ad attribution
     *                         is present (e.g. 'shop_checkout' for cart
     *                         checkout, 'order_form' for the contact form).
     *
     * @return string A member of Customer::SOURCES.
     *
     * @example
     *   CustomerSource::resolve(['source' => 'google'], 'shop_checkout'); // 'google_ads'
     * @example
     *   CustomerSource::resolve([], 'order_form'); // 'order_form'
     */
    public static function resolve(array $utm, string $fallback): string
    {
        $utmSource = strtolower(trim((string) ($utm['source'] ?? '')));

        $mapped = match ($utmSource) {
            'google'              => 'google_ads',
            'facebook', 'fb'      => 'facebook',
            'instagram', 'ig'     => 'instagram',
            default               => null,
        };

        $source = $mapped ?? $fallback;

        return in_array($source, Customer::SOURCES, true) ? $source : 'other';
    }
}
