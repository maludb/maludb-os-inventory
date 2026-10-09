<?php /** The feed's column mapping (`feed-mapping`): one field per mapping field — a header from the file, a column number or a letter. Data: columns, mapping, missing, errors? */
$missingBy = [];
foreach ($missing ?? [] as $m) { $missingBy[trim((string) strtok((string) $m, ' '))] = $m; }
$errors = $errors ?? [];
?>
<div id="feed-mapping" class="mt-2">
    <div class="fs-12 text-muted mb-2">Map each field to a column: choose a header from the file, or type a column number (0 = the first) or a letter (A). <strong>supplier_sku</strong> or <strong>gtin</strong> — one of these is required.</div>
    <datalist id="feed-mapping-columns"><?php foreach ($columns as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?></datalist>
    <div class="row g-2">
        <?php foreach (InvConnectorFeed::FIELDS as $field): $err = $errors['feed-mapping-' . $field] ?? ($missingBy[$field] ?? null); ?>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label fs-12 text-muted mb-0" for="feed-mapping-<?= e($field) ?>"><?= e($field) ?><?= in_array($field, ['supplier_sku', 'gtin'], true) ? ' <span class="text-primary">(one of these is required)</span>' : '' ?></label>
                <input type="text" list="feed-mapping-columns" name="settings_feed[mapping][<?= e($field) ?>]" id="feed-mapping-<?= e($field) ?>" class="form-control btn-touch<?= $err ? ' is-invalid' : '' ?>" value="<?= e((string) ($mapping[$field] ?? '')) ?>" placeholder="column…" autocomplete="off">
                <?php if ($err): ?><div class="invalid-feedback d-block" id="feed-mapping-<?= e($field) ?>-error"><?= e(str_starts_with((string) $err, 'The file') ? $err : 'Not in the file: ' . $err) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
