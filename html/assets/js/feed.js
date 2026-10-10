/*
 * The feed keys' few lines of script (feed.md): the price-list row of the mint form shows only for a partner store's key (without JavaScript the row is always there and the database's CHECK says the rest), and Copy puts the
 * once-shown key on the clipboard. Nothing else is worked out here.
 */
(function () {
    'use strict';
    function syncPriceRow() {
        var kind = document.getElementById('feed-key-form-field-consumer_kind'), row = document.getElementById('feed-key-form-row-price_list');
        if (!kind || !row) { return; }
        var partner = kind.value === 'partner';
        row.classList.toggle('d-none', !partner);
        var sel = document.getElementById('feed-key-form-field-price_list');
        if (sel) { sel.required = partner; if (!partner) { sel.value = ''; } }
    }
    document.addEventListener('change', function (ev) {
        if (ev.target && ev.target.id === 'feed-key-form-field-consumer_kind') { syncPriceRow(); }
    });
    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('[data-copy-from]') : null;
        if (!btn) { return; }
        var src = document.getElementById(btn.getAttribute('data-copy-from'));
        if (!src) { return; }
        src.focus(); src.select();
        var done = function () { btn.innerHTML = '<i class="feather-check me-1"></i>Copied'; };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(src.value).then(done, function () { try { document.execCommand('copy'); done(); } catch (e) {} }); }
        else { try { document.execCommand('copy'); done(); } catch (e) {} }
    });
    document.addEventListener('htmx:afterSettle', syncPriceRow);
    syncPriceRow();
})();
