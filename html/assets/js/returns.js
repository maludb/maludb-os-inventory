/*
 * Receiving a return (returns-worker.md "return-receive"): the scan field finds a line's card by the variant's barcode or SKU and puts the cursor in its quantity; a code that is on no line says so; the steppers move the
 * quantity within its bounds. Without JavaScript the quantities are plain number fields and the form posts as it stands. Enter in the scan field never submits the form.
 */
(function () {
    'use strict';
    function norm(s) { return String(s || '').trim().toLowerCase(); }
    document.addEventListener('keydown', function (e) {
        var t = e.target;
        if (e.key !== 'Enter' || !t.matches || !t.matches('[data-return-scan]')) { return; }
        e.preventDefault();
        var code = norm(t.value), miss = document.getElementById('return-receive-scan-miss'), hit = null;
        if (code === '') { return; }
        document.querySelectorAll('[data-return-line]').forEach(function (c) {
            c.classList.remove('border-primary');
            if (!hit && (norm(c.getAttribute('data-barcode')) === code || norm(c.getAttribute('data-sku')) === code)) { hit = c; }
        });
        t.value = '';
        if (!hit) { if (miss) { miss.hidden = false; } return; }
        if (miss) { miss.hidden = true; }
        hit.classList.add('border-primary');
        var q = hit.querySelector('input[type=number]');
        hit.scrollIntoView({ block: 'center' });
        if (q) { q.focus(); q.select(); }
    });
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-step]') : null;
        if (!b) { return; }
        var q = b.parentNode.querySelector('input[type=number]');
        if (!q) { return; }
        var v = (parseInt(q.value, 10) || 0) + parseInt(b.getAttribute('data-step'), 10);
        var max = q.max === '' ? v : parseInt(q.max, 10), min = q.min === '' ? 0 : parseInt(q.min, 10);
        q.value = Math.max(min, Math.min(max, v));
        q.dispatchEvent(new Event('change', { bubbles: true }));
    });
})();
