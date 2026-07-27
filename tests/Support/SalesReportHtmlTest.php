<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\SalesReportHtml;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SalesReportHtml::render().
 *
 * Fixtures are hand-built literal arrays matching the shared weekly-sales
 * aggregate shape, independent of WeeklySalesAggregator, so these tests do
 * not depend on that class being finished. Covers the self-containment
 * guarantee (no external requests), the validated chart color tokens and
 * dark-mode selectors, escaping of untrusted warning text, the DoorDash
 * banner, and the empty/all-zero edge cases the chart must handle without
 * dividing by zero.
 *
 * @see \App\Support\SalesReportHtml
 */
class SalesReportHtmlTest extends TestCase
{
    /**
     * The document opens with a doctype and a style block and closes with `</html>`.
     */
    public function testDocumentSkeleton(): void
    {
        $html = SalesReportHtml::render($this->oneWeekAggregate());

        $this->assertStringContainsString('<!DOCTYPE html', $html);
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringEndsWith('</html>', trim($html));
    }

    /**
     * The document makes no external requests: no http(s) URL, no
     * `<script src=`, and no `@import` anywhere in the whole document.
     */
    public function testFullySelfContained(): void
    {
        $html = SalesReportHtml::render($this->oneWeekAggregate());

        $this->assertDoesNotMatchRegularExpression('#https?://#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<script[^>]+src=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#@import#i', $html);
    }

    /**
     * All three validated series hex values are present, along with both the
     * dark-mode media query and the `[data-theme="dark"]` selector.
     */
    public function testChartColorTokensAndDarkModeSelectors(): void
    {
        $html = SalesReportHtml::render($this->oneWeekAggregate());

        $this->assertStringContainsString('#2a78d6', $html); // --series-merch light
        $this->assertStringContainsString('#eb6834', $html); // --series-delivery light
        $this->assertStringContainsString('#1baf7a', $html); // --series-tax light
        $this->assertStringContainsString('#3987e5', $html); // --series-merch dark
        $this->assertStringContainsString('#d95926', $html); // --series-delivery dark
        $this->assertStringContainsString('#199e70', $html); // --series-tax dark
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringContainsString('[data-theme="dark"]', $html);
    }

    /**
     * The legend names all three stacked series so identity is never carried
     * by color alone.
     */
    public function testLegendListsAllThreeSeries(): void
    {
        $html = SalesReportHtml::render($this->oneWeekAggregate());

        $this->assertStringContainsString('Flower sales', $html);
        $this->assertStringContainsString('Delivery fees', $html);
        $this->assertStringContainsString('Sales tax', $html);
    }

    /**
     * A hostile warning string containing a script tag and a double-quoted
     * attribute-breakout attempt is escaped, never emitted raw.
     */
    public function testEscapesHostileWarningString(): void
    {
        $hostile   = 'quote-99: description "quoted" <script>alert(1)</script>';
        $aggregate = $this->oneWeekAggregate();
        $aggregate['warnings'] = [$hostile];

        $html = SalesReportHtml::render($aggregate);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;quoted&quot;', $html);
    }

    /**
     * A warning the aggregator filed under both its week and the flat top-level
     * list is listed once, not twice. The warnings section reads the top-level
     * list only, precisely because that list is already complete.
     */
    public function testWarningIsListedOnceWhenPresentInBothLists(): void
    {
        $taxedDelivery = 'quote-19: delivery line "Delivery" ($15.00) was taxed (pre-migration-018 behaviour)';

        // Reproduce what WeeklySalesAggregator emits: the row warning appears in
        // the week's own list AND in the flat top-level list. A renderer that
        // merges both lists renders two <li> copies of it.
        $aggregate = $this->oneWeekAggregate();
        $aggregate['weeks'][0]['warnings'] = [$taxedDelivery];
        $aggregate['warnings']             = [$taxedDelivery];

        $html = SalesReportHtml::render($aggregate);

        $this->assertSame(1, substr_count($html, self::escapeForAssertion($taxedDelivery)));
    }

    /**
     * HTML-escape a warning string the same way the renderer does, so an
     * occurrence count can be taken against the rendered markup.
     *
     * @param string $warning The raw warning text.
     *
     * @return string The text as it appears inside the rendered `<li>`.
     */
    private static function escapeForAssertion(string $warning): string
    {
        return htmlspecialchars($warning, ENT_QUOTES, 'UTF-8');
    }

    /**
     * The DoorDash-not-included banner renders when `doordash_included` is false.
     */
    public function testDoorDashBannerRendersWhenNotIncluded(): void
    {
        $html = SalesReportHtml::render($this->oneWeekAggregate(), ['doordash_included' => false]);

        $this->assertStringContainsString('DoorDash sales are NOT included', $html);
    }

    /**
     * The DoorDash-not-included banner is omitted when DoorDash sales are included.
     */
    public function testDoorDashBannerOmittedWhenIncluded(): void
    {
        $html = SalesReportHtml::render($this->oneWeekAggregate(), ['doordash_included' => true]);

        $this->assertStringNotContainsString('DoorDash sales are NOT included', $html);
    }

