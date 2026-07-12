<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure builder for GA4 + Meta Pixel + Google Ads ecommerce event payloads.
 *
 * Each builder returns a normalized platform spec:
 * <pre>
 * [
 *   'ga4'   => ['name' => 'view_item',   'params' => [...]],
 *   'pixel' => ['event' => 'ViewContent', 'params' => [...]],
 *   'ads'   => ['conversion' => 'purchase', 'params' => [...]], // conversion events only
 * ]
 * </pre>
 * The layout emits one `gtag('event', name, params)` and one
 * `fbq('track', event, params)` per spec. All monetary values are USD rounded
 * to cents; item ids/names are coerced to strings. No database, session, or
 * output — just a deterministic transform, which is what makes it unit-testable.
 *
 * The optional `ads` key appears only on conversion events ({@see self::purchase()},
 * {@see self::lead()}). It carries a SYMBOLIC conversion name ('purchase' or
 * 'lead') rather than a Google Ads conversion id/label — this class must stay
 * config-free, so it is the layout's job to resolve that symbolic name to the
 * `AW-.../label` pair read from env before calling `gtag('event', 'conversion', ...)`.
 * Funnel events ({@see self::viewItem()}, {@see self::addToCart()},
 * {@see self::beginCheckout()}) carry no `ads` key since they are not conversions.
 *
 * Input rows are read leniently: product rows, cart lines, and order-snapshot
 * rows use different key names for the same concept, so {@see self::item()}
 * accepts any of them.
 *
 * @see views/layouts/public.php          Emits the specs as <script> tags and resolves 'ads' conversion names to labels.
 * @see \App\Controllers\ShopController    view_item.
 * @see \App\Controllers\CartController    add_to_cart.
 * @see \App\Controllers\CheckoutController begin_checkout + purchase.
 */
final class Analytics
{
    /** ISO-4217 currency for every event (the shop is USD-only). */
    public const CURRENCY = 'USD';

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Build a `view_item` / `ViewContent` spec for a product detail view.
     *
     * @param array<string, mixed> $product Product row (id, name_en, price_from).
     *
     * @return array{ga4: array{name: string, params: array<string, mixed>}, pixel: array{event: string, params: array<string, mixed>}}
     *
     * @example
     *   Analytics::viewItem(['id' => 12, 'name_en' => 'Rose Bouquet', 'price_from' => 45]);
     */
    public static function viewItem(array $product): array
    {
        $item = self::item($product);

        return [
            'ga4' => [
                'name'   => 'view_item',
                'params' => [
                    'currency' => self::CURRENCY,
                    'value'    => $item['price'],
                    'items'    => [$item],
                ],
            ],
            'pixel' => [
                'event'  => 'ViewContent',
                'params' => [
                    'content_ids'  => [$item['item_id']],
                    'content_name' => $item['item_name'],
                    'content_type' => 'product',
                    'value'        => $item['price'],
                    'currency'     => self::CURRENCY,
                ],
            ],
        ];
    }

    /**
     * Build an `add_to_cart` / `AddToCart` spec for a cart line.
     *
     * @param array<string, mixed> $line Cart line (id, name_en, unit_price, qty).
     *
     * @return array{ga4: array{name: string, params: array<string, mixed>}, pixel: array{event: string, params: array<string, mixed>}}
     *
     * @example
     *   Analytics::addToCart(['id' => 12, 'name_en' => 'Rose Bouquet', 'unit_price' => 45, 'qty' => 2]);
     */
    public static function addToCart(array $line): array
    {
        $item  = self::item($line);
        $value = round($item['price'] * $item['quantity'], 2);

        return [
            'ga4' => [
                'name'   => 'add_to_cart',
                'params' => [
                    'currency' => self::CURRENCY,
                    'value'    => $value,
                    'items'    => [$item],
                ],
            ],
            'pixel' => [
                'event'  => 'AddToCart',
                'params' => [
                    'content_ids'  => [$item['item_id']],
                    'content_name' => $item['item_name'],
                    'content_type' => 'product',
                    'contents'     => [['id' => $item['item_id'], 'quantity' => $item['quantity']]],
                    'value'        => $value,
                    'currency'     => self::CURRENCY,
                ],
            ],
        ];
    }

