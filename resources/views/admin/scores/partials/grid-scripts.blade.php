@push('scripts')
<script>
    /**
     * Score entry helpers, shared by the single-subject screen and the grid.
     *
     * Kept event-driven rather than per-cell Alpine state: a full entrance cohort
     * is hundreds of boxes, and binding each one individually makes the page crawl.
     */
    function scoreGrid(gradeScale) {
        const ABSENT = ['A', 'ABS', 'AB', 'X', '-', '--', 'N/A', 'NA'];

        return {
            filter: '',
            savedCount: 0,

            init() {
                // Paint the marks already on the page, going through paint()
                // rather than onInput, so nothing counts as changed until somebody
                // actually types.
                this.$nextTick(() => {
                    document.querySelectorAll('input[data-max]').forEach((input) => this.paint(input));

                    // A row that is already marked absent has nothing to type into,
                    // otherwise the box and the tick disagree and the tick wins.
                    document.querySelectorAll('input[name^="absent"]:checked').forEach((box) => {
                        this.markAbsent(box, true);
                    });

                    this.savedCount = 0;
                });
            },

            get visibleRows() {
                return document.querySelectorAll('.score-grid tbody tr:not([hidden])').length;
            },

            /** Blank is "not marked yet"; A means absent. The two are different. */
            parse(raw) {
                const text = String(raw ?? '').trim().toUpperCase();

                if (text === '') return { state: 'empty' };
                if (ABSENT.includes(text)) return { state: 'absent' };
                if (!/^\d+(\.\d+)?$/.test(text)) return { state: 'invalid' };

                return { state: 'mark', value: parseFloat(text) };
            },

            gradeFor(percentage) {
                for (const band of gradeScale) {
                    if (percentage >= band.min) return band.grade;
                }

                return null;
            },

            /** Fills in the small badge under the box with the grade and percentage. */
            paint(input) {
                this.paintBadge(input);
                this.paintRow(input);
            },

            paintBadge(input) {
                const badge = input.closest('td')?.querySelector('[data-feedback]');
                if (!badge) return;

                const max = parseFloat(input.dataset.max || '0');
                const pass = parseFloat(input.dataset.pass || '0');
                const parsed = this.parse(input.value);

                const base = 'mt-1 block text-center text-[11px] font-semibold ';
                input.classList.remove('ring-2', 'ring-rose-400');

                if (parsed.state === 'empty') {
                    badge.textContent = '';
                    badge.className = base + 'text-transparent';
                    return;
                }

                if (parsed.state === 'absent') {
                    badge.textContent = 'absent';
                    badge.className = base + 'text-rose-600 dark:text-rose-400';
                    return;
                }

                if (parsed.state === 'invalid' || parsed.value < 0 || (max > 0 && parsed.value > max)) {
                    badge.textContent = max > 0 && parsed.state === 'mark' ? 'over ' + max : 'not a mark';
                    badge.className = base + 'text-rose-600 dark:text-rose-400';
                    input.classList.add('ring-2', 'ring-rose-400');
                    return;
                }

                const percentage = max > 0 ? (parsed.value / max) * 100 : 0;
                const grade = this.gradeFor(percentage);

                badge.textContent = (grade ? grade + ' · ' : '') + percentage.toFixed(0) + '%';
                badge.className = base + (percentage < pass ? 'text-amber-600' : 'text-emerald-600 dark:text-emerald-400');
            },

            /** Two decimals at most, with trailing zeros dropped: 225, 74.33. */
            trim(value) {
                return String(parseFloat(Number(value).toFixed(2)));
            },

            /**
             * The row's total and average, on the same rule the cutoff desk uses:
             * each mark becomes a percentage of its own paper FIRST, and the
             * average is over the papers that carry a mark. A blank paper is not a
             * zero and an absence is not a zero, so neither drags the average down.
             *
             * Mirrors ScoreEntryService::summarise() and AdmissionService::summarise().
             * If those three ever disagree, a candidate's place is decided by a
             * number that was never on this screen.
             */
            paintRow(input) {
                const row = input.closest('tr');
                const totalCell = row?.querySelector('[data-total]');
                const averageCell = row?.querySelector('[data-average]');

                if (!row || !totalCell || !averageCell) return;

                let sum = 0;
                let marked = 0;

                row.querySelectorAll('input[data-max]').forEach((cell) => {
                    const parsed = this.parse(cell.value);
                    const max = parseFloat(cell.dataset.max || '0');

                    // An over-max mark is refused on save, so it must not inflate
                    // the total while somebody is still typing it.
                    if (parsed.state !== 'mark' || max <= 0 || parsed.value < 0 || parsed.value > max) {
                        return;
                    }

                    sum += (parsed.value / max) * 100;
                    marked++;
                });

                const average = marked === 0 ? 0 : sum / marked;
                const cutoff = parseFloat(row.dataset.cutoff || '0');

                totalCell.textContent = this.trim(sum);

                averageCell.textContent = this.trim(average) + '%';
                averageCell.className = 'font-semibold '
                    + (marked === 0 ? 'text-muted' : (average >= cutoff ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'));

                const countCell = row.querySelector('[data-count]');
                if (countCell) {
                    countCell.textContent = marked === 1 ? '1 paper' : marked + ' papers';
                }
            },

            onInput(event) {
                const input = event.target;

                if (!input.matches('input[data-max]')) return;

                if (input.dataset.touched !== '1') {
                    input.dataset.touched = '1';
                    this.savedCount++;
                }

                this.paint(input);
            },

            /**
             * Ticking "absent" clears and greys the mark box so the two can never
             * disagree. The mark is remembered, because a mis-click must not cost
             * the officer the number they just read off the script.
             */
            toggleAbsent(event) {
                this.markAbsent(event.target, event.target.checked);
            },

            markAbsent(checkbox, absent) {
                const cell = checkbox.closest('tr')?.querySelector('input[data-max]');

                // A locked cell is locked by permission, which no tick can undo.
                if (!cell || cell.dataset.locked === '1') return;

                if (absent) {
                    cell.dataset.remembered = cell.value;
                    cell.value = '';
                    cell.readOnly = true;
                } else {
                    cell.readOnly = false;

                    if (cell.dataset.remembered) {
                        cell.value = cell.dataset.remembered;
                    }
                }

                this.paint(cell);
            },

            /** Enter moves down the same column, which is how a column gets keyed. */
            onKeydown(event) {
                if (event.key !== 'Enter') return;

                const input = event.target;

                // Deliberately narrow: a blanket .prevent on the container would
                // also swallow Enter on the Save button.
                if (!input.matches('input[data-row][data-column]')) return;

                event.preventDefault();

                const next = document.querySelector(
                    `input[data-row="${parseInt(input.dataset.row) + 1}"][data-column="${input.dataset.column}"]`,
                );

                if (next) {
                    next.focus();
                    next.select();
                }
            },

            /**
             * A column copied out of Excel arrives as one multi-line blob, which a
             * single box would swallow whole. Spread it over the grid instead.
             */
            onPaste(event) {
                const input = event.target;

                if (!input.matches('input[data-row][data-column]')) return;

                const clipboard = event.clipboardData || window.clipboardData;
                const text = clipboard ? clipboard.getData('text') : '';

                if (!text || (!text.includes('\n') && !text.includes('\t'))) return;

                event.preventDefault();

                const rows = text.replace(/\r/g, '').split('\n')
                    .map((line) => line.split('\t'))
                    .filter((cells) => cells.length > 1 || cells[0].trim() !== '');

                const startRow = parseInt(input.dataset.row);
                const startColumn = parseInt(input.dataset.column);
                let written = 0;

                rows.forEach((cells, rowOffset) => {
                    cells.forEach((value, columnOffset) => {
                        const target = document.querySelector(
                            `input[data-row="${startRow + rowOffset}"][data-column="${startColumn + columnOffset}"]`,
                        );

                        if (!target || target.readOnly) return;

                        target.value = value.trim();
                        target.dataset.touched = '1';
                        this.paint(target);
                        written++;
                    });
                });

                this.savedCount += written;
            },

            /** Hides rows that do not match. Hidden boxes still submit their values. */
            applyFilter() {
                const needle = this.filter.trim().toLowerCase();

                document.querySelectorAll('.score-grid tbody tr').forEach((row) => {
                    row.hidden = needle !== '' && !(row.dataset.match ?? '').includes(needle);
                });
            },
        };
    }
</script>
@endpush
