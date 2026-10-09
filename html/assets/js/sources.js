/*
 * The sources screens (sources.md "Files"): the connector sub-form switch (every sub-form is rendered by the server; this shows the chosen one
 * and disables the others' inputs so nothing of them is posted twice — with JavaScript off they all show, the chosen one marked), the feed's
 * transport toggle, the manual editor's rows (add, remove, renumbered), the credential form's fields by kind. Delegated events; the state is
 * applied again after every HTMX swap.
 */
(function () {
    'use strict';
    function apply(root) {
        root = root || document;
        root.querySelectorAll('form[data-source-form]').forEach(function (form) {
            var sel = form.querySelector('[data-connector-select]');
            if (!sel) { return; }
            var chosen = sel.value;
            form.querySelectorAll('.source-sub').forEach(function (fs) {
                var on = fs.getAttribute('data-connector') === chosen;
                fs.hidden = !on;
                fs.disabled = !on;
            });
            var base = form.querySelector('[data-needs-base]');
            if (base) { base.hidden = chosen === 'feed' || chosen === 'manual'; }
            var note = form.querySelector('#source-form-connector-note');
            if (note && sel.tagName === 'SELECT') { note.textContent = sel.options[sel.selectedIndex].title || note.textContent; }
            var feed = form.querySelector('#source-form-feed');
            if (feed) {
                var sftp = feed.querySelector('input[name="settings_feed[transport]"][value="sftp"]');
                var isSftp = sftp && sftp.checked;
                feed.querySelectorAll('[id^="source-form-field-feed-sftp_"]').forEach(function (i) { i.closest('.col-12, .col-md-6, [class*="col-"]').hidden = !isSftp; });
                var url = feed.querySelector('#source-form-field-feed-url');
                if (url) { url.closest('[class*="col-"]').hidden = isSftp; }
            }
        });
        root.querySelectorAll('#source-credential-form').forEach(function (form) {
            var kind = form.querySelector('[data-credential-kind]');
            if (!kind) { return; }
            form.querySelectorAll('.credential-field').forEach(function (f) {
                var on = (' ' + f.getAttribute('data-kinds') + ' ').indexOf(' ' + kind.value + ' ') >= 0;
                f.hidden = !on;
                f.querySelectorAll('input, textarea').forEach(function (i) { i.disabled = !on; });
            });
        });
    }
    function renumber(editor) {
        editor.querySelectorAll('[data-manual-row]').forEach(function (row, n) {
            row.id = 'manual-row-' + n;
            row.querySelectorAll('[name^="settings_manual[listings]"]').forEach(function (i) {
                i.name = i.name.replace(/settings_manual\[listings\]\[\d+\]/, 'settings_manual[listings][' + n + ']');
                i.id = i.id.replace(/^manual-row-\d+-/, 'manual-row-' + n + '-');
            });
            row.querySelectorAll('label[for^="manual-row-"]').forEach(function (l) { l.htmlFor = l.htmlFor.replace(/^manual-row-\d+-/, 'manual-row-' + n + '-'); });
            var rm = row.querySelector('[data-manual-remove]');
            if (rm) { rm.id = 'manual-row-' + n + '-remove'; }
        });
    }
    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-connector-select], input[name="settings_feed[transport]"], [data-credential-kind]')) { apply(document); }
    });
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-manual-add]');
        if (add) {
            var editor = add.closest('#manual-editor');
            var tpl = editor.querySelector('#manual-row-template');
            var rows = editor.querySelector('#manual-rows');
            rows.appendChild(tpl.content.firstElementChild.cloneNode(true));
            renumber(editor);
            var first = rows.lastElementChild.querySelector('input');
            if (first) { first.focus(); }
            return;
        }
        var rm = e.target.closest('[data-manual-remove]');
        if (rm) {
            var ed = rm.closest('#manual-editor');
            var all = ed.querySelectorAll('[data-manual-row]');
            if (all.length > 1) { rm.closest('[data-manual-row]').remove(); } else { rm.closest('[data-manual-row]').querySelectorAll('input, select').forEach(function (i) { i.value = ''; }); }
            renumber(ed);
        }
    });
    document.addEventListener('DOMContentLoaded', function () { apply(document); });
    if (document.body) { document.body.addEventListener('htmx:afterSettle', function () { apply(document); }); }
    apply(document);
})();
