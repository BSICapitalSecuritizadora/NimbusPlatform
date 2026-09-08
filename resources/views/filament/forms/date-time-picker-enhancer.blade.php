<script>
    (function () {
        if (window.__bsiDateTimePickerEnhancer) return;
        window.__bsiDateTimePickerEnhancer = true;

        function enhancePanel(panel) {
            if (!panel || !window.Alpine) return;
            var alpine = window.Alpine.$data(panel);
            if (!alpine) return;

            // 1. Header Navigation Buttons (‹ and ›)
            var header = panel.querySelector('.fi-fo-date-time-picker-panel-header');
            if (header && !header.querySelector('.fi-fo-date-time-picker-header-nav')) {
                var nav = document.createElement('div');
                nav.className = 'fi-fo-date-time-picker-header-nav';
                nav.innerHTML = '' +
                    '<button type="button" class="fi-fo-date-time-picker-nav-btn fi-fo-date-time-picker-nav-btn-prev" aria-label="Mês anterior" title="Mês anterior">' +
                    '    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>' +
                    '</button>' +
                    '<button type="button" class="fi-fo-date-time-picker-nav-btn fi-fo-date-time-picker-nav-btn-next" aria-label="Próximo mês" title="Próximo mês">' +
                    '    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>' +
                    '</button>';
                header.appendChild(nav);

                nav.querySelector('.fi-fo-date-time-picker-nav-btn-prev').addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var d = window.Alpine.$data(panel);
                    if (!d) return;
                    var m = Number(d.focusedMonth);
                    var y = Number(d.focusedYear);
                    if (m === 0) {
                        d.focusedMonth = 11;
                        d.focusedYear = y - 1;
                    } else {
                        d.focusedMonth = m - 1;
                    }
                });

                nav.querySelector('.fi-fo-date-time-picker-nav-btn-next').addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var d = window.Alpine.$data(panel);
                    if (!d) return;
                    var m = Number(d.focusedMonth);
                    var y = Number(d.focusedYear);
                    if (m === 11) {
                        d.focusedMonth = 0;
                        d.focusedYear = y + 1;
                    } else {
                        d.focusedMonth = m + 1;
                    }
                });
            }

            // 2. Footer Actions ("Limpar" and "Hoje")
            if (!panel.querySelector('.fi-fo-date-time-picker-panel-footer')) {
                var footer = document.createElement('div');
                footer.className = 'fi-fo-date-time-picker-panel-footer';
                footer.innerHTML = '' +
                    '<button type="button" class="fi-fo-date-time-picker-footer-btn fi-fo-date-time-picker-footer-btn-clear">Limpar</button>' +
                    '<button type="button" class="fi-fo-date-time-picker-footer-btn fi-fo-date-time-picker-footer-btn-today">Hoje</button>';
                panel.appendChild(footer);

                footer.querySelector('.fi-fo-date-time-picker-footer-btn-clear').addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var d = window.Alpine.$data(panel);
                    if (d && typeof d.clearState === 'function') {
                        d.clearState();
                    }
                });

                footer.querySelector('.fi-fo-date-time-picker-footer-btn-today').addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var d = window.Alpine.$data(panel);
                    if (d && typeof d.selectDate === 'function') {
                        var now = window.dayjs ? window.dayjs() : new Date();
                        var day = typeof now.date === 'function' ? now.date() : now.getDate();
                        var month = typeof now.month === 'function' ? now.month() : now.getMonth();
                        var year = typeof now.year === 'function' ? now.year() : now.getFullYear();
                        d.focusedDate = now;
                        d.focusedMonth = month;
                        d.focusedYear = year;
                        d.selectDate(day);
                    }
                });
            }
        }

        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type === 'childList') {
                    for (var j = 0; j < m.addedNodes.length; j++) {
                        var node = m.addedNodes[j];
                        if (node.nodeType === 1) {
                            if (node.classList && node.classList.contains('fi-fo-date-time-picker-panel')) {
                                enhancePanel(node);
                            } else if (node.querySelectorAll) {
                                var panels = node.querySelectorAll('.fi-fo-date-time-picker-panel');
                                for (var k = 0; k < panels.length; k++) {
                                    enhancePanel(panels[k]);
                                }
                            }
                        }
                    }
                } else if (m.type === 'attributes' && m.attributeName === 'style') {
                    if (m.target && m.target.classList && m.target.classList.contains('fi-fo-date-time-picker-panel')) {
                        if (m.target.style.display !== 'none') {
                            enhancePanel(m.target);
                        }
                    }
                }
            }
        });

        var initObserver = function () {
            if (document.body) {
                observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] });
                var panels = document.querySelectorAll('.fi-fo-date-time-picker-panel');
                for (var i = 0; i < panels.length; i++) {
                    enhancePanel(panels[i]);
                }
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initObserver);
        } else {
            initObserver();
        }
    })();
</script>
