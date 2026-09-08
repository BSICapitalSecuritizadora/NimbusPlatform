<script>
    (function () {
        if (window.__bsiSelectRepositionWatch) return;
        window.__bsiSelectRepositionWatch = true;

        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type === 'childList' && m.addedNodes.length > 0) {
                    for (var j = 0; j < m.addedNodes.length; j++) {
                        var node = m.addedNodes[j];
                        if (node.nodeType === 1 && (
                            (node.matches && (node.matches('.fi-dropdown-list') || node.matches('.fi-select-input-option'))) ||
                            (node.querySelector && node.querySelector('.fi-select-input-option'))
                        )) {
                            var dropdown = node.closest('.fi-dropdown-panel') || (node.classList && node.classList.contains('fi-dropdown-panel') ? node : null);
                            if (dropdown) {
                                var selectCtn = dropdown.closest('.fi-select-input-ctn');
                                var alpineEl = selectCtn ? selectCtn.closest('[x-ref="select"]') : null;
                                var alpineData = window.Alpine && alpineEl ? window.Alpine.$data(alpineEl) : null;
                                if (alpineData && alpineData.select) {
                                    alpineData.select.deferPositionDropdown();
                                } else {
                                    window.dispatchEvent(new Event('resize'));
                                }
                            }
                        }
                    }
                }
            }
        });

        var startObserving = function () {
            if (document.body) {
                observer.observe(document.body, { childList: true, subtree: true });
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', startObserving);
        } else {
            startObserving();
        }
    })();
</script>
