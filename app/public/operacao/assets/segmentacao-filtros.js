(function () {
    'use strict';

    document.querySelectorAll('[data-filter-card]').forEach(function (card) {
        var toggle = card.querySelector('[data-filter-toggle]');
        if (!toggle) return;

        function sync() {
            card.classList.toggle('is-enabled', toggle.checked);
            card.querySelectorAll('[data-filter-field] input, [data-filter-field] select, [data-filter-field] textarea').forEach(function (field) {
                field.disabled = !toggle.checked;
            });
        }

        toggle.addEventListener('change', sync);
        sync();
    });
})();