    /**
     * An empty aggregate (no weeks) renders the "no sales in range" panel
     * instead of a broken chart, with no PHP warning surfacing as output and
     * no NAN/INF artifact from a division by zero.
     */
    public function testEmptyAggregateRendersNoSalesPanel(): void
    {
        $html = SalesReportHtml::render($this->emptyAggregate());

        $this->assertStringContainsString('No sales in range', $html);
        $this->assertStringNotContainsString('NAN', $html);
        $this->assertStringNotContainsString('INF', $html);
    }

    /**
     * A week whose totals are all zero renders without error (no exception,
     * no division-by-zero artifact).
     */
    public function testAllZeroWeekRendersWithoutError(): void
    {
        $html = SalesReportHtml::render($this->zeroWeekAggregate());

        $this->assertStringContainsString('<!DOCTYPE html', $html);
        $this->assertStringNotContainsString('NAN', $html);
        $this->assertStringNotContainsString('INF', $html);
    }

    /**
     * The TOTAL row appears in the weekly table.
     */
    public function testTotalRowAppearsInTable(): void
    {
        $html = SalesReportHtml::render($this->oneWeekAggregate());

        $this->assertMatchesRegularExpression('#<tr class="total-row">.*?TOTAL.*?</tr>#s', $html);
    }

    /**
     * One week of hand-built ChannelTotals figures, matching the same fixture
     * shape used by SalesReportCsvTest.
     *
     * @return array{weeks: list<array<string, mixed>>, totals: array<string, mixed>,
     *              warnings: list<string>, first_sale_week: ?string, last_week: ?string}
     */
    private function oneWeekAggregate(): array
    {
        $channels = [
            'shop' => [
                'merchandise' => 100.00, 'delivery' => 10.00, 'tax' => 8.52, 'fees' => 0.00,
                'recognized_sales' => 118.52, 'deposit_collected' => 0.00, 'order_count' => 2,
            ],
            'quote' => [
                'merchandise' => 112.00, 'delivery' => 15.00, 'tax' => 10.82, 'fees' => 0.00,
                'recognized_sales' => 137.82, 'deposit_collected' => 63.50, 'order_count' => 1,
            ],
            'doordash' => [
                'merchandise' => 210.00, 'delivery' => 18.00, 'tax' => 17.89, 'fees' => 35.70,
                'recognized_sales' => 210.19, 'deposit_collected' => 0.00, 'order_count' => 1,
            ],
        ];

        $total = [
            'merchandise' => 422.00, 'delivery' => 43.00, 'tax' => 37.23, 'fees' => 35.70,
            'recognized_sales' => 466.53, 'deposit_collected' => 63.50, 'order_count' => 4,
        ];

        $week = [
            'week_start' => '2026-07-06',
            'iso_week'   => '2026-W28',
            'channels'   => $channels,
            'total'      => $total,
            'warnings'   => [],
        ];

        return [
            'weeks'           => [$week],
            'totals'          => ['channels' => $channels, 'total' => $total],
            'warnings'        => [],
            'first_sale_week' => '2026-07-06',
            'last_week'       => '2026-07-06',
        ];
    }

    /**
     * An aggregate with zero weeks — the case the chart must render as a
     * "no sales in range" panel rather than divide by zero.
     *
     * @return array{weeks: list<array<string, mixed>>, totals: array<string, mixed>,
     *              warnings: list<string>, first_sale_week: ?string, last_week: ?string}
     */
    private function emptyAggregate(): array
    {
        $zeroTotals = [
            'merchandise' => 0.00, 'delivery' => 0.00, 'tax' => 0.00, 'fees' => 0.00,
            'recognized_sales' => 0.00, 'deposit_collected' => 0.00, 'order_count' => 0,
        ];

        return [
            'weeks'           => [],
            'totals'          => [
                'channels' => ['shop' => $zeroTotals, 'quote' => $zeroTotals, 'doordash' => $zeroTotals],
                'total'    => $zeroTotals,
            ],
            'warnings'        => [],
            'first_sale_week' => null,
            'last_week'       => null,
        ];
    }

    /**
     * An aggregate with a single week whose every channel and total figure
     * is zero — the all-zero-week edge case that must not divide by zero
     * when scaling the chart.
     *
     * @return array{weeks: list<array<string, mixed>>, totals: array<string, mixed>,
     *              warnings: list<string>, first_sale_week: ?string, last_week: ?string}
     */
    private function zeroWeekAggregate(): array
    {
        $zeroTotals = [
            'merchandise' => 0.00, 'delivery' => 0.00, 'tax' => 0.00, 'fees' => 0.00,
            'recognized_sales' => 0.00, 'deposit_collected' => 0.00, 'order_count' => 0,
        ];

        $channels = ['shop' => $zeroTotals, 'quote' => $zeroTotals, 'doordash' => $zeroTotals];

        $week = [
            'week_start' => '2026-07-13',
            'iso_week'   => '2026-W29',
            'channels'   => $channels,
            'total'      => $zeroTotals,
            'warnings'   => [],
        ];

        return [
            'weeks'           => [$week],
            'totals'          => ['channels' => $channels, 'total' => $zeroTotals],
            'warnings'        => [],
            'first_sale_week' => null,
            'last_week'       => '2026-07-13',
        ];
    }
}
