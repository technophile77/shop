<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure renderer that turns the weekly sales aggregate into a single
 * self-contained HTML document the owner can open in a browser or forward
 * by email, with no server, no build step, and no network access required.
 *
 * The document is fully inline: one `<style>` block (light/dark aware via
 * both `prefers-color-scheme` and a `data-theme` attribute so a future theme
 * toggle wins either way), an inline SVG stacked-bar chart built entirely in
 * PHP, and no external stylesheet, font, image, or script. Every untrusted
 * string — most importantly the free-text warning sentences produced by
 * {@see \App\Support\QuoteSalesBreakdown} and
 * {@see \App\Support\SalesLineClassifier}, which echo admin-entered quote
 * line descriptions — is escaped with `htmlspecialchars(..., ENT_QUOTES,
 * 'UTF-8')` before it reaches the page.
 *
 * The stacked bar chart deliberately stacks only three components — flower
 * sales, delivery fees, and sales tax — because those three are the only
 * *additive* pieces of `merchandise + delivery + tax − fees = recognized_sales`.
 * DoorDash commission (`fees`) is a subtraction, not a positive component of
 * revenue; stacking it alongside the others would visually misrepresent it
 * as more revenue when it is actually money the business never received. It
 * is instead reported as its own annotation next to the chart and as its own
 * stat tile.
 *
 * No filesystem access, no database access, no side effects.
 *
 * @see \App\Support\SalesChannel        Defines the channel order and the accounting identity.
 * @see \App\Support\SalesReportCsv      Renders the same aggregate as a spreadsheet CSV.
 * @see \App\Support\WeeklySalesAggregator Produces the `$aggregate` shape consumed here.
 */
final class SalesReportHtml
{
    /** Prevent instantiation — all access is via static methods. */
    private function __construct() {}

    /** Fallback business name when `$meta['business_name']` is not supplied. */
    private const DEFAULT_BUSINESS_NAME = "Perla's Flowers";

    /** SVG viewBox width in user units; scales to the container via CSS. */
    private const CHART_WIDTH = 760;

    /** SVG viewBox height in user units. */
    private const CHART_HEIGHT = 300;

    /** Left edge of the plotted bar area, leaving room for y-axis labels. */
    private const CHART_PLOT_LEFT = 56;

    /** Right edge of the plotted bar area. */
    private const CHART_PLOT_RIGHT = 740;

    /** Top edge of the plotted bar area. */
    private const CHART_PLOT_TOP = 16;

    /** Bottom edge of the plotted bar area — the baseline every bar anchors to. */
    private const CHART_PLOT_BOTTOM = 230;

    /** Y position of the x-axis week-start labels, below the baseline. */
    private const CHART_X_LABEL_Y = 254;

    /** Gap, in SVG user units, left in the surface color between stacked segments. */
    private const SEGMENT_GAP = 2.0;

    /** Corner radius applied only to the top (outer) end of each bar. */
    private const CORNER_RADIUS = 4.0;

    /** Fractions of the y-axis scale a gridline (and its dollar label) is drawn at. */
    private const GRIDLINE_FRACTIONS = [0.25, 0.5, 0.75, 1.0];

