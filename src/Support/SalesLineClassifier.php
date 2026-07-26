<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure, side-effect-free classifier that buckets a quote line-item description
 * into merchandise, delivery, or another kind of fee.
 *
 * Custom quotes have no dedicated delivery column for most of their history
 * (see `migrations/018_add_quote_delivery_fee.sql`) — delivery, rush charges,
 * and similar fees were hand-typed as free-text line descriptions inside
 * `quotes.items_json`, mixed in with the actual flowers. Splitting flower
 * sales from fees for the weekly sales report therefore requires guessing
 * from the text, which is inherently a heuristic: a bouquet can legitimately
 * be named "Rose Delivery Bouquet" and will be misread as a delivery fee.
 *
 * Because the heuristic can be wrong, every classification carries a `reason`
 * string so a human reviewing the report's line-level audit listing can see
 * *why* a line landed where it did, and the {@see self::OVERRIDES} map is the
 * escape hatch for correcting a specific misclassification without touching
 * the pattern rules that work for everything else.
 *
 * Classification order (first match wins):
 *   1. Explicit override, keyed by `"{source_id}:{line_index}"` — a human
 *      correction of a specific line.
 *   2. Delivery patterns — "deliver(y/ies/ed)", "drop off/drop-off/dropoff",
 *      "courier", "shipping", "freight", "mileage", "travel fee".
 *   3. Other-fee patterns — "setup fee"/"set-up", "service fee", "rush",
 *      "gratuity", "tip", "surcharge".
 *   4. Default: merchandise.
 *
 * No database access, no side effects.
 *
 * @see \App\Support\QuoteSalesBreakdown Consumes classify() for every line of a quote.
 */
final class SalesLineClassifier
{
    /** A flower, vase, ribbon, or other physical product sold to the customer. */
    public const MERCHANDISE = 'merchandise';

    /** A delivery, courier, or shipping charge. */
    public const DELIVERY = 'delivery';

    /** A non-delivery fee: setup, rush, gratuity, surcharge, and similar. */
    public const OTHER_FEE = 'other_fee';

    /**
     * Human corrections for lines the pattern rules get wrong.
     *
     * Keyed by `"{source_id}:{line_index}"` — the quote id (or other source
     * id) and the zero-based index of the line within `items_json` — mapping
     * to the correct bucket constant. This is the escape hatch for lines like
     * a bouquet literally named "Rose Delivery Bouquet", which the delivery
     * pattern below will otherwise misclassify: add an entry here rather than
     * trying to make the regex cleverer, since no regex can distinguish that
     * case from an actual delivery charge worded the same way.
     *
     * Example entry (kept commented out — add real corrections as they're found):
     *   'quote-42:1' => self::MERCHANDISE, // "Rose Delivery Bouquet" is a product, not a fee
     *
     * @var array<string, string>
     */
    private const OVERRIDES = [
        // 'quote-42:1' => self::MERCHANDISE, // "Rose Delivery Bouquet" is a product, not a fee
    ];

    /**
     * Case-insensitive, word-boundary regexes matching delivery-fee wording.
     *
     * @var list<string>
     */
    private const DELIVERY_PATTERNS = [
        '/\bdeliver(?:y|ies|ed)?\b/i',
        '/\bdrop[\s-]?off\b/i',
        '/\bcourier\b/i',
        '/\bshipping\b/i',
        '/\bfreight\b/i',
        '/\bmileage\b/i',
        '/\btravel fee\b/i',
    ];

    /**
     * Case-insensitive, word-boundary regexes matching non-delivery fee wording.
     *
     * @var list<string>
     */
    private const OTHER_FEE_PATTERNS = [
        '/\bset[\s-]?up fee\b/i',
        '/\bset-up\b/i',
        '/\bservice fee\b/i',
        '/\brush\b/i',
        '/\bgratuity\b/i',
        '/\btip\b/i',
        '/\bsurcharge\b/i',
    ];

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Classify a single quote line description into a revenue bucket.
     *
     * @param string      $description The line's free-text description, e.g. 'Delivery'.
     * @param string|null $overrideKey Lookup key for {@see self::OVERRIDES}, in the
     *                                 form `"{source_id}:{line_index}"`, e.g.
     *                                 `'quote-19:1'`. Pass null when there is no
     *                                 source/index to key on (the override lookup
     *                                 is then skipped).
     * @param array<string, string> $overrides Override map to consult; defaults to
     *                                 {@see self::OVERRIDES}. Overridable so tests
     *                                 can exercise the override path without
     *                                 mutating the class constant.
     *
     * @return array{bucket: string, reason: string} The bucket constant the line
     *         belongs in, and a short human-readable explanation of why — the
     *         audit trail that lets a human sanity-check the heuristic.
     *
     * @example
     *   SalesLineClassifier::classify('Delivery');
     *   // ['bucket' => 'delivery', 'reason' => 'matched delivery pattern "/\bdeliver(?:y|ies|ed)?\b/i"']
     *
     *   SalesLineClassifier::classify('Rose Delivery Bouquet', 'quote-42:1', [
     *       'quote-42:1' => SalesLineClassifier::MERCHANDISE,
     *   ]);
     *   // ['bucket' => 'merchandise', 'reason' => 'explicit override']
     */
    public static function classify(
        string $description,
        ?string $overrideKey = null,
        array $overrides = self::OVERRIDES,
    ): array {
        if ($overrideKey !== null && array_key_exists($overrideKey, $overrides)) {
            return [
                'bucket' => $overrides[$overrideKey],
                'reason' => 'explicit override',
            ];
        }

        foreach (self::DELIVERY_PATTERNS as $pattern) {
            if (preg_match($pattern, $description) === 1) {
                return [
                    'bucket' => self::DELIVERY,
                    'reason' => sprintf('matched delivery pattern "%s"', $pattern),
                ];
            }
        }

        foreach (self::OTHER_FEE_PATTERNS as $pattern) {
            if (preg_match($pattern, $description) === 1) {
                return [
                    'bucket' => self::OTHER_FEE,
                    'reason' => sprintf('matched other-fee pattern "%s"', $pattern),
                ];
            }
        }

        return [
            'bucket' => self::MERCHANDISE,
            'reason' => 'no fee pattern matched',
        ];
    }

    /**
     * Check whether a bucket represents a fee (delivery or other) rather than
     * merchandise.
     *
     * @param string $bucket One of the bucket constants on this class.
     *
     * @return bool True for {@see self::DELIVERY} and {@see self::OTHER_FEE};
     *              false for {@see self::MERCHANDISE} and any unknown value.
     *
     * @example
     *   SalesLineClassifier::isFee(SalesLineClassifier::DELIVERY);    // true
     *   SalesLineClassifier::isFee(SalesLineClassifier::MERCHANDISE); // false
     */
    public static function isFee(string $bucket): bool
    {
        return $bucket === self::DELIVERY || $bucket === self::OTHER_FEE;
    }
}
