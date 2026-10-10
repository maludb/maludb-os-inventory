/*
 * The order form's few lines of script (orders.md "The order form"): a variant picked from the search list fills its line (the label, the retail as the price's placeholder) and loads the line's
 * availability picker (slice 4's compact partial); a change of quantity reloads the picker and its promise line, keeping the radio that is checked; "+ Add line" clones a blank row from the template;
 * Remove drops a row; a pickup hides the ship-to block. The server's figures are the only figures — nothing is worked out here. With JavaScript off the form still posts: the SKU box, the
 * recommended fulfilment and the page's own links do the same job (the pick list is the only thing that needs this file).
 */
(function () {
    'use strict';
    var timers = {};

    function box(row) { return row ? row.querySelector('[id$="-fulfilment"]') : null; }
    function variantOf(row) {
        var h = row.querySelector('input[type="hidden"][id$="-variant"]'), b = box(row);
        return (h && h.value) || (b && b.dataset.variant) || '';
    }
    function load(row) {
        var b = box(row), v = variantOf(row);
        if (!b || !v || typeof htmx === 'undefined') { return; }
        var q = row.querySelector('[data-line-qty]'), qty = Math.max(1, parseInt(q && q.value, 10) || 1);
        var cur = b.querySelector('input[type="radio"]:checked'), choose = cur && b.dataset.variant === v ? cur.value : (b.dataset.choose || '');
        b.dataset.variant = v;
        var url = '/find/availability?variant=' + encodeURIComponent(v) + '&compact=1&qty=' + qty + '&field=' + encodeURIComponent(b.dataset.field || 'line') + (choose ? '&choose=' + encodeURIComponent(choose) : '');
        htmx.ajax('GET', url, { target: b, swap: 'innerHTML' });
    }
    function choosePick(btn) {
        var row = btn.closest('.order-line-row');
        if (!row) { return; }
        var n = row.dataset.lineN;
        row.querySelector('#order-line-' + n + '-variant').value = btn.dataset.variantId;
        var label = row.querySelector('#order-line-' + n + '-label');
        if (label) { label.textContent = btn.dataset.label ? btn.dataset.sku + ' — ' + btn.dataset.label : btn.dataset.sku; }
        var price = row.querySelector('#order-line-' + n + '-price');
        if (price && btn.dataset.retail) { price.placeholder = Number(btn.dataset.retail).toFixed(2); }
        var code = row.querySelector('#order-line-' + n + '-code'); if (code) { code.value = ''; }
        var s = row.querySelector('#order-line-' + n + '-search'); if (s) { s.value = ''; }
        var list = row.querySelector('#order-line-' + n + '-pick'); if (list) { list.innerHTML = ''; }
        var b = box(row); if (b) { b.dataset.choose = ''; b.dataset.variant = ''; }
        load(row);
    }
    function addLine() {
        var host = document.getElementById('order-lines'), t = document.getElementById('order-line-template');
        if (!host || !t) { return; }
        var n = parseInt(host.dataset.next || '1', 10);
        host.dataset.next = String(n + 1);
        var wrap = document.createElement('div');
        wrap.innerHTML = t.innerHTML.split('__N__').join(String(n));
        var el = wrap.firstElementChild;
        host.appendChild(el);
        if (typeof htmx !== 'undefined') { htmx.process(el); }
        var s = el.querySelector('[id$="-search"]'); if (s) { s.focus({ preventScroll: false }); }
    }
    function removeLine(btn) {
        var row = btn.closest('.order-line-row'), host = document.getElementById('order-lines');
        if (!row || !host) { return; }
        row.remove();
        if (!host.querySelector('.order-line-row')) { addLine(); }
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        var pick = t.closest('[id^="order-line-"][id$="-pick"] button[data-variant-id]');
        if (pick) { e.preventDefault(); choosePick(pick); return; }
        if (t.closest('[data-add-line]')) { e.preventDefault(); addLine(); return; }
        var rm = t.closest('[data-remove-line]');
        if (rm) { e.preventDefault(); removeLine(rm); }
    });
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.matches || !t.matches('[data-line-qty]')) { return; }
        var row = t.closest('.order-line-row') || t.closest('[id^="order-line-"]');
        if (!row) { return; }
        clearTimeout(timers[row.id]);
        timers[row.id] = setTimeout(function () { load(row); }, 300);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target && e.target.matches && e.target.matches('[id^="order-line-"][id$="-search"]')) { e.preventDefault(); }
    });
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('[data-delivery-method]')) {
            var b = document.getElementById('order-form-ship-to-box');
            if (b) { b.hidden = t.value === 'pickup'; }
        }
    });
})();
