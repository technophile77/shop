<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Thin session wrapper for the customer shopping cart.
 *
 * Reads and writes `$_SESSION['cart']` and delegates all mutation logic to the
 * pure {@see \App\Support\Cart} class. Controllers should call only this class
 * for cart operations; they should never touch $_SESSION['cart'] directly.
 *
 * This class is not unit-tested because all meaningful logic lives in Cart.
 * Integration behaviour is validated via deployment smoke tests.
 *
 * @see \App\Support\Cart         Pure cart mutation logic.
 * @see \App\Controllers\CartController  Primary consumer.
 */
final class CartSession
{
    /** Session key that stores the cart items array. */
    private const SESSION_KEY = 'cart';

    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /**
     * Return the current cart items from the session.
     *
     * @return array[] List of canonical line-item arrays; empty when no cart exists.
     *
     * @example
     *   $items = CartSession::items();
     */
    public static function items(): array
    {
        return $_SESSION[self::SESSION_KEY] ?? [];
    }

    /**
     * Overwrite the cart items in the session.
     *
     * @param array[] $items New full items list.
     *
     * @return void
     *
     * @example
     *   CartSession::save($updatedItems);
     */
    public static function save(array $items): void
    {
        $_SESSION[self::SESSION_KEY] = $items;
    }

    /**
     * Add a line item to the cart, merging qty when the signature already exists.
     *
     * @param array $line Partial or complete canonical line-item array.
     *
     * @return void
     *
     * @example
     *   CartSession::addLine($line);
     */
    public static function addLine(array $line): void
    {
        self::save(Cart::add(self::items(), $line));
    }

    /**
     * Update the quantity of a line identified by its signature.
     *
     * When $qty ≤ 0 the line is removed.
     *
     * @param string $signature Canonical line signature from Cart::signature().
     * @param int    $qty       New quantity.
     *
     * @return void
     *
     * @example
     *   CartSession::update($sig, 2);
     */
    public static function update(string $signature, int $qty): void
    {
        self::save(Cart::updateQty(self::items(), $signature, $qty));
    }

    /**
     * Remove a line item by signature.
     *
     * No-ops silently when the signature is not found.
     *
     * @param string $signature Canonical line signature from Cart::signature().
     *
     * @return void
     *
     * @example
     *   CartSession::remove($sig);
     */
    public static function remove(string $signature): void
    {
        self::save(Cart::remove(self::items(), $signature));
    }

    /**
     * Empty the cart entirely.
     *
     * @return void
     *
     * @example
     *   CartSession::clear();
     */
    public static function clear(): void
    {
        $_SESSION[self::SESSION_KEY] = [];
    }

    /**
     * Return the total number of individual units across all cart lines.
     *
     * Useful for displaying a badge count in the navigation bar.
     *
     * @return int Total unit count; 0 when the cart is empty.
     *
     * @example
     *   $badge = CartSession::count(); // e.g. 3
     */
    public static function count(): int
    {
        return Cart::totalQuantity(self::items());
    }
}
