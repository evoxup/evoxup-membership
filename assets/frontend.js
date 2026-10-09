(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-evo-copy]');
        if (!btn) return;
        var value = btn.getAttribute('data-evo-copy') || '';
        if (!value) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                var old = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = old; }, 1200);
            });
        }
    });
})();
