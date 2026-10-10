/*
 * The purchase-order form's few lines of script (purchasing.md "The purchase-order form"): a variant picked from the search list fills its line (the label) and loads the supplier's price sheet entry, cost and offers for it
 * (/purchasing/line-defaults — the cost box takes the default, the supplier SKU box shows theirs as a hint, a quantity of 1 takes the minimum order); "+ Add line" clones a blank row from the template; Remove drops a row. The
 * server's figures are the only figures — nothing is worked out here. With JavaScript off the form still posts: the SKU box and the server's defaults do the same job (the pick list is the only thing that needs this file).
 */
(function () {
    'use strict';
    function supplierId() {
        var s = document.getElementById('po-form-field-supplier');
        return (s && s.value) || '';
    }
    function loadDefaults(row) {
        var n = row.dataset.lineN, box = document.getElementById('po-line-' + n + '-offerbox'), v = row.querySelector('#po-line-' + n + '-variant');
        if (!box || !v || !v.value || typeof htmx === 'undefined') { return; }
        var sup = supplierId();
        if (!sup) { box.innerHTML = '<div class="fs-12 text-warning">Choose a supplier first — the cost and offers are theirs.</div>'; return; }
        htmx.ajax('GET', '/purchasing/line-defaults?supplier=' + encodeURIComponent(sup) + '&variant=' + encodeURIComponent(v.value) + '&n=' + encodeURIComponent(n) + '&field=' + encodeURIComponent(box.dataset.field || ''), { target: box, swap: 'innerHTML' });
    }
    function fillFromDefaults(box) {
        var d = box.querySelector('[data-default-cost]'), row = box.closest('.po-line-row');
        if (!d || !row) { return; }
        var n = row.dataset.lineN;
        var cost = row.querySelector('#po-line-' + n + '-cost'); if (cost) { cost.value = d.dataset.defaultCost || ''; }
        var sku = row.querySelector('#po-line-' + n + '-sku'); if (sku && d.dataset.defaultSku) { sku.placeholder = d.dataset.defaultSku; }
        var qty = row.querySelector('#po-line-' + n + '-qty'), moq = parseInt(d.dataset.moq || '0', 10);
        if (qty && moq > 1 && parseInt(qty.value || '1', 10) < moq) { qty.value = String(moq); }
    }
    function choosePick(btn) {
        var row = btn.closest('.po-line-row');
        if (!row) { return; }
        var n = row.dataset.lineN;
        row.querySelector('#po-line-' + n + '-variant').value = btn.dataset.variantId;
        var label = row.querySelector('#po-line-' + n + '-label');
        if (label) { label.textContent = btn.dataset.label ? btn.dataset.sku + ' — ' + btn.dataset.label : btn.dataset.sku; }
        var code = row.querySelector('#po-line-' + n + '-code'); if (code) { code.value = ''; }
        var s = row.querySelector('#po-line-' + n + '-search'); if (s) { s.value = ''; }
        var list = row.querySelector('#po-line-' + n + '-pick'); if (list) { list.innerHTML = ''; }
        loadDefaults(row);
    }
    function addLine() {
        var host = document.getElementById('po-lines'), t = document.getElementById('po-line-template');
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
        var row = btn.closest('.po-line-row'), host = document.getElementById('po-lines');
        if (!row || !host) { return; }
        row.remove();
        if (!host.querySelector('.po-line-row')) { addLine(); }
    }
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        var pick = t.closest('[id^="po-line-"][id$="-pick"] button[data-variant-id]');
        if (pick) { e.preventDefault(); choosePick(pick); return; }
        if (t.closest('[data-add-line]')) { e.preventDefault(); addLine(); return; }
        var rm = t.closest('[data-remove-line]');
        if (rm) { e.preventDefault(); removeLine(rm); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target && e.target.matches && e.target.matches('[id^="po-line-"][id$="-search"]')) { e.preventDefault(); }
    });
    document.body.addEventListener('htmx:afterSwap', function (e) {
        var t = e.detail && e.detail.target;
        if (t && t.id && /^po-line-.+-offerbox$/.test(t.id)) { fillFromDefaults(t); }
    });
})();
