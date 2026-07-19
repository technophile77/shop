<div class="admin-content">

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
    <div>
        <h1 style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.8rem; font-weight:600; color:#1a1a1a; margin-bottom:0.25rem">Store Closures</h1>
        <p style="color:#666; font-size:0.9rem"><?= count($closures) ?> closure<?= count($closures) !== 1 ? 's' : '' ?></p>
    </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
<div style="margin-bottom:1.5rem; padding:0.875rem 1.125rem; border-radius:6px;
    <?= $_SESSION['flash']['type'] === 'success'
        ? 'background:#e6f4ea; color:#2e7d32; border:1px solid #a8d5b1'
        : ($_SESSION['flash']['type'] === 'warning'
            ? 'background:#fff4e5; color:#8a5300; border:1px solid #ffcc80'
            : 'background:#ffebee; color:#b71c1c; border:1px solid #f5c6cb') ?>">
    <?= htmlspecialchars($_SESSION['flash']['message']) ?>
</div>
<?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- ============================================================
     CLOSURE CALENDAR
     ============================================================ -->
<div class="admin-card"
     x-data="closureCalendar(<?= htmlspecialchars($closuresJson, ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars($todayYmd, ENT_QUOTES, 'UTF-8') ?>')">
    <p class="admin-card-title" style="margin-bottom:1.25rem">Add a Closure</p>

    <!-- Legend -->
    <div style="display:flex; gap:1.5rem; margin-bottom:1.25rem; font-size:0.8rem; color:#666; flex-wrap:wrap">
        <span style="display:inline-flex; align-items:center; gap:0.4rem">
            <span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#fee2e2; border:1px solid #fca5a5"></span>
            Already closed
        </span>
        <span style="display:inline-flex; align-items:center; gap:0.4rem">
            <span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:var(--color-primary)"></span>
            Selected range
        </span>
    </div>

    <!-- Month header -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.875rem">
        <button type="button" class="admin-btn admin-btn-ghost" style="padding:0.4rem 0.75rem" @click="prevMonth()" aria-label="Previous month">&lsaquo;</button>
        <p style="font-family:'Cormorant Garamond',Georgia,serif; font-size:1.3rem; font-weight:600; color:#1a1a1a" x-text="monthLabel"></p>
        <button type="button" class="admin-btn admin-btn-ghost" style="padding:0.4rem 0.75rem" @click="nextMonth()" aria-label="Next month">&rsaquo;</button>
    </div>

    <!-- Weekday header -->
    <div style="display:grid; grid-template-columns:repeat(7,1fr); gap:4px; margin-bottom:4px; text-align:center; font-family:'Montserrat',sans-serif; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.05em; color:#999">
        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
    </div>

    <!-- Day grid -->
    <div @mouseleave="hover = ''">
        <template x-for="(week, wi) in weeks" :key="wi">
            <div style="display:grid; grid-template-columns:repeat(7,1fr); gap:4px; margin-bottom:4px">
                <template x-for="(cell, ci) in week" :key="ci">
                    <button type="button"
                            :disabled="!cell.inMonth || cell.closed"
                            :style="cellStyle(cell)"
                            :title="cell.closed ? cell.reason : ''"
                            @click="pick(cell)"
                            @mouseenter="onHover(cell)">
                        <span x-show="cell.inMonth" x-text="cell.day"></span>
                    </button>
                </template>
            </div>
        </template>
    </div>

    <p style="margin-top:0.75rem; font-size:0.85rem; color:#666" x-text="helperText"></p>
    <p x-show="rejectMessage" x-text="rejectMessage" style="margin-top:0.4rem; font-size:0.85rem; color:#b71c1c"></p>

    <!-- Add-closure form -->
    <form method="POST" action="/admin/closures" style="margin-top:1.25rem; display:flex; gap:0.875rem; align-items:end; flex-wrap:wrap">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
        <input type="hidden" name="start_date" :value="selStart">
        <input type="hidden" name="end_date" :value="selEnd">

        <div class="admin-form-group" style="margin-bottom:0; flex:1; min-width:220px">
            <label for="closure_reason">Reason <span style="color:#999; font-weight:400; font-size:0.75rem">(optional — shown to customers)</span></label>
            <input type="text" id="closure_reason" name="reason" x-model="reason" maxlength="255" placeholder="e.g. Independence Day week">
        </div>

        <button type="submit" class="admin-btn admin-btn-primary" :disabled="!canSubmit">Add Closure</button>
        <button type="button" class="admin-btn admin-btn-ghost" x-show="selStart" @click="clearSelection()">Clear</button>
    </form>
</div>

<!-- ============================================================
     EXISTING CLOSURES TABLE
     ============================================================ -->
