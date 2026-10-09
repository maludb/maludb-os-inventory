<?php /** A variant's identifiers (screen `variant-identifiers`): the add form and the table. Data: variant, product, identifiers, sources, here, notice, mayWrite, tz */
$vid = $variant['variant_id'];
?>
<?= view('shared/header.php', ['id' => 'variant-identifiers', 'title' => 'Identifiers of ' . $variant['sku'], 'crumbs' => [['Home', '/'], ['Products', '/products/'], [$product['name'], '/products/' . $variant['product_id']], [$variant['sku'], '/variants/' . $vid], ['Identifiers', null]], 'back' => back_link() ?? ['/variants/' . $vid, $variant['sku']]]) ?>
<div class="main-content" id="variant-identifiers-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($mayWrite): ?>
    <form method="post" action="/variants/identifiers/add.php" hx-post="/variants/identifiers/add.php" hx-target="#flash" class="card mb-3" id="identifier-form">
        <?= csrf_field() ?><input type="hidden" name="variant" value="<?= $vid ?>">
        <div class="card-header"><h5 class="card-title mb-0">Add an identifier</h5></div>
        <div class="card-body row g-2 align-items-end">
            <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="identifier-form-field-kind">Kind</label><select name="kind" id="identifier-form-field-kind" class="form-select btn-touch" required><?php foreach (IDENTIFIER_KINDS as $k => $w): ?><option value="<?= $k ?>"><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="identifier-form-field-value">Value</label><input type="text" name="value" id="identifier-form-field-value" class="form-control btn-touch" maxlength="120" required></div>
            <div class="col-12 col-md-3" id="identifier-form-source-wrap" hidden><label class="form-label fs-12 text-muted" for="identifier-form-field-source">The supplier source it belongs to</label><select name="source" id="identifier-form-field-source" class="form-select btn-touch"><option value="">Choose…</option><?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>"><?= e($s['name']) ?><?= $s['supplier_name'] ? ' · ' . e($s['supplier_name']) : '' ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="identifier-form-save-btn">Add</button></div>
        </div>
    </form>
    <?php endif; ?>
    <div class="card" id="identifier-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="identifier-table">
        <thead class="thead-light"><tr><th>Kind</th><th>Value</th><th>Source</th><th class="d-none d-md-table-cell">Added by</th><th class="d-none d-md-table-cell">When</th><th></th></tr></thead><tbody>
        <?php if ($variant['barcode']): ?><tr id="identifier-row-barcode"><td><?= identifier_kind_chip('gtin') ?></td><td><code><?= e($variant['barcode']) ?></code> <span class="text-muted">the barcode</span></td><td></td><td class="d-none d-md-table-cell"></td><td class="d-none d-md-table-cell"></td><td></td></tr><?php endif; ?>
        <?php if ($identifiers === [] && !$variant['barcode']): ?><tr><td colspan="6" class="text-center text-muted py-4" id="identifier-table-empty">No identifiers yet.</td></tr><?php endif; ?>
        <?php foreach ($identifiers as $i): ?><?= view('catalog/partials/identifier-row.php', ['i' => $i, 'vid' => $vid, 'mayWrite' => $mayWrite, 'tz' => $tz]) ?><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>
<script>
(function () { var k = document.getElementById('identifier-form-field-kind'), w = document.getElementById('identifier-form-source-wrap'); if (!k || !w) return;
    function sync() { if (k.value === 'supplier_sku') { w.removeAttribute('hidden'); } else { w.setAttribute('hidden', ''); } } k.addEventListener('change', sync); sync(); })();
</script>
