/*
 * The scan field (stock.md): a scanner types a code and Enter. Each Enter is queued and POSTed in order (fetch, as HTMX would — the server
 * answers HX-Location), the field is cleared at once so the next scan never waits, and when the queue is empty the page region is refreshed
 * from the last landing and the field focused again. An adjustment's scan stops at the delta: Enter with no change typed moves the focus there.
 * A refusal (422) is shown in #flash in the server's words. With JavaScript off the field is a plain form and posts the same.
 */
(function () {
    'use strict';
    var queue = [], busy = false, land = null;

    function csrf() { var m = document.querySelector('#csrf-token-meta'); return m ? m.content : ''; }
    function flash(html) { var f = document.getElementById('flash'); if (f) { f.innerHTML = html; } }
    function focusScan() {
        var i = document.querySelector('[data-scan-input]');
        if (i && document.activeElement !== i && !document.querySelector('.modal.show')) { i.focus({ preventScroll: true }); }
    }
    function refresh() {
        if (!land || typeof htmx === 'undefined') { focusScan(); return; }
        var path = land; land = null;
        htmx.ajax('GET', path, { target: '#page-content', swap: 'innerHTML' }).then(function () {
            try { history.replaceState(history.state, '', path); } catch (e) { /* no history */ }
            focusScan();
        });
    }
    function next() {
        if (busy) { return; }
        var job = queue.shift();
        if (!job) { refresh(); return; }
        busy = true;
        fetch(job.action, { method: 'POST', body: job.body, credentials: 'same-origin',
                            headers: { 'HX-Request': 'true', 'HX-Current-URL': location.href, 'X-CSRF-Token': csrf() } })
            .then(function (res) {
                var hx = res.headers.get('HX-Location');
                if (hx) { try { land = JSON.parse(hx).path; } catch (e) { land = hx; } }
                return res.text().then(function (text) {
                    if (!res.ok) { flash(text); queue = []; land = land || null; }
                });
            })
            .catch(function () { flash('<div class="alert alert-danger">The scan did not reach the server — scan it again.</div>'); queue = []; })
            .finally(function () { busy = false; next(); });
    }

    document.addEventListener('keydown', function (e) {
        var input = e.target;
        if (e.key !== 'Enter' || !input.matches || !input.matches('[data-scan-input]')) { return; }
        var form = input.closest('form[data-scan-form]');
        if (!form || typeof fetch === 'undefined') { return; }
        e.preventDefault();
        var code = input.value.trim();
        if (code === '') { return; }
        var qty = form.querySelector('[data-scan-qty]');
        if (qty && qty.hasAttribute('data-scan-required') && qty.value.trim() === '') { qty.focus(); return; }   // an adjustment: type the change
        var body = new FormData(form);
        queue.push({ action: form.getAttribute('action'), body: body });
        input.value = '';
        if (qty && !qty.hasAttribute('data-scan-required')) { qty.value = ''; }
        next();
    });
    // the delta of an adjustment: Enter there sends the scan
    document.addEventListener('keydown', function (e) {
        var t = e.target;
        if (e.key !== 'Enter' || !t.matches || !t.matches('[data-scan-qty][data-scan-required]')) { return; }
        var form = t.closest('form[data-scan-form]'), input = form && form.querySelector('[data-scan-input]');
        if (!input || input.value.trim() === '') { return; }
        e.preventDefault();
        queue.push({ action: form.getAttribute('action'), body: new FormData(form) });
        input.value = ''; t.value = '';
        next();
    });
    document.addEventListener('DOMContentLoaded', focusScan);
    document.body && document.body.addEventListener('htmx:afterSettle', function () { if (document.querySelector('[data-scan-input]')) { focusScan(); } });
})();
