<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Models\Quote;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Quote::isEditable() and the EDITABLE_STATUSES whitelist.
 *
 * Pure, no DB — guards the rule that only pre-payment quotes may be edited.
 *
 * @see \App\Models\Quote::isEditable()
 */
final class QuoteEditableTest extends TestCase
{
    /**
     * Pre-payment statuses are editable.
     */
    public function testPrePaymentStatusesAreEditable(): void
    {
        self::assertTrue(Quote::isEditable('draft'));
        self::assertTrue(Quote::isEditable('sent'));
        self::assertTrue(Quote::isEditable('accepted'));
    }

    /**
     * Post-payment, terminal, and unknown statuses are not editable.
     */
    public function testLockedStatusesAreNotEditable(): void
    {
        self::assertFalse(Quote::isEditable('deposit_confirmed'));
        self::assertFalse(Quote::isEditable('completed'));
        self::assertFalse(Quote::isEditable('cancelled'));
        self::assertFalse(Quote::isEditable('bogus'));
        self::assertFalse(Quote::isEditable(''));
    }

    /**
     * Every editable status is a real status (no typos / drift from STATUSES).
     */
    public function testEditableStatusesAreASubsetOfAllStatuses(): void
    {
        foreach (Quote::EDITABLE_STATUSES as $status) {
            self::assertContains(
                $status,
                Quote::STATUSES,
                "EDITABLE_STATUSES entry '{$status}' is not a valid quote status."
            );
        }
    }

    /**
     * isEditable() agrees with the EDITABLE_STATUSES whitelist across every
     * defined status — a small exhaustive sweep over Quote::STATUSES.
     */
    public function testIsEditableMatchesWhitelistForAllStatuses(): void
    {
        foreach (Quote::STATUSES as $status) {
            self::assertSame(
                in_array($status, Quote::EDITABLE_STATUSES, true),
                Quote::isEditable($status),
                "isEditable disagrees with the whitelist for '{$status}'."
            );
        }
    }
}
