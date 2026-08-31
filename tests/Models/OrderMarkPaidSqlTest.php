<?php

declare(strict_types=1);

namespace App\Tests\Models;

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression guard for the SQL contract of Order::markPaid().
 *
 * Why this asserts on source text instead of exercising the method: this
 * project has no database test harness (see tests/bootstrap.php — every
 * other test here is pure/DB-free), so a real call to Order::markPaid()
 * cannot be verified in CI. The weekly sales report's correctness depends
 * directly on this SQL: it reads COALESCE(paid_at, created_at) to date
 * revenue (migrations/019_add_orders_paid_at.sql), so a silent regression
 * here — paid_at dropped from the SET clause, or the idempotency guard
 * removed — would corrupt the report without any other test catching it.
 * This test reads src/Models/Order.php as text and asserts the UPDATE
 * statement still sets payment_status and paid_at and still guards against
 * re-firing on an already-paid order.
 *
 * @see \App\Models\Order::markPaid()
 * @see \App\Support\WeeklySalesAggregator
 */
final class OrderMarkPaidSqlTest extends TestCase
{
    /** Path to the class under test. */
    private const SOURCE_PATH = __DIR__ . '/../../src/Models/Order.php';

    /**
     * Returns the body of the markPaid() method as a whitespace-normalised
     * single-line string, so assertions are robust to reformatting.
     */
    private static function markPaidBody(): string
    {
        self::assertFileExists(self::SOURCE_PATH, 'Order.php is missing: ' . self::SOURCE_PATH);
        $source = (string) file_get_contents(self::SOURCE_PATH);

        $matched = preg_match(
            '/function\s+markPaid\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s',
            $source,
            $matches
        );
        self::assertSame(1, $matched, 'Could not locate the markPaid() method body in Order.php.');

        // Collapse all whitespace runs to a single space so the assertions
        // below don't depend on line breaks or indentation inside the SQL.
        return trim((string) preg_replace('/\s+/', ' ', $matches[1]));
    }

    /**
     * markPaid() must set paid_at = NOW() so the payment moment is recorded
     * separately from updated_at, which mutates on unrelated fulfilment
     * edits (see migrations/019_add_orders_paid_at.sql).
     */
    public function testSetsPaidAtToNow(): void
    {
        self::assertMatchesRegularExpression(
            '/paid_at\s*=\s*NOW\(\)/i',
            self::markPaidBody(),
            'markPaid() must set paid_at = NOW() so the sales report has a stable payment timestamp.'
        );
    }

    /**
     * markPaid() must still flip payment_status to 'paid'.
     */
    public function testSetsPaymentStatusToPaid(): void
    {
        self::assertMatchesRegularExpression(
            "/payment_status\s*=\s*'paid'/i",
            self::markPaidBody(),
            "markPaid() must set payment_status = 'paid'."
        );
    }

    /**
     * markPaid() must keep the idempotency guard that prevents a second
     * confirmation (webhook vs. success redirect) from re-firing the update
     * and overwriting paid_at.
     */
    public function testKeepsIdempotencyGuard(): void
    {
        self::assertMatchesRegularExpression(
            "/payment_status\s*!=\s*'paid'/i",
            self::markPaidBody(),
            "markPaid() must guard with payment_status != 'paid' so a second confirmation "
            . 'call is a no-op and does not overwrite paid_at.'
        );
    }
}