<div class="admin-card" style="padding:0">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="padding-left:1.5rem">Dates</th>
                    <th>Reason</th>
                    <th style="padding-right:1.5rem">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($closures as $c): ?>
                <tr>
                    <td style="padding-left:1.5rem"><?= htmlspecialchars(\App\Support\Closures::formatRange($c, $months)) ?></td>
                    <td>
                        <?php if (!empty($c['reason'])): ?>
                            <?= htmlspecialchars($c['reason']) ?>
                        <?php else: ?>
                            <span style="color:var(--color-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding-right:1.5rem">
                        <form method="POST"
                              action="/admin/closures/<?= (int) $c['id'] ?>/delete"
                              style="display:inline-block"
                              data-confirm="Delete this closure? Customers will be able to book these dates again.">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                            <button type="submit"
                                    class="admin-btn"
                                    style="padding:0.4rem 0.875rem; font-size:0.7rem;
                                           background:#fee2e2; color:#b91c1c; border-color:#fca5a5">
                                Delete
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($closures)): ?>
                <tr>
                    <td colspan="3" style="text-align:center; padding:3rem 1.5rem; color:var(--color-muted)">
                        No store closures yet. Use the calendar above to add one.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /.admin-content -->

<script>
/**
 * closureCalendar — Alpine component powering the month-grid store-closure picker.
 *
 * Builds a Sunday-first month grid from local date parts only (never
 * toISOString(), which is UTC and shifts the calendar date in
 * America/Chicago after 19:00 CDT). Lets the admin click a start date then
 * an end date to select an inclusive range, blocking spans that would
 * swallow an already-closed date, and posts the resulting range to
 * POST /admin/closures.
 *
 * @param {Array<{id:number,start_date:string,end_date:string,reason:string}>} closures
 *   Existing closures (id/start_date/end_date/reason only — no created_at).
 * @param {string} todayYmd Today's date in the app's timezone, 'Y-m-d'.
 *
 * @returns {object} Alpine component data/methods, bound via x-data.
 *
 * @example
 *   // <div x-data="closureCalendar(closures, '2026-07-19')"> ... </div>
 */
