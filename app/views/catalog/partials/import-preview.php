<?php /** Step 2 of the import: the first five rows under their headers, one select per mapping field, Update existing, Import. Data: preview (columns, rows, upload), fields, guess */
$cols = $preview['columns'];
$letter = static fn (int $i): string => chr(65 + ($i % 26)) . ($i >= 26 ? (string) intdiv($i, 26) : '');
?>
<div class="card mb-3" id="import-preview"><div class="card-header"><h5 class="card-title mb-0">Step 2 — the first rows</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="import-preview-table">
    <thead class="thead-light"><tr><?php foreach ($cols as $i => $c): ?><th><span class="text-muted"><?= e($letter($i)) ?></span> <?= e($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach (array_slice($preview['rows'], 0, 5) as $r): ?><tr><?php foreach ($r as $cell): ?><td><?= e(mb_substr((string) $cell, 0, 40)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
</table></div></div><div class="card-footer fs-12 text-muted"><?= count($preview['rows']) ?> row<?= count($preview['rows']) === 1 ? '' : 's' ?> in the file.</div></div>
<form method="post" action="/products/import.php" class="card" id="import-mapping-form">
    <?= csrf_field() ?><input type="hidden" name="upload" value="<?= e($preview['upload']) ?>">
    <div class="card-header"><h5 class="card-title mb-0">The mapping</h5></div>
    <div class="card-body row g-2">
        <?php foreach ($fields as $k => $label): ?>
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="import-mapping-<?= e($k) ?>"><?= e($label) ?></label>
            <select name="mapping[<?= e($k) ?>]" id="import-mapping-<?= e($k) ?>" class="form-select btn-touch" <?= in_array($k, ['name', 'sku'], true) ? 'required' : '' ?>><option value="">— not in the file —</option><?php foreach ($cols as $i => $c): ?><option value="<?= $i ?>" <?= $guess($k, $cols) === (string) $i ? 'selected' : '' ?>><?= e($letter($i)) ?> · <?= e($c) ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <div class="col-12"><input type="hidden" name="update_existing" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="import-mapping-update_existing"><input type="checkbox" class="form-check-input mt-0" name="update_existing" value="yes" id="import-mapping-update_existing">Update existing variants (matched by SKU or GTIN) — otherwise they are skipped</label></div>
    </div>
    <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="import-mapping-run-btn">Import</button><?= hx_link('/catalog/import', 'Start over', 'btn btn-light btn-touch') ?></div>
</form>
