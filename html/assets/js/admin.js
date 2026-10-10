/*
 * The settings' few lines of script (reports-admin.md): the sizes and attribute keys are JSON in a textarea; as the person types, the read-only table beside it is drawn again from what they have written, so a typo shows before Save.
 * Nothing is validated here — the server says what is wrong. Without JavaScript the table shows the saved list.
 */
(function () {
    'use strict';
    function cell(tr, text, cls) { var td = document.createElement('td'); td.textContent = text; if (cls) { td.className = cls; } tr.appendChild(td); return td; }
    function draw(textarea) {
        var table = document.getElementById(textarea.getAttribute('data-render'));
        if (!table) { return; }
        var body = table.tBodies[0], data;
        try { data = JSON.parse(textarea.value); } catch (e) { table.classList.add('opacity-50'); return; }
        if (!Array.isArray(data)) { table.classList.add('opacity-50'); return; }
        table.classList.remove('opacity-50');
        var counts = {};
        Array.prototype.forEach.call(body.rows, function (r) { if (r.cells.length === 4) { counts[r.cells[0].textContent] = r.cells[3].textContent; } });
        while (body.firstChild) { body.removeChild(body.firstChild); }
        data.forEach(function (row) {
            if (!row || typeof row !== 'object') { return; }
            var tr = document.createElement('tr');
            var key = cell(tr, String(row.key == null ? '' : row.key)); key.className = 'font-monospace';
            cell(tr, String(row.name == null ? '' : row.name));
            if (table.id === 'admin-settings-sizes-table') {
                cell(tr, (row.synonyms || []).join(', '), 'text-muted');
                cell(tr, counts[String(row.key)] || '0', 'text-end');
            } else {
                cell(tr, String(row.kind == null ? '' : row.kind));
                cell(tr, (row.choices || []).join(', '), 'text-muted');
            }
            body.appendChild(tr);
        });
    }
    document.addEventListener('input', function (ev) { if (ev.target && ev.target.matches && ev.target.matches('textarea[data-render]')) { draw(ev.target); } });
})();