function closureCalendar(closures, todayYmd) {
    const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    // Build a 'Y-m-d' string from LOCAL date parts only — see DocBlock above.
    const ymd = (y, m, d) => `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;

    const [ty, tm] = todayYmd.split('-').map(Number);

    return {
        closures,
        today: todayYmd,
        viewYear: ty,
        viewMonth: tm - 1,
        selStart: '',
        selEnd: '',
        hover: '',
        reason: '',
        rejectMessage: '',

        /**
         * The month/year label shown above the grid, e.g. "July 2026".
         */
        get monthLabel() {
            return `${MONTH_NAMES[this.viewMonth]} ${this.viewYear}`;
        },

        /**
         * The tentative end date used to preview a range while hovering,
         * active only between picking a start date and an end date.
         */
        get previewEnd() {
            if (!this.selStart || this.selEnd) return '';
            return this.hover && this.hover >= this.selStart ? this.hover : '';
        },

        /**
         * True once both a start and an end date have been picked.
         */
        get canSubmit() {
            return this.selStart !== '' && this.selEnd !== '';
        },

        /**
         * The instructional line shown beneath the grid.
         */
        get helperText() {
            if (this.selStart && this.selEnd) {
                return this.selStart === this.selEnd
                    ? `Selected: ${this.formatDisplay(this.selStart)}`
                    : `Selected: ${this.formatDisplay(this.selStart)} – ${this.formatDisplay(this.selEnd)}`;
            }
            if (this.selStart) {
                return `Start: ${this.formatDisplay(this.selStart)} — now click the end date`;
            }
            return 'Click a start date';
        },

        /**
         * The full month grid as an array of weeks (7-cell rows, Sunday first).
         * Leading/trailing days outside the viewed month are blank placeholders.
         */
        get weeks() {
            const year = this.viewYear;
            const month = this.viewMonth;
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const startWeekday = new Date(year, month, 1).getDay();

            const cells = [];

            for (let i = 0; i < startWeekday; i++) {
                cells.push({ inMonth: false });
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const key = ymd(year, month, day);
                const closure = this.closureFor(key);

                cells.push({
                    inMonth: true,
                    day,
                    ymd: key,
                    closed: closure !== null,
                    reason: closure ? (closure.reason || '') : '',
                    past: key < this.today,
                    selected: this.inSelection(key),
                    isStart: key === this.selStart,
                    isEnd: key === (this.selEnd || this.previewEnd),
                });
            }

            while (cells.length % 7 !== 0) {
                cells.push({ inMonth: false });
            }

            const weeks = [];
            for (let i = 0; i < cells.length; i += 7) {
                weeks.push(cells.slice(i, i + 7));
            }

            return weeks;
        },

        /**
         * The closure row covering a given date, or null when the date is open.
         *
         * @param {string} key Date to check, 'Y-m-d'.
         * @returns {object|null}
         */
        closureFor(key) {
            return this.closures.find((c) => key >= c.start_date && key <= c.end_date) || null;
        },

        /**
         * Whether a date falls inside any existing closure.
         *
         * @param {string} key Date to check, 'Y-m-d'.
         * @returns {boolean}
         */
        isClosed(key) {
            return this.closureFor(key) !== null;
        },

        /**
         * Whether a date falls inside the current selection (committed or,
         * while picking the end date, the hover preview).
         *
         * @param {string} key Date to check, 'Y-m-d'.
         * @returns {boolean}
         */
        inSelection(key) {
            if (!this.selStart) return false;

            const end = this.selEnd || this.previewEnd;
            if (!end) return key === this.selStart;

            const [a, b] = this.selStart <= end ? [this.selStart, end] : [end, this.selStart];
            return key >= a && key <= b;
        },

        /**
         * Whether the inclusive span [a, b] would swallow any existing closure.
         *
         * @param {string} a One end of the proposed span, 'Y-m-d'.
         * @param {string} b The other end of the proposed span, 'Y-m-d'.
         * @returns {boolean}
         */
        spanHitsClosure(a, b) {
            const [lo, hi] = a <= b ? [a, b] : [b, a];
            return this.closures.some((c) => lo <= c.end_date && c.start_date <= hi);
        },

        /**
         * Handle a click on a day cell: first click sets the start date,
         * second click sets the end date (rejecting a span that would
         * swallow an existing closure), and clicking an earlier date than
         * the current start re-anchors the start date.
         *
         * @param {object} cell The grid cell that was clicked.
         * @returns {void}
         */
        pick(cell) {
            if (!cell.inMonth || cell.closed) return;

            this.rejectMessage = '';

            if (!this.selStart || this.selEnd) {
                this.selStart = cell.ymd;
                this.selEnd = '';
                return;
            }

            if (cell.ymd < this.selStart) {
                this.selStart = cell.ymd;
                this.selEnd = '';
                return;
            }

            if (this.spanHitsClosure(this.selStart, cell.ymd)) {
                this.rejectMessage = 'That range includes a date that is already closed. Choose a different end date.';
                this.selEnd = '';
                return;
            }

            this.selEnd = cell.ymd;
        },

        /**
         * Track the hovered cell so the tentative range can preview while
         * the admin is choosing an end date. Ignored for out-of-month or
         * already-closed cells, which can never become part of a selection.
         *
         * @param {object} cell The grid cell being hovered.
         * @returns {void}
         */
        onHover(cell) {
            if (cell.inMonth && !cell.closed) {
                this.hover = cell.ymd;
            }
        },

        /**
         * Reset the current selection and any rejection message.
         *
         * @returns {void}
         */
        clearSelection() {
            this.selStart = '';
            this.selEnd = '';
            this.rejectMessage = '';
        },

        /**
         * Format a 'Y-m-d' string as "{Month} {day}, {year}" for display.
         *
         * @param {string} key Date to format, 'Y-m-d'.
         * @returns {string}
         */
        formatDisplay(key) {
            const [y, m, d] = key.split('-').map(Number);
            return `${MONTH_NAMES[m - 1]} ${d}, ${y}`;
        },

        /**
         * Compute the inline style for a single day cell based on its state.
         *
         * @param {object} cell The grid cell to style.
         * @returns {string} A CSS declaration string for the :style binding.
         */
        cellStyle(cell) {
            if (!cell.inMonth) {
                return 'visibility:hidden; border:none; background:transparent; padding:0;';
            }

            const base = 'aspect-ratio:1; border-radius:4px; font-size:0.85rem; font-family:inherit; padding:0;';
            const dim = cell.past ? ' opacity:0.55;' : '';

            if (cell.closed) {
                return `${base} background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; cursor:not-allowed;${dim}`;
            }

            if (cell.selected) {
                return `${base} background:var(--color-primary); color:#fff; border:1px solid var(--color-primary); cursor:pointer; font-weight:600;${dim}`;
            }

            return `${base} background:#fff; color:#333; border:1px solid #e8e8e8; cursor:pointer;${dim}`;
        },

        /**
         * Move the viewed month back by one, rolling the year over at January.
         *
         * @returns {void}
         */
        prevMonth() {
            if (this.viewMonth === 0) {
                this.viewMonth = 11;
                this.viewYear -= 1;
            } else {
                this.viewMonth -= 1;
            }
        },

        /**
         * Move the viewed month forward by one, rolling the year over at December.
         *
         * @returns {void}
         */
        nextMonth() {
            if (this.viewMonth === 11) {
                this.viewMonth = 0;
                this.viewYear += 1;
            } else {
                this.viewMonth += 1;
            }
        },
    };
}
</script>
