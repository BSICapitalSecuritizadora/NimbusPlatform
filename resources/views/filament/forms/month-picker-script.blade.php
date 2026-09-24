<script>
    (function () {
        if (window.bsiMonthPicker) return;

        var SHORT_LABELS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        var LONG_LABELS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        var MIN_YEAR = 1900;
        var MAX_YEAR = 2100;
        var VIEWPORT_GUTTER = 16;
        var PANEL_OFFSET = 6;

        function pad(month) {
            return String(month).padStart(2, '0');
        }

        function monthIndex(parsed) {
            return parsed.year * 12 + (parsed.month - 1);
        }

        /**
         * Estado `Y-m` (ou `Y-m-d` herdado) → { year, month }. Espelha MonthPicker::parseMonth().
         */
        function parseMonth(value) {
            if (typeof value !== 'string') return null;

            var match = value.trim().match(/^(\d{4})-(\d{2})(?:-(\d{2}))?$/);
            if (!match) return null;

            var year = Number(match[1]);
            var month = Number(match[2]);

            if (month < 1 || month > 12) return null;

            return { year: year, month: month };
        }

        window.bsiMonthPicker = function (config) {
            return {
                id: config.id || ('bmp_' + Math.random().toString(36).slice(2)),
                state: config.state,
                notBeforeStatePath: config.notBeforeStatePath,
                notAfterStatePath: config.notAfterStatePath,
                rangeMessage: config.rangeMessage,
                columns: config.columns || 4,
                displayMode: config.displayMode || 'numeric',
                clearValue: config.clearValue !== undefined ? config.clearValue : null,
                typedText: '',
                formatError: null,
                isOpen: false,
                focusedYear: new Date().getFullYear(),
                focusedMonth: new Date().getMonth() + 1,

                init: function () {
                    var self = this;

                    this.typedText = this.displayFor(this.state);

                    this.$watch('state', function (value) {
                        self.typedText = self.displayFor(value);
                        self.formatError = null;
                    });

                    // O painel de filtros (x-float) fecha no Escape ouvido na `window` em captura.
                    // Registrado antes dele, este ouvinte fecha só o seletor de competência.
                    this.onWindowKeydown = function (event) {
                        if (event.key !== 'Escape' || !self.isOpen) return;

                        event.preventDefault();
                        event.stopImmediatePropagation();
                        self.close(true);
                    };

                    this.onOtherPickerOpen = function (event) {
                        if (event.detail && event.detail.id !== self.id && self.isOpen) {
                            self.close(false);
                        }
                    };

                    this.onViewportChange = function () {
                        if (!self.isOpen || self.positionFrame) return;

                        self.positionFrame = window.requestAnimationFrame(function () {
                            self.positionFrame = null;
                            self.position();
                        });
                    };

                    window.addEventListener('keydown', this.onWindowKeydown, true);
                    window.addEventListener('bsi-month-picker:open', this.onOtherPickerOpen);
                    window.addEventListener('resize', this.onViewportChange);
                    window.addEventListener('scroll', this.onViewportChange, true);
                },

                destroy: function () {
                    window.removeEventListener('keydown', this.onWindowKeydown, true);
                    window.removeEventListener('bsi-month-picker:open', this.onOtherPickerOpen);
                    window.removeEventListener('resize', this.onViewportChange);
                    window.removeEventListener('scroll', this.onViewportChange, true);
                },

                displayFor: function (value) {
                    var parsed = parseMonth(value);

                    if (!parsed) return '';

                    if (this.displayMode === 'verbose') {
                        return LONG_LABELS[parsed.month - 1] + ' de ' + parsed.year;
                    }

                    return pad(parsed.month) + '/' + parsed.year;
                },

                boundAt: function (statePath) {
                    return statePath ? parseMonth(this.$wire.$get(statePath)) : null;
                },

                get hasRangeError() {
                    var own = parseMonth(this.state);
                    var start = this.boundAt(this.notBeforeStatePath);

                    return !!(own && start && monthIndex(own) < monthIndex(start));
                },

                isMonthDisabled: function (year, month) {
                    var candidate = monthIndex({ year: year, month: month });
                    var start = this.boundAt(this.notBeforeStatePath);
                    var end = this.boundAt(this.notAfterStatePath);

                    return !!((start && candidate < monthIndex(start)) || (end && candidate > monthIndex(end)));
                },

                isMonthSelected: function (year, month) {
                    var own = parseMonth(this.state);

                    return !!own && own.year === year && own.month === month;
                },

                isCurrentMonth: function (year, month) {
                    var now = new Date();

                    return now.getFullYear() === year && now.getMonth() + 1 === month;
                },

                isCurrentMonthDisabled: function () {
                    var now = new Date();

                    return this.isMonthDisabled(now.getFullYear(), now.getMonth() + 1);
                },

                monthShortLabel: function (month) {
                    return SHORT_LABELS[month - 1];
                },

                monthLongLabel: function (month) {
                    return LONG_LABELS[month - 1];
                },

                setState: function (value) {
                    this.state = value;
                    this.typedText = this.displayFor(value);
                    this.formatError = null;
                },

                formatTyped: function () {
                    var digits = String(this.typedText || '').replace(/\D/g, '').slice(0, 6);

                    this.typedText = digits.length > 2 ? digits.slice(0, 2) + '/' + digits.slice(2) : digits;
                    this.formatError = null;
                },

                commitTyped: function () {
                    var text = String(this.typedText || '').trim();

                    if (text === '') {
                        this.setState(null);

                        return;
                    }

                    var match = text.match(/^(\d{2})\/(\d{4})$/);
                    var month = match ? Number(match[1]) : 0;
                    var year = match ? Number(match[2]) : 0;

                    if (!match || month < 1 || month > 12 || year < MIN_YEAR || year > MAX_YEAR) {
                        this.typedText = this.displayFor(this.state);
                        this.formatError = 'Informe a competência no formato mm/aaaa.';

                        return;
                    }

                    this.setState(year + '-' + pad(month));
                },

                toggle: function () {
                    this.isOpen ? this.close(true) : this.open();
                },

                open: function () {
                    if (this.isOpen) return;

                    var self = this;
                    window.dispatchEvent(new CustomEvent('bsi-month-picker:open', { detail: { id: this.id } }));

                    var anchor = parseMonth(this.state)
                        || this.boundAt(this.notBeforeStatePath)
                        || this.boundAt(this.notAfterStatePath)
                        || { year: new Date().getFullYear(), month: new Date().getMonth() + 1 };

                    this.focusedYear = anchor.year;
                    this.focusedMonth = anchor.month;
                    this.$refs.panel.style.visibility = 'hidden';
                    this.isOpen = true;

                    this.$nextTick(function () {
                        self.position();
                        self.$refs.panel.style.visibility = '';
                        self.focusMonthButton();
                    });
                },

                close: function (shouldReturnFocus) {
                    if (!this.isOpen) return;

                    this.isOpen = false;

                    if (shouldReturnFocus && this.$refs.toggle) {
                        this.$refs.toggle.focus({ preventScroll: true });
                    }
                },

                onFocusOut: function (event) {
                    var next = event.relatedTarget;

                    if (next && !this.$root.contains(next)) {
                        this.close(false);
                    }
                },

                changeYear: function (delta) {
                    this.focusedYear = Math.min(MAX_YEAR, Math.max(MIN_YEAR, this.focusedYear + delta));
                },

                select: function (year, month) {
                    if (this.isMonthDisabled(year, month)) return;

                    this.setState(year + '-' + pad(month));
                    this.close(true);
                },

                selectCurrentMonth: function () {
                    var now = new Date();

                    this.select(now.getFullYear(), now.getMonth() + 1);
                },

                clear: function () {
                    this.setState(this.clearValue !== undefined ? this.clearValue : null);
                    this.close(true);
                },

                moveFocus: function (deltaMonths) {
                    var target = this.focusedYear * 12 + (this.focusedMonth - 1) + deltaMonths;
                    var year = Math.floor(target / 12);

                    if (year < MIN_YEAR || year > MAX_YEAR) return;

                    this.focusedYear = year;
                    this.focusedMonth = (target % 12) + 1;
                    this.focusMonthButton();
                },

                focusMonthButton: function () {
                    var button = this.$refs.panel.querySelector('[data-month="' + this.focusedMonth + '"]');

                    if (button) button.focus({ preventScroll: true });
                },

                onPanelKeydown: function (event) {
                    if (!event.target.hasAttribute('data-month')) return;

                    var cols = this.columns || 4;
                    var deltas = {
                        ArrowLeft: -1,
                        ArrowRight: 1,
                        ArrowUp: -cols,
                        ArrowDown: cols,
                        PageUp: -12,
                        PageDown: 12,
                        Home: 1 - this.focusedMonth,
                        End: 12 - this.focusedMonth,
                    };

                    if (!(event.key in deltas)) return;

                    event.preventDefault();
                    event.stopPropagation();
                    this.moveFocus(deltas[event.key]);
                },

                /**
                 * Ancora o popup abaixo do campo (ou acima, quando falta espaço) com
                 * `position: fixed`, dentro da viewport. A medição em (0, 0) desconta um
                 * eventual bloco contentor criado por ancestral com transform/contain.
                 */
                position: function () {
                    var panel = this.$refs.panel;
                    var control = this.$refs.control;

                    if (!panel || !control) return;

                    panel.style.top = '0px';
                    panel.style.left = '0px';

                    var origin = panel.getBoundingClientRect();
                    var anchor = control.getBoundingClientRect();
                    var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
                    var viewportHeight = window.innerHeight;
                    var spaceBelow = viewportHeight - anchor.bottom - PANEL_OFFSET - VIEWPORT_GUTTER;
                    var spaceAbove = anchor.top - PANEL_OFFSET - VIEWPORT_GUTTER;
                    var placement = origin.height > spaceBelow && spaceAbove > spaceBelow ? 'top' : 'bottom';
                    var top = placement === 'top'
                        ? anchor.top - PANEL_OFFSET - origin.height
                        : anchor.bottom + PANEL_OFFSET;
                    var left = anchor.left;

                    top = Math.max(VIEWPORT_GUTTER, Math.min(top, viewportHeight - VIEWPORT_GUTTER - origin.height));
                    left = Math.max(VIEWPORT_GUTTER, Math.min(left, viewportWidth - VIEWPORT_GUTTER - origin.width));

                    panel.style.top = (top - origin.top) + 'px';
                    panel.style.left = (left - origin.left) + 'px';
                    panel.setAttribute('data-placement', placement);
                },
            };
        };
    })();
</script>