    /**
     * Build a `begin_checkout` / `InitiateCheckout` spec for the checkout view.
     *
     * @param array<int, array<string, mixed>> $items Cart line items.
     * @param float                            $value Merchandise subtotal.
     *
     * @return array{ga4: array{name: string, params: array<string, mixed>}, pixel: array{event: string, params: array<string, mixed>}}
     *
     * @example
     *   Analytics::beginCheckout($items, 90.00);
     */
    public static function beginCheckout(array $items, float $value): array
    {
        $ga4Items = array_map([self::class, 'item'], array_values($items));
        $ids      = array_map(static fn (array $i): string => $i['item_id'], $ga4Items);
        $numItems = array_sum(array_map(static fn (array $i): int => $i['quantity'], $ga4Items));

        return [
            'ga4' => [
                'name'   => 'begin_checkout',
                'params' => [
                    'currency' => self::CURRENCY,
                    'value'    => round($value, 2),
                    'items'    => $ga4Items,
                ],
            ],
            'pixel' => [
                'event'  => 'InitiateCheckout',
                'params' => [
                    'content_ids'  => $ids,
                    'content_type' => 'product',
                    'num_items'    => $numItems,
                    'value'        => round($value, 2),
                    'currency'     => self::CURRENCY,
                ],
            ],
        ];
    }

    /**
     * Build a `purchase` / `Purchase` spec for a paid order.
     *
     * @param array<string, mixed>             $order The order row (id, total).
     * @param array<int, array<string, mixed>> $items The order's snapshot line items.
     *
     * @return array{ga4: array{name: string, params: array<string, mixed>}, pixel: array{event: string, params: array<string, mixed>}, ads: array{conversion: string, params: array<string, mixed>}}
     *
     * @example
     *   Analytics::purchase(['id' => 87, 'total' => 96.83], $items);
     */
    public static function purchase(array $order, array $items): array
    {
        $ga4Items = array_map([self::class, 'item'], array_values($items));
        $ids      = array_map(static fn (array $i): string => $i['item_id'], $ga4Items);
        $numItems = array_sum(array_map(static fn (array $i): int => $i['quantity'], $ga4Items));
        $value    = round((float) ($order['total'] ?? 0), 2);

        return [
            'ga4' => [
                'name'   => 'purchase',
                'params' => [
                    'transaction_id' => (string) ($order['id'] ?? ''),
                    'currency'       => self::CURRENCY,
                    'value'          => $value,
                    'items'          => $ga4Items,
                ],
            ],
            'pixel' => [
                'event'  => 'Purchase',
                'params' => [
                    'content_ids'  => $ids,
                    'content_type' => 'product',
                    'num_items'    => $numItems,
                    'value'        => $value,
                    'currency'     => self::CURRENCY,
                ],
            ],
            'ads' => [
                'conversion' => 'purchase',
                'params'     => [
                    'value'          => $value,
                    'currency'       => self::CURRENCY,
                    'transaction_id' => (string) ($order['id'] ?? ''),
                ],
            ],
        ];
    }

    /**
     * Build a `generate_lead` / `Lead` / Google Ads lead-conversion spec for a submitted bouquet request.
     *
     * Fired client-side from the bouquet-request form's AJAX success handler —
     * there is no server-rendered thank-you page to attach a pageview event to.
     * A bouquet request has no price yet (it becomes a quote later), so unlike
     * {@see self::purchase()} there is no `value` to report.
     *
     * @return array{ga4: array{name: string, params: array<string, mixed>}, pixel: array{event: string, params: array<string, mixed>}, ads: array{conversion: string, params: array<string, mixed>}}
     *
     * @example
     *   Analytics::lead();
     */
    public static function lead(): array
    {
        return [
            'ga4' => [
                'name'   => 'generate_lead',
                'params' => [
                    'currency' => self::CURRENCY,
                ],
            ],
            'pixel' => [
                'event'  => 'Lead',
                'params' => [
                    'currency' => self::CURRENCY,
                ],
            ],
            'ads' => [
                'conversion' => 'lead',
                'params'     => [],
            ],
        ];
    }

    /**
     * Normalize a product / cart-line / snapshot row into a GA4 item.
     *
     * Reads whichever key the source uses for each concept (id ← item_id|id|
     * product_id; name ← item_name|name_en|name; price ← price|unit_price|
     * price_from; quantity ← quantity|qty), so one mapper serves every event.
     *
     * @param array<string, mixed> $row A product, cart line, or snapshot row.
     *
     * @return array{item_id: string, item_name: string, price: float, quantity: int}
     */
    private static function item(array $row): array
    {
        $id    = $row['item_id'] ?? $row['id'] ?? $row['product_id'] ?? '';
        $name  = $row['item_name'] ?? $row['name_en'] ?? $row['name'] ?? '';
        $price = $row['price'] ?? $row['unit_price'] ?? $row['price_from'] ?? 0;
        $qty   = $row['quantity'] ?? $row['qty'] ?? 1;

        return [
            'item_id'   => (string) $id,
            'item_name' => (string) $name,
            'price'     => round((float) $price, 2),
            'quantity'  => max(1, (int) $qty),
        ];
    }
}
