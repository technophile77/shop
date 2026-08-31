<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Enumerates the revenue channels the weekly sales report covers, and defines
 * the canonical accounting identity every channel row must satisfy.
 *
 * The three channels are disjoint sources of recognized revenue:
 *   - SHOP     — Stripe shop-cart purchases, stored in the `orders` table.
 *   - QUOTE    — custom/event quotes, stored in the `quotes` table, recognized
 *                in full on the week their deposit was confirmed.
 *   - DOORDASH — imported from a manually downloaded Merchant Portal CSV; not
 *                present in the database at all.
 *
 * Every channel row, and every aggregated weekly row, closes on:
 *
 *   merchandise + delivery + tax − fees = recognized_sales
 *
 * `fees` is a positive magnitude that is *subtracted* — it is only ever
 * non-zero for DOORDASH, where the recognized sale is the net payout and the
 * commission/marketing charges are reported as their own category.
 *
 * No database access, no side effects.
 *
 * @see \App\Support\WeeklySalesAggregator Consumes rows keyed by these channels.
 * @see \App\Support\DoorDashSalesCsv      Produces DOORDASH rows.
 * @see \App\Support\QuoteSalesBreakdown   Produces the QUOTE money split.
 */
final class SalesChannel
{
    /** Stripe shop-cart purchases from the `orders` table. */
    public const SHOP = 'shop';

    /** Custom/event quotes from the `quotes` table. */
    public const QUOTE = 'quote';

    /** DoorDash orders imported from a Merchant Portal CSV export. */
    public const DOORDASH = 'doordash';

    /**
     * All channels in canonical report column order.
     *
     * @var list<string>
     */
    public const ALL = [self::SHOP, self::QUOTE, self::DOORDASH];

    /**
     * Tolerance in dollars for the closing-identity check.
     *
     * Half a cent: the identity is computed from values already rounded to
     * 2 decimals, so any drift beyond this is a real arithmetic fault, not
     * floating-point noise.
     */
    public const IDENTITY_TOLERANCE = 0.005;

    /** Prevent instantiation — all access is via constants and static methods. */
    private function __construct() {}

    /**
     * Human-readable label for a channel, used in report headings.
     *
     * @param string $channel One of the channel constants on this class.
     *
     * @return string A display label, or the raw channel string when unknown
     *                (so an unrecognised channel is visible rather than hidden).
     *
     * @example
     *   SalesChannel::label(SalesChannel::SHOP);      // 'Web Store'
     *   SalesChannel::label(SalesChannel::DOORDASH);  // 'DoorDash'
     */
    public static function label(string $channel): string
    {
        return match ($channel) {
            self::SHOP     => 'Web Store',
            self::QUOTE    => 'Quotes',
            self::DOORDASH => 'DoorDash',
            default        => $channel,
        };
    }

    /**
     * Compute recognized sales from the four component figures.
     *
     * This is the single definition of the accounting identity; every producer
     * of channel rows must derive `recognized_sales` through this method so the
     * report can never disagree with itself.
     *
     * @param float $merchandise Flower/product sales in dollars.
     * @param float $delivery    Delivery fees charged to the customer, in dollars.
     * @param float $tax         Sales tax collected, in dollars.
     * @param float $fees        Channel fees withheld (commission, marketing) as a
     *                           positive magnitude; 0.00 for the web-store channels.
     *
     * @return float Recognized sales in dollars, rounded to 2 decimal places.
     *
     * @example
     *   // A $90 bouquet with $10 delivery and $7.67 tax, no channel fees:
     *   SalesChannel::recognize(90.00, 10.00, 7.67, 0.00);   // 107.67
     *
     *   // A DoorDash order netting the payout after $31.50 commission:
     *   SalesChannel::recognize(210.00, 18.00, 17.89, 35.70); // 210.19
     */
    public static function recognize(float $merchandise, float $delivery, float $tax, float $fees): float
    {
        return round($merchandise + $delivery + $tax - $fees, 2);
    }

    /**
     * Check whether a set of component figures closes on the stated total.
     *
     * @param float $merchandise      Flower/product sales in dollars.
     * @param float $delivery         Delivery fees in dollars.
     * @param float $tax              Sales tax in dollars.
     * @param float $fees             Channel fees withheld, positive magnitude.
     * @param float $recognizedSales  The total to check the components against.
     *
     * @return bool True when the components sum to the total within
     *              {@see self::IDENTITY_TOLERANCE}.
     *
     * @example
     *   SalesChannel::closes(90.00, 10.00, 7.67, 0.00, 107.67); // true
     *   SalesChannel::closes(90.00, 10.00, 7.67, 0.00, 100.00); // false
     */
    public static function closes(
        float $merchandise,
        float $delivery,
        float $tax,
        float $fees,
        float $recognizedSales
    ): bool {
        $expected = self::recognize($merchandise, $delivery, $tax, $fees);

        return abs($expected - $recognizedSales) <= self::IDENTITY_TOLERANCE;
    }
}
