document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-evo-copy]');
    if (!button) return;
    var value = button.getAttribute('data-evo-copy') || '';
    if (!value) return;
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(value).then(function () {
        var old = button.textContent;
        button.textContent = 'Copied ✓';
        button.classList.add('button-primary');
        setTimeout(function () { button.textContent = old; button.classList.remove('button-primary'); }, 1500);
    });
});


(function () {
    function updateEvoValidityField() {
        var select = document.getElementById('_evo_license_validity');
        var row = document.querySelector('.evo-license-days-row');
        var input = document.getElementById('_evo_license_days');
        if (!select || !row || !input) return;

        var limited = select.value === 'limited';
        row.classList.toggle('evo-hidden', !limited);
        input.disabled = !limited;

        if (limited && (!input.value || parseInt(input.value, 10) < 1)) {
            input.value = '365';
        }
    }

    document.addEventListener('DOMContentLoaded', updateEvoValidityField);
    document.addEventListener('change', function (event) {
        if (event.target && event.target.id === '_evo_license_validity') {
            updateEvoValidityField();
        }
    });
})();

(function () {
    function toggleProductLicenseFields() {
        var check = document.getElementById('evo-requires-license');
        var box = document.getElementById('evo-product-license-fields');
        if (!check || !box) return;
        box.classList.toggle('evo-hidden', !check.checked);
        box.querySelectorAll('input,select,textarea').forEach(function (el) { el.disabled = !check.checked; });
    }
    function toggleRouteTargets() {
        var select = document.querySelector('select[name="target_type"]');
        if (!select) return;
        document.querySelectorAll('.evo-route-target').forEach(function (el) { el.classList.add('evo-hidden'); });
        var target = document.querySelector(select.value === 'woocommerce' ? '.evo-route-wc' : '.evo-route-evo');
        if (target) target.classList.remove('evo-hidden');
    }
    function toggleRouteLicenseFields() {
        var check = document.getElementById('evo-route-license');
        var box = document.getElementById('evo-route-license-fields');
        if (!check || !box) return;
        box.classList.toggle('evo-hidden', !check.checked);
        box.querySelectorAll('input,select,textarea').forEach(function (el) { el.disabled = !check.checked; });
    }
    function bootEvoAdminForms() {
        toggleProductLicenseFields();
        toggleRouteTargets();
        toggleRouteLicenseFields();
    }
    document.addEventListener('DOMContentLoaded', bootEvoAdminForms);
    document.addEventListener('change', function (event) {
        if (!event.target) return;
        if (event.target.id === 'evo-requires-license') toggleProductLicenseFields();
        if (event.target.name === 'target_type') toggleRouteTargets();
        if (event.target.id === 'evo-route-license') toggleRouteLicenseFields();
    });
})();

(function () {
    function syncApiMode() {
        var checked = document.querySelector('input[name="api_mode"]:checked');
        var mode = checked ? checked.value : 'internal';
        document.querySelectorAll('[data-evo-api-mode]').forEach(function (el) {
            var allowed = (el.getAttribute('data-evo-api-mode') || '').split(',').map(function (value) { return value.trim(); }).filter(Boolean);
            var visible = allowed.indexOf(mode) !== -1;
            el.classList.toggle('evo-hidden', !visible);
            el.querySelectorAll('input,select,textarea').forEach(function (field) {
                field.disabled = !visible;
            });
        });
        document.querySelectorAll('.evo-api-mode-card').forEach(function (card) {
            var input = card.querySelector('input[name="api_mode"]');
            card.classList.toggle('is-selected', !!input && input.checked);
        });
    }
    document.addEventListener('DOMContentLoaded', syncApiMode);
    document.addEventListener('change', function (event) {
        if (event.target && event.target.name === 'api_mode') syncApiMode();
    });
})();


(function () {
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-evo-media-select]');
        if (!button || !window.wp || !wp.media) return;

        event.preventDefault();
        var targetSelector = button.getAttribute('data-target') || '#evo-product-image-id';
        var target = document.querySelector(targetSelector);
        if (!target) return;

        var frame = wp.media({
            title: 'Select product image',
            button: { text: 'Use this image' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            target.value = attachment.id || 0;
            target.dispatchEvent(new Event('change', { bubbles: true }));
        });

        frame.open();
    });
})();

// Stable 1.1.3 license selection helpers.
document.addEventListener('change', function (event) {
    var target = event.target;
    if (!target || !target.matches('[data-evo-license-select-all]')) {
        return;
    }
    document.querySelectorAll('[data-evo-license-select]').forEach(function (checkbox) {
        checkbox.checked = target.checked;
    });
});

document.addEventListener('change', function (event) {
    var target = event.target;
    if (!target || !target.matches('[data-evo-license-select]')) {
        return;
    }
    var all = Array.prototype.slice.call(document.querySelectorAll('[data-evo-license-select]'));
    var selectAll = document.querySelector('[data-evo-license-select-all]');
    if (selectAll && all.length) {
        selectAll.checked = all.every(function (checkbox) { return checkbox.checked; });
        selectAll.indeterminate = !selectAll.checked && all.some(function (checkbox) { return checkbox.checked; });
    }
});


// Evoxup RBAC: when an administrator chooses one of the built-in Evoxup roles,
// load its default capability preset into the granular permission checkboxes.
// "Keep current role" does not alter the current checkbox state.
document.addEventListener('change', function (event) {
    var select = event.target;
    if (!select || select.name !== 'base_role') {
        return;
    }
    var option = select.options[select.selectedIndex];
    if (!option || !select.value || !option.hasAttribute('data-evox-capabilities')) {
        return;
    }
    var caps = (option.getAttribute('data-evox-capabilities') || '').split(',').filter(Boolean);
    document.querySelectorAll('input[name="capabilities[]"]').forEach(function (box) {
        box.checked = caps.indexOf(box.value) !== -1;
    });
});