    /**
     * Render the complete weekly sales report as one self-contained HTML document.
     *
     * @param array{
     *     weeks: list<array{week_start: string, iso_week: string,
     *                       channels: array<string, array{merchandise: float, delivery: float,
     *                           tax: float, fees: float, recognized_sales: float,
     *                           deposit_collected: float, order_count: int}>,
     *                       total: array{merchandise: float, delivery: float, tax: float,
     *                           fees: float, recognized_sales: float,
     *                           deposit_collected: float, order_count: int},
     *                       warnings: list<string>}>,
     *     totals: array{channels: array<string, array{merchandise: float, delivery: float,
     *                       tax: float, fees: float, recognized_sales: float,
     *                       deposit_collected: float, order_count: int}>,
     *                   total: array{merchandise: float, delivery: float, tax: float,
     *                       fees: float, recognized_sales: float,
     *                       deposit_collected: float, order_count: int}},
     *     warnings: list<string>,
     *     first_sale_week: ?string,
     *     last_week: ?string,
     * } $aggregate The weekly sales aggregate.
     * @param array{generated_at?: \DateTimeImmutable, doordash_included?: bool,
     *              doordash_source?: string, business_name?: string} $meta
     *        Optional report metadata. `generated_at` defaults to now,
     *        `doordash_included` defaults to true, `business_name` defaults to
     *        {@see self::DEFAULT_BUSINESS_NAME}.
     *
     * @return string A complete `<!DOCTYPE html>` document with no external
     *         requests of any kind — safe to save to disk or attach to an email.
     *
     * @example
     *   $html = SalesReportHtml::render($aggregate, [
     *       'generated_at' => new \DateTimeImmutable('now'),
     *       'doordash_included' => true,
     *       'doordash_source' => 'doordash-2026-07-20.csv',
     *   ]);
     *   file_put_contents('/tmp/report.html', $html);
     */
    public static function render(array $aggregate, array $meta = []): string
    {
        $generatedAt      = $meta['generated_at'] ?? new \DateTimeImmutable('now');
        $doordashIncluded = $meta['doordash_included'] ?? true;
        $doordashSource   = $meta['doordash_source'] ?? null;
        $businessName     = $meta['business_name'] ?? self::DEFAULT_BUSINESS_NAME;

        $header   = self::renderHeader($aggregate, $businessName, $generatedAt, $doordashIncluded, $doordashSource);
        $tiles    = self::renderStatTiles($aggregate['totals']);
        $chart    = self::renderChartSection($aggregate['weeks'], $aggregate['totals']['channels'][SalesChannel::DOORDASH]['fees']);
        $table    = self::renderTable($aggregate);
        $warnings = self::renderWarnings($aggregate);
        $title    = self::escape($businessName . ' — Weekly Sales Report');
        $css      = self::css();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$title}</title>
        <style>{$css}</style>
        </head>
        <body>
        <main class="report">
        {$header}
        {$tiles}
        {$chart}
        {$table}
        {$warnings}
        </main>
        </body>
        </html>
        HTML;
    }

    /**
     * Render the `<h1>`/subhead block, including the "DoorDash not included"
     * banner when applicable so a partial report can never be mistaken for a
     * complete one.
     *
     * @param array{first_sale_week: ?string, last_week: ?string} $aggregate The aggregate
     *        (only the date-span keys are read).
     * @param string $businessName Business name for the page heading.
     * @param \DateTimeImmutable $generatedAt When the report was generated.
     * @param bool $doordashIncluded Whether DoorDash sales are included in this run.
     * @param ?string $doordashSource Filename the DoorDash figures were imported from.
     *
     * @return string The `<header>` element markup.
     */
    private static function renderHeader(
        array $aggregate,
        string $businessName,
        \DateTimeImmutable $generatedAt,
        bool $doordashIncluded,
        ?string $doordashSource
    ): string {
        $span   = self::formatDateSpan($aggregate['first_sale_week'], $aggregate['last_week']);
        $banner = $doordashIncluded ? '' : self::renderDoorDashBanner();
        $source = ($doordashIncluded && $doordashSource !== null)
            ? sprintf(' &middot; DoorDash data: %s', self::escape($doordashSource))
            : '';

        return sprintf(
            '<header class="report-header">' .
                '<h1>%s — Weekly Sales Report</h1>' .
                '<p class="report-subhead">%s &middot; Generated %s%s</p>' .
                '%s' .
            '</header>',
            self::escape($businessName),
            self::escape($span),
            self::escape($generatedAt->format('Y-m-d H:i')),
            $source,
            $banner
        );
    }

    /**
     * Render the prominent banner shown whenever DoorDash sales were not
     * included in this run, so a partial report is never mistakable for a
     * complete one.
     *
     * @return string A `<p>` banner element.
     */
    private static function renderDoorDashBanner(): string
    {
        return '<p class="banner-warning">DoorDash sales are NOT included in this report.</p>';
    }

    /**
     * Format the report's covered date span for the subhead.
     *
     * @param ?string $first The first week with any recognized sale, or null when there is none.
     * @param ?string $last  The last week covered by the report, or null when there is none.
     *
     * @return string A human-readable span, or a "no sales" message when either bound is missing.
     */
    private static function formatDateSpan(?string $first, ?string $last): string
    {
        if ($first === null || $last === null) {
            return 'No sales in range';
        }

        return sprintf('%s through %s', $first, $last);
    }

    /**
     * Render the row of hero/summary stat tiles.
     *
     * @param array{channels: array<string, array{fees: float}>,
     *              total: array{merchandise: float, delivery: float, tax: float,
     *                  recognized_sales: float, order_count: int}} $totals The
     *        aggregate's `totals` entry.
     *
     * @return string A `<section>` of stat tiles.
     */
    private static function renderStatTiles(array $totals): string
    {
        $doordashFees = $totals['channels'][SalesChannel::DOORDASH]['fees'];
        $total        = $totals['total'];

        $tiles = [
            ['Total Recognized Sales', self::formatMoney($total['recognized_sales']), true],
            ['Flower Sales',           self::formatMoney($total['merchandise']),      false],
            ['Delivery Fees',          self::formatMoney($total['delivery']),         false],
            ['Sales Tax',              self::formatMoney($total['tax']),              false],
            ['DoorDash Commission',    self::formatMoney($doordashFees),              false],
            ['Orders',                 (string) $total['order_count'],                false],
        ];

        $html = '';
        foreach ($tiles as [$label, $value, $isHero]) {
            $class = $isHero ? 'stat-tile stat-tile-hero' : 'stat-tile';
            $html .= sprintf(
                '<div class="%s"><span class="stat-label">%s</span><span class="stat-value">%s</span></div>',
                $class,
                self::escape($label),
                self::escape($value)
            );
        }

        return "<section class=\"stat-tiles\">{$html}</section>";
    }

    /**
     * Render the chart section: the stacked-bar SVG, its legend, and the
     * DoorDash commission annotation.
     *
     * @param list<array{week_start: string,
     *              total: array{merchandise: float, delivery: float, tax: float}}> $weeks
     *        The aggregate's `weeks` entries (only `week_start` and `total` are read).
     * @param float $doordashFees Total DoorDash commission across the report, shown as
     *        an annotation rather than stacked into the chart — see the class DocBlock.
     *
     * @return string A `<section>` containing the chart.
     */
    private static function renderChartSection(array $weeks, float $doordashFees): string
    {
        $chart = self::renderChart($weeks);
        $legend = $weeks === [] ? '' : self::renderLegend();
        $annotation = $weeks === [] ? '' : sprintf(
            '<p class="chart-annotation">DoorDash commission (subtracted from recognized sales; not part of the stack above): %s</p>',
            self::escape(self::formatMoney($doordashFees))
        );

        return <<<HTML
        <section class="report-section">
        <h2>Weekly Sales by Component</h2>
        <div class="chart-wrap">{$chart}</div>
        {$legend}
        {$annotation}
        </section>
        HTML;
    }

    /**
     * Render the stacked-bar SVG, or a "no sales in range" panel when there
     * are no weeks to plot.
     *
     * @param list<array{week_start: string,
     *              total: array{merchandise: float, delivery: float, tax: float}}> $weeks
     *        The weeks to plot.
     *
     * @return string An `<svg>` element, or the empty-state panel markup.
     */
    private static function renderChart(array $weeks): string
    {
        if ($weeks === []) {
            return self::renderNoSalesPanel();
        }

        $stackTotals = array_map(
            static fn (array $week): float =>
                $week['total']['merchandise'] + $week['total']['delivery'] + $week['total']['tax'],
            $weeks
        );

        $maxStack = max([0.0, ...$stackTotals]);
        $scale    = $maxStack > 0.0 ? $maxStack : 1.0;

        $weekCount = count($weeks);
        $plotWidth = self::CHART_PLOT_RIGHT - self::CHART_PLOT_LEFT;
        $slotWidth = $plotWidth / $weekCount;
        $barWidth  = $slotWidth * 0.7;

        $bars = '';
        foreach ($weeks as $index => $week) {
            $bars .= self::renderBar($week, $index, $slotWidth, $barWidth, $scale);
        }

        $gridlines = self::renderGridlines($scale);
        $xLabels   = self::renderXAxisLabels($weeks, $slotWidth);

        return sprintf(
            '<svg class="sales-chart" viewBox="0 0 %d %d" role="img" ' .
                'aria-label="Weekly sales stacked by flower sales, delivery fees, and sales tax">' .
                '%s%s<line class="chart-baseline" x1="%d" y1="%d" x2="%d" y2="%d"></line>%s' .
            '</svg>',
            self::CHART_WIDTH,
            self::CHART_HEIGHT,
            $gridlines,
            $bars,
            self::CHART_PLOT_LEFT,
            self::CHART_PLOT_BOTTOM,
            self::CHART_PLOT_RIGHT,
            self::CHART_PLOT_BOTTOM,
            $xLabels
        );
    }

    /**
     * Render the "no sales in range" empty-state panel shown instead of a broken chart.
     *
     * @return string A `<div>` panel element.
     */
    private static function renderNoSalesPanel(): string
    {
        return '<div class="chart-empty">No sales in range.</div>';
    }

    /**
     * Render one week's stacked bar (flower sales, delivery fees, sales tax,
     * bottom to top), with a 2px surface-color gap between rendered segments
     * and a rounded top only on the topmost non-zero segment.
     *
     * @param array{week_start: string, total: array{merchandise: float, delivery: float, tax: float}} $week
     *        The week to render.
     * @param int $index Zero-based position of this week among all plotted weeks.
     * @param float $slotWidth Horizontal space allotted to this bar, including its gap to neighbours.
     * @param float $barWidth Actual bar width, narrower than `$slotWidth` to leave visual spacing.
     * @param float $scale The value (in dollars) that maps to the full plot height.
     *
     * @return string SVG markup for the bar's segments, or `''` when every component is zero.
     */
    private static function renderBar(array $week, int $index, float $slotWidth, float $barWidth, float $scale): string
    {
        $plotHeight = self::CHART_PLOT_BOTTOM - self::CHART_PLOT_TOP;

        $components = [
            ['amount' => $week['total']['merchandise'], 'class' => 'bar-merch'],
            ['amount' => $week['total']['delivery'],    'class' => 'bar-delivery'],
            ['amount' => $week['total']['tax'],          'class' => 'bar-tax'],
        ];

        $rendered = array_values(array_filter(
            $components,
            static fn (array $component): bool => $component['amount'] > 0.0
        ));

        if ($rendered === []) {
            return '';
        }

        $rawHeights = array_map(
            static fn (array $component): float => ($component['amount'] / $scale) * $plotHeight,
            $rendered
        );

        $totalRaw    = array_sum($rawHeights);
        $gapSpace    = (count($rendered) - 1) * self::SEGMENT_GAP;
        $available   = max($totalRaw - $gapSpace, 0.0);
        $scaleFactor = $totalRaw > 0.0 ? $available / $totalRaw : 0.0;

        $x = self::CHART_PLOT_LEFT + $index * $slotWidth + ($slotWidth - $barWidth) / 2;

        $svg       = '';
        $bottom    = self::CHART_PLOT_BOTTOM;
        $lastIndex = count($rendered) - 1;

        foreach ($rendered as $i => $component) {
            $height = $rawHeights[$i] * $scaleFactor;
            $top    = $bottom - $height;

            $svg .= $i === $lastIndex
                ? self::renderRoundedTopSegment($x, $top, $barWidth, $height, $component['class'])
                : self::renderSegment($x, $top, $barWidth, $height, $component['class']);

            $bottom = $top - self::SEGMENT_GAP;
        }

        return $svg;
    }

    /**
     * Render one plain rectangular bar segment.
     *
     * @param float $x Left edge in SVG user units.
     * @param float $y Top edge in SVG user units.
     * @param float $width Segment width.
     * @param float $height Segment height.
     * @param string $class CSS class selecting the segment's series color.
     *
     * @return string A `<rect>` element.
     */
    private static function renderSegment(float $x, float $y, float $width, float $height, string $class): string
    {
        return sprintf(
            '<rect class="%s" x="%.2f" y="%.2f" width="%.2f" height="%.2f"></rect>',
            $class,
            $x,
            $y,
            $width,
            $height
        );
    }

    /**
     * Render the topmost bar segment with rounded top-left/top-right corners
     * only, as a path (an SVG `<rect>` cannot round a subset of its corners).
     *
     * @param float $x Left edge in SVG user units.
     * @param float $y Top edge in SVG user units.
     * @param float $width Segment width.
     * @param float $height Segment height.
     * @param string $class CSS class selecting the segment's series color.
     *
     * @return string A `<path>` element.
     */
    private static function renderRoundedTopSegment(float $x, float $y, float $width, float $height, string $class): string
    {
        $radius = min(self::CORNER_RADIUS, $height, $width / 2);
        $bottom = $y + $height;
        $right  = $x + $width;

        $d = sprintf(
            'M %.2f,%.2f L %.2f,%.2f Q %.2f,%.2f %.2f,%.2f L %.2f,%.2f Q %.2f,%.2f %.2f,%.2f L %.2f,%.2f Z',
            $x, $bottom,
            $x, $y + $radius,
            $x, $y, $x + $radius, $y,
            $right - $radius, $y,
            $right, $y, $right, $y + $radius,
            $right, $bottom
        );

        return sprintf('<path class="%s" d="%s"></path>', $class, $d);
    }

    /**
     * Render the y-axis hairline gridlines and their dollar labels.
     *
     * @param float $scale The value (in dollars) that maps to the full plot height.
     *
     * @return string SVG `<line>`/`<text>` markup for each gridline in {@see self::GRIDLINE_FRACTIONS},
     *         plus the `$0` label at the baseline.
     */
    private static function renderGridlines(float $scale): string
    {
        $svg = '';

        foreach (self::GRIDLINE_FRACTIONS as $fraction) {
            $y     = self::CHART_PLOT_BOTTOM - $fraction * (self::CHART_PLOT_BOTTOM - self::CHART_PLOT_TOP);
            $value = $scale * $fraction;

            $svg .= sprintf(
                '<line class="chart-gridline" x1="%d" y1="%.2f" x2="%d" y2="%.2f"></line>' .
                    '<text class="chart-axis-label" x="%d" y="%.2f" text-anchor="end">%s</text>',
                self::CHART_PLOT_LEFT,
                $y,
                self::CHART_PLOT_RIGHT,
                $y,
                self::CHART_PLOT_LEFT - 8,
                $y + 4,
                self::escape('$' . number_format($value, 0))
            );
        }

        $svg .= sprintf(
            '<text class="chart-axis-label" x="%d" y="%.2f" text-anchor="end">$0</text>',
            self::CHART_PLOT_LEFT - 8,
            self::CHART_PLOT_BOTTOM + 4
        );

        return $svg;
    }

    /**
     * Render x-axis week-start labels, thinned so they never collide: every
     * 4th week plus the first and last are always labelled.
     *
     * @param list<array{week_start: string}> $weeks The plotted weeks.
     * @param float $slotWidth Horizontal space allotted to each bar.
     *
     * @return string SVG `<text>` markup for the labelled weeks.
     */
    private static function renderXAxisLabels(array $weeks, float $slotWidth): string
    {
        $total = count($weeks);
        $svg   = '';

        foreach ($weeks as $index => $week) {
            if (!self::shouldLabelWeek($index, $total)) {
                continue;
            }

            $x = self::CHART_PLOT_LEFT + ($index + 0.5) * $slotWidth;

            $svg .= sprintf(
                '<text class="chart-axis-label chart-x-label" x="%.2f" y="%d" text-anchor="middle">%s</text>',
                $x,
                self::CHART_X_LABEL_Y,
                self::escape($week['week_start'])
            );
        }

        return $svg;
    }

    /**
     * Decide whether a week's x-axis label should be drawn, per the thinning
     * rule in {@see self::renderXAxisLabels()}.
     *
     * @param int $index Zero-based position of the week.
     * @param int $total Total number of plotted weeks.
     *
     * @return bool True when this week's label should be shown.
     */
    private static function shouldLabelWeek(int $index, int $total): bool
    {
        return $index === 0 || $index === $total - 1 || $index % 4 === 0;
    }

    /**
     * Render the chart legend, naming all three stacked series so identity
     * is never carried by color alone.
     *
     * @return string A `<ul>` legend element.
     */
    private static function renderLegend(): string
    {
        return '<ul class="chart-legend">' .
            '<li><span class="legend-swatch legend-merch"></span>Flower sales</li>' .
            '<li><span class="legend-swatch legend-delivery"></span>Delivery fees</li>' .
            '<li><span class="legend-swatch legend-tax"></span>Sales tax</li>' .
        '</ul>';
    }

    /**
     * Render the authoritative weekly table, with a `TOTAL` footer row.
     *
     * @param array{
     *     weeks: list<array{week_start: string, iso_week: string,
     *                       channels: array<string, array{merchandise: float, delivery: float,
     *                           tax: float, fees: float, recognized_sales: float,
     *                           deposit_collected: float, order_count: int}>,
     *                       total: array{merchandise: float, delivery: float, tax: float,
     *                           fees: float, recognized_sales: float,
     *                           deposit_collected: float, order_count: int}}>,
     *     totals: array{channels: array<string, array{merchandise: float, delivery: float,
     *                       tax: float, fees: float, recognized_sales: float,
     *                       deposit_collected: float, order_count: int}>,
     *                   total: array{merchandise: float, delivery: float, tax: float,
     *                       fees: float, recognized_sales: float,
     *                       deposit_collected: float, order_count: int}},
     * } $aggregate The weekly sales aggregate.
     *
     * @return string A `<section>` containing the table inside an `overflow-x: auto` scroller.
     */
    private static function renderTable(array $aggregate): string
    {
        $rows = '';
        foreach ($aggregate['weeks'] as $week) {
            $rows .= self::renderTableRow($week['week_start'], $week['iso_week'], $week['channels'], $week['total']);
        }

        $rows .= self::renderTableRow(
            'TOTAL',
            '',
            $aggregate['totals']['channels'],
            $aggregate['totals']['total'],
            true
        );

        $head = self::renderTableHead();

        return <<<HTML
        <section class="report-section">
        <h2>Weekly Detail</h2>
        <div class="table-scroll">
        <table class="weekly-table">
        <thead>{$head}</thead>
        <tbody>{$rows}</tbody>
        </table>
        </div>
        </section>
        HTML;
    }

    /**
     * Render the two-row table head: a channel-grouping row and a per-field row.
     *
     * @return string The `<tr>` markup for both header rows.
     */
    private static function renderTableHead(): string
    {
        $fieldCount = 6; // merchandise, delivery, tax, fees, recognized_sales, order_count

        $groupRow = '<tr><th rowspan="2">Week</th><th rowspan="2">ISO Week</th>';
        foreach (SalesChannel::ALL as $channel) {
            $groupRow .= sprintf(
                '<th colspan="%d">%s</th>',
                $fieldCount,
                self::escape(SalesChannel::label($channel))
            );
        }
        $groupRow .= '<th rowspan="2">Quote Deposit</th>';
        $groupRow .= sprintf('<th colspan="%d">Total</th>', $fieldCount);
        $groupRow .= '</tr>';

        $fieldLabels = ['Merch.', 'Delivery', 'Tax', 'Fees', 'Recognized', 'Orders'];
        $fieldRow    = '<tr>';
        for ($group = 0; $group < count(SalesChannel::ALL) + 1; $group++) {
            foreach ($fieldLabels as $label) {
                $fieldRow .= sprintf('<th class="num">%s</th>', $label);
            }
        }
        $fieldRow .= '</tr>';

        return $groupRow . $fieldRow;
    }

    /**
     * Render one table row (a week, or the trailing `TOTAL` row).
     *
     * @param string $weekStart The week's start date, or `'TOTAL'`.
     * @param string $isoWeek   The ISO week label, or `''` for the totals row.
     * @param array<string, array{merchandise: float, delivery: float, tax: float,
     *              fees: float, recognized_sales: float, deposit_collected: float,
     *              order_count: int}> $channels Per-channel totals, keyed by
     *        {@see \App\Support\SalesChannel::ALL}.
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float,
     *              order_count: int} $total Combined totals across channels.
     * @param bool $isTotalRow Whether this is the trailing totals row (styled distinctly).
     *
     * @return string A `<tr>` element.
     */
    private static function renderTableRow(
        string $weekStart,
        string $isoWeek,
        array $channels,
        array $total,
        bool $isTotalRow = false
    ): string {
        $class = $isTotalRow ? ' class="total-row"' : '';
        $cells = sprintf('<td>%s</td><td>%s</td>', self::escape($weekStart), self::escape($isoWeek));

        foreach (SalesChannel::ALL as $channel) {
            $cells .= self::renderChannelCells($channels[$channel]);
        }

        $cells .= sprintf(
            '<td class="num">%s</td>',
            self::escape(self::formatMoney($channels[SalesChannel::QUOTE]['deposit_collected']))
        );
        $cells .= self::renderChannelCells($total);

        return "<tr{$class}>{$cells}</tr>";
    }

    /**
     * Render the six field cells (merchandise through order_count) for one
     * channel or totals figure.
     *
     * @param array{merchandise: float, delivery: float, tax: float, fees: float,
     *              recognized_sales: float, deposit_collected: float,
     *              order_count: int} $totals One channel's (or the combined) figures.
     *
     * @return string Six `<td class="num">` cells.
     */
    private static function renderChannelCells(array $totals): string
    {
        return sprintf(
            '<td class="num">%s</td><td class="num">%s</td><td class="num">%s</td>' .
                '<td class="num">%s</td><td class="num">%s</td><td class="num">%d</td>',
            self::escape(self::formatMoney($totals['merchandise'])),
            self::escape(self::formatMoney($totals['delivery'])),
            self::escape(self::formatMoney($totals['tax'])),
            self::escape(self::formatMoney($totals['fees'])),
            self::escape(self::formatMoney($totals['recognized_sales'])),
            $totals['order_count']
        );
    }

    /**
     * Render the warnings/audit section, visually de-emphasised but always
     * present — even an empty report says so explicitly rather than omitting
     * the section.
     *
     * Reads the aggregate's top-level `warnings` only, and deliberately does
     * not merge in each week's own list: WeeklySalesAggregator files every row
     * warning under its week *and* in the flat top-level list, so that list is
     * already complete. Merging both would list each week-attributable warning
     * twice. This section has no week column to justify the per-week copy — the
     * warning text names its own `source_id`, and the audit CSV from
     * {@see \App\Support\SalesReportCsv::warningRows()} is where the week
     * attribution lives.
     *
     * @param array{warnings: list<string>} $aggregate The aggregate (only the
     *        top-level `warnings` is read).
     *
     * @return string A `<section>` listing every warning, or a "no warnings" message.
     */
    private static function renderWarnings(array $aggregate): string
    {
        $warnings = $aggregate['warnings'];

        if ($warnings === []) {
            return '<section class="report-section warnings">' .
                '<h2>Warnings</h2><p class="muted">No warnings for this report.</p>' .
            '</section>';
        }

        $items = '';
        foreach ($warnings as $warning) {
            $items .= sprintf('<li>%s</li>', self::escape($warning));
        }

        return "<section class=\"report-section warnings\"><h2>Warnings</h2><ul>{$items}</ul></section>";
    }

    /**
     * Format a dollar amount for display, with a currency symbol and a
     * thousands separator (unlike {@see \App\Support\SalesReportCsv}, which
     * deliberately omits both for spreadsheet safety — this output is prose,
     * not a data cell).
     *
     * @param float $amount Dollar amount, already rounded to 2dp upstream.
     *
     * @return string A dollar-formatted string, e.g. `'$1,234.50'`.
     *
     * @example
     *   self::formatMoney(1234.5); // '$1,234.50'
     */
    private static function formatMoney(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    /**
     * Escape a string for safe inclusion in the HTML document.
     *
     * @param string $value Untrusted or trusted text to escape.
     *
     * @return string The escaped text, safe for HTML body context.
     *
     * @example
     *   self::escape('<script>alert(1)</script>'); // '&lt;script&gt;alert(1)&lt;/script&gt;'
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Build the inline stylesheet, including the light/dark design tokens
     * from the report's validated chart specification, declared under both
     * `prefers-color-scheme` and `[data-theme="dark"]` so a future manual
     * theme toggle takes precedence over the OS preference.
     *
     * @return string A complete CSS stylesheet body (without `<style>` tags).
     */
    private static function css(): string
    {
        return <<<CSS
        :root {
            --series-merch: #2a78d6;
            --series-delivery: #eb6834;
            --series-tax: #1baf7a;
            --surface-1: #fcfcfb;
            --page: #f9f9f7;
            --text-primary: #0b0b0b;
            --text-secondary: #52514e;
            --text-muted: #898781;
            --gridline: #e1e0d9;
            --baseline: #c3c2b7;
            --border: rgba(11,11,11,0.10);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --series-merch: #3987e5;
                --series-delivery: #d95926;
                --series-tax: #199e70;
                --surface-1: #1a1a19;
                --page: #0d0d0d;
                --text-primary: #ffffff;
                --text-secondary: #c3c2b7;
                --text-muted: #898781;
                --gridline: #2c2c2a;
                --baseline: #383835;
                --border: rgba(255,255,255,0.10);
            }
        }

        :root[data-theme="dark"] {
            --series-merch: #3987e5;
            --series-delivery: #d95926;
            --series-tax: #199e70;
            --surface-1: #1a1a19;
            --page: #0d0d0d;
            --text-primary: #ffffff;
            --text-secondary: #c3c2b7;
            --text-muted: #898781;
            --gridline: #2c2c2a;
            --baseline: #383835;
            --border: rgba(255,255,255,0.10);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--page);
            color: var(--text-primary);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        .report {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px 20px 48px;
        }

        .report-header h1 {
            margin: 0 0 4px;
            font-size: 1.5rem;
        }

        .report-subhead {
            margin: 0 0 12px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .banner-warning {
            margin: 0 0 16px;
            padding: 10px 14px;
            border: 1px solid var(--series-delivery);
            border-left-width: 4px;
            border-radius: 6px;
            background: var(--surface-1);
            color: var(--text-primary);
            font-weight: 600;
        }

        .stat-tiles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin: 20px 0;
        }

        .stat-tile {
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .stat-tile-hero {
            grid-column: span 2;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .stat-tile-hero .stat-value {
            font-size: 1.8rem;
        }

        .report-section {
            margin: 28px 0;
        }

        .report-section h2 {
            font-size: 1.1rem;
            margin: 0 0 10px;
        }

        .chart-wrap {
            background: var(--surface-1);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
        }

        .sales-chart {
            width: 100%;
            height: auto;
            display: block;
        }

        .chart-empty {
            padding: 40px 12px;
            text-align: center;
            color: var(--text-muted);
        }

        .bar-merch { fill: var(--series-merch); }
        .bar-delivery { fill: var(--series-delivery); }
        .bar-tax { fill: var(--series-tax); }

        .chart-gridline { stroke: var(--gridline); stroke-width: 1; }
        .chart-baseline { stroke: var(--baseline); stroke-width: 1.5; }

        .chart-axis-label {
            fill: var(--text-muted);
            font-size: 11px;
        }

        .chart-legend {
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin: 10px 0 0;
            padding: 0;
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .chart-legend li {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .legend-swatch {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 2px;
        }

        .legend-merch { background: var(--series-merch); }
        .legend-delivery { background: var(--series-delivery); }
        .legend-tax { background: var(--series-tax); }

        .chart-annotation {
            margin: 8px 0 0;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .table-scroll {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
        }

        .weekly-table {
            border-collapse: collapse;
            width: 100%;
            min-width: 960px;
            font-size: 0.85rem;
        }

        .weekly-table th,
        .weekly-table td {
            padding: 6px 10px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            white-space: nowrap;
        }

        .weekly-table td.num,
        .weekly-table th.num {
            font-variant-numeric: tabular-nums;
            text-align: right;
        }

        .weekly-table thead th {
            background: var(--surface-1);
            color: var(--text-secondary);
            position: sticky;
            top: 0;
        }

        .weekly-table tr.total-row {
            font-weight: 700;
            background: var(--surface-1);
        }

        .warnings {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .warnings ul {
            margin: 0;
            padding-left: 20px;
        }

        .warnings .muted {
            color: var(--text-muted);
        }
        CSS;
    }
}
