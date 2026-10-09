/* series-chart.js — the hover and keyboard of shared/series-chart.php (catalog.md "The chart component"): a crosshair at the nearest
   point by x across the plot's whole width, a tooltip (the date, each series' value, the reason when the point carries one) kept inside
   the figure; ← → move the crosshair when the figure has focus. Bound once per figure; re-bound on htmx:afterSwap. */
(function () {
    'use strict';
    function bind(fig) {
        if (fig.dataset.vizBound) { return; }
        fig.dataset.vizBound = '1';
        var series; try { series = JSON.parse(fig.dataset.vizSeries || '[]'); } catch (e) { return; }
        var plot = (fig.dataset.vizPlot || '60,20,560,160').split(',').map(Number);
        var svg = fig.querySelector('svg'), hit = fig.querySelector('.viz-hit'), cross = fig.querySelector('.viz-crosshair'), tip = fig.querySelector('.viz-tooltip');
        if (!svg || !hit || !cross || !tip) { return; }
        var xs = [];
        series.forEach(function (s) { s.points.forEach(function (p) { if (xs.indexOf(p.x) < 0) { xs.push(p.x); } }); });
        xs.sort(function (a, b) { return a - b; });
        if (!xs.length) { return; }
        var idx = xs.length - 1;
        function valueAt(s, x) { var v = null; s.points.forEach(function (p) { if (p.x <= x + 0.01) { v = p; } }); return v; }
        function show(i) {
            idx = Math.max(0, Math.min(xs.length - 1, i));
            var x = xs[idx];
            cross.setAttribute('x1', x); cross.setAttribute('x2', x); cross.removeAttribute('hidden');
            var when = null, lines = [];
            series.forEach(function (s) {
                var p = valueAt(s, x); if (!p) { return; }
                if (p.x === x) { when = p.t; }
                lines.push('<div><span class="viz-swatch viz-swatch-' + (series.indexOf(s) + 1) + ' me-1"></span>' + s.label + ': <strong>' + p.v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (fig.dataset.vizUnit ? ' ' + fig.dataset.vizUnit : '') + '</strong>' + (p.x === x && p.reason ? ' <span class="text-muted">· ' + String(p.reason).replace(/[<>&]/g, '') + '</span>' : '') + '</div>');
            });
            if (when === null) { series.forEach(function (s) { s.points.forEach(function (p) { if (p.x === x) { when = p.t; } }); }); }
            tip.innerHTML = '<div class="fw-semibold">' + (when ? new Date(when * 1000).toLocaleDateString() : '') + '</div>' + lines.join('');
            tip.removeAttribute('hidden');
            var r = fig.getBoundingClientRect(), sr = svg.getBoundingClientRect();
            var px = sr.left - r.left + (x / 640) * sr.width, py = sr.top - r.top + (plot[1] / 240) * sr.height;
            var tw = tip.offsetWidth, left = px + 10; if (left + tw > r.width) { left = px - tw - 10; } if (left < 0) { left = 0; }
            tip.style.left = left + 'px'; tip.style.top = Math.max(0, py) + 'px';
        }
        function hide() { cross.setAttribute('hidden', ''); tip.setAttribute('hidden', ''); }
        hit.addEventListener('mousemove', function (e) {
            var sr = svg.getBoundingClientRect(); var x = (e.clientX - sr.left) / sr.width * 640;
            var best = 0; xs.forEach(function (v, i) { if (Math.abs(v - x) < Math.abs(xs[best] - x)) { best = i; } });
            show(best);
        });
        hit.addEventListener('mouseleave', hide);
        fig.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { e.preventDefault(); show(cross.hasAttribute('hidden') ? xs.length - 1 : idx - 1); }
            else if (e.key === 'ArrowRight') { e.preventDefault(); show(cross.hasAttribute('hidden') ? 0 : idx + 1); }
            else if (e.key === 'Escape') { hide(); }
        });
        fig.addEventListener('blur', hide);
    }
    function bindAll() { document.querySelectorAll('.viz-root[data-viz-series]').forEach(bind); }
    bindAll();
    document.body.addEventListener('htmx:afterSwap', bindAll);
})();
