<script>
    (function () {
        if (window.__bsiZeroWatch) return;
        window.__bsiZeroWatch = true;

        var isZero = function (value) {
            return /^-?0([.,]0+)?$/.test((value || '').trim());
        };

        var sweep = function () {
            document.querySelectorAll('.bsi-receivable-form-page input.bsi-num').forEach(function (el) {
                el.classList.toggle('bsi-zero', isZero(el.value));
            });
        };

        document.addEventListener('input', function (event) {
            var target = event.target;

            if (target.matches && target.matches('input.bsi-num')) {
                target.classList.toggle('bsi-zero', isZero(target.value));
            }
        });

        document.addEventListener('focusout', sweep);
        document.addEventListener('livewire:navigated', sweep);

        var frame = null;

        new MutationObserver(function () {
            if (frame !== null) return;

            frame = requestAnimationFrame(function () {
                frame = null;
                sweep();
            });
        }).observe(document.body, { childList: true, subtree: true });

        sweep();
    })();
</script>
