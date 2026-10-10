<?php
/**
 * A purchase order (screens `purchase-order-add`, `purchase-order-edit`; the form `po-form`). Data: cur, head, kind, pre, rows (prefilled lines), dropships, so, locations, seesCost, here
 * New stock order: the header and the lines are one form, posted together. Edit (a draft): the header is the form; each line saves on its own (partials/lines.php). A drop-ship is drafted by the database from the sales order's open lines.
 */
$id = $cur['purchase_order_id'] ?? null;
$screen = $cur === null ? 'purchase-order-add' : 'purchase-order-edit';
$back = $id === null ? '/purchasing/' : '/purchasing/' . (int) $id;
$h = $head;
$val = static fn (string $k) => e($h[$k] ?? '');
$supplierId = isset($h['supplier_id']) ? (int) $h['supplier_id'] : null;
$fixedSupplier = $cur !== null && $cur['kind'] === 'dropship';
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New purchase order' : 'Change ' . $cur['number'], 'crumbs' => array_merge([['Home', '/'], ['Purchase orders', '/purchasing/']], $cur === null ? [['New', null]] : [[$cur['number'], $back], ['Edit', null]]),
    'back' => back_link() ?? [$back, $cur === null ? 'Purchase orders' : $cur['number']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?php if ($cur === null): ?>
    <div class="d-flex gap-2 mb-3" id="po-form-kind">
        <?= hx_link('/purchasing/new?' . http_build_query(array_filter(['kind' => 'stock', 'supplier' => $pre['supplier']])), '<i class="feather-box me-1"></i>For stock', 'btn btn-touch ' . ($kind === 'stock' ? 'btn-primary' : 'btn-light'), 'id="po-form-kind-stock"') ?>
        <?= hx_link('/purchasing/new?' . http_build_query(array_filter(['kind' => 'dropship', 'order' => $pre['order']])), '<i class="feather-truck me-1"></i>Drop-ship', 'btn btn-touch ' . ($kind === 'dropship' ? 'btn-primary' : 'btn-light'), 'id="po-form-kind-dropship"') ?>
    </div>
    <?php endif; ?>
    <?php if ($kind === 'dropship' && $cur === null): ?>
        <form method="get" action="/purchasing/new" class="row g-2 mb-3" id="po-form-order-lookup"><input type="hidden" name="kind" value="dropship">
            <div class="col-8 col-md-4"><label class="form-label fs-12 text-muted" for="po-form-field-order">The sales order the drop-ship fills</label><input type="text" name="order" id="po-form-field-order" class="form-control btn-touch" placeholder="SO-00012" value="<?= e($pre['order']) ?>" autocomplete="off"></div>
            <div class="col-4 col-md-2"><label class="form-label fs-12 text-muted">&nbsp;</label><button type="submit" class="btn btn-light btn-touch w-100" id="po-form-order-btn">Look up</button></div></form>
        <?php if ($pre['order'] !== '' && $so === null): ?><div class="alert alert-warning" id="po-form-order-missing">No sales order has the number or id “<?= e($pre['order']) ?>”.</div><?php endif; ?>
        <?php if ($so !== null): ?><?= view('purchasing/partials/dropship-draft.php', ['so' => $so, 'dropships' => $dropships, 'seesCost' => $seesCost]) ?>
        <?php else: ?><div class="text-muted fs-12" id="po-form-dropship-hint">A drop-ship purchase order is drafted by the database from a confirmed sales order's open drop-ship lines — name the order above.</div><?php endif; ?>
    <?php else: ?>
    <form method="post" action="/purchasing/save.php" hx-post="/purchasing/save.php" hx-target="#flash" id="po-form" class="card" data-supplier-field="po-form-field-supplier">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="purchase_order" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="page-header-form d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom" id="po-form-header">
            <div class="fw-semibold"><?= $cur === null ? 'New purchase order' : e($cur['number']) ?></div>
            <div class="d-flex gap-2"><?php if ($cur === null): ?><button type="button" class="btn btn-light btn-touch" id="po-lines-add-btn" data-add-line><i class="feather-plus me-1"></i>Add line</button><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-touch" id="po-form-save-btn"><?= $cur === null ? 'Draft the order' : 'Save' ?></button></div>
        </div>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6">
                <label class="form-label fs-12 text-muted" id="po-form-supplier-label" for="po-form-field-supplier-open">Supplier</label>
                <?php if ($fixedSupplier): ?><div class="form-control btn-touch bg-light" id="po-form-supplier-fixed"><?= e($cur['supplier_name']) ?></div><div class="fs-11 text-muted mt-1">A drop-ship's supplier is the offer's.</div>
                <?php else: ?><?= view('shared/supplier-select.php', ['value' => $supplierId, 'required' => true]) ?>
                    <?php if ($cur !== null && $cur['lines'] !== []): ?><div class="fs-11 text-muted mt-1" id="po-form-supplier-note">Changing the supplier clears the lines' SKUs and offers — they are the old supplier's — and re-reads the SKUs from the new one's price sheet.</div><?php endif; ?><?php endif; ?>
            </div>
            <?php if ($kind === 'stock'): ?>
            <div class="col-12 col-md-6">
                <label class="form-label fs-12 text-muted" for="po-form-field-location">Ship to</label>
                <select name="location" id="po-form-field-location" class="form-select btn-touch"><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) ($h['location_id'] ?? 0) === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="po-form-field-expected_on">Expected</label><input type="date" name="expected_on" id="po-form-field-expected_on" class="form-control btn-touch" value="<?= $val('expected_on') ?>"></div>
            <?php if ($seesCost): ?><div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="po-form-field-shipping_cost">Shipping cost</label><input type="text" name="shipping_cost" id="po-form-field-shipping_cost" class="form-control btn-touch" inputmode="decimal" value="<?= e(number_format((float) ($h['shipping_cost'] ?? 0), 2, '.', '')) ?>"></div><?php endif; ?>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="po-form-field-notes">Notes to the supplier</label><textarea name="notes" id="po-form-field-notes" class="form-control" rows="2" maxlength="2000"><?= $val('notes') ?></textarea><div class="fs-11 text-muted">Shown on their page and in the email.</div></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="po-form-field-internal_notes">Internal notes</label><textarea name="internal_notes" id="po-form-field-internal_notes" class="form-control" rows="2" maxlength="2000"><?= $val('internal_notes') ?></textarea><div class="fs-11 text-muted">Never shown to the supplier.</div></div>
        </div>
        <?php if ($cur === null): ?>
        <div class="card-body pt-0">
            <h6 class="fs-13 mb-2">Lines</h6>
            <div id="po-lines" data-next="<?= max(1, count($rows)) + 0 ?>">
                <?php if ($rows === []): ?><?= view('purchasing/partials/line-row.php', ['mode' => 'new', 'n' => 0, 'fv' => null, 'supplierId' => $supplierId, 'seesCost' => $seesCost]) ?>
                <?php else: foreach ($rows as $i => $r): ?><?= view('purchasing/partials/line-row.php', ['mode' => 'new', 'n' => $i, 'fv' => $r, 'supplierId' => $supplierId, 'seesCost' => $seesCost]) ?><?php endforeach; endif; ?>
            </div>
            <template id="po-line-template"><?= view('purchasing/partials/line-row.php', ['mode' => 'new', 'n' => '__N__', 'fv' => null, 'supplierId' => $supplierId, 'seesCost' => $seesCost]) ?></template>
            <div class="fs-12 text-muted" id="po-lines-hint">The totals are worked out when you save. A bundle is bought as its components.</div>
        </div>
        <?php endif; ?>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="po-form-save-bottom-btn"><?= $cur === null ? 'Draft the order' : 'Save' ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="po-form-cancel-link"') ?></div>
    </form>
    <?php if ($cur !== null): ?>
    <h6 class="fs-13 mt-3 mb-2">Lines</h6>
    <?= view('purchasing/partials/lines.php', ['o' => $cur, 'editable' => true, 'seesCost' => $seesCost, 'form' => true, 'may' => ['write' => true], 'here' => $here]) ?>
    <div id="po-totals"><?= view('purchasing/partials/totals.php', ['o' => $cur, 'seesCost' => $seesCost]) ?></div>
    <?php endif; ?>
    <?php endif; ?>
</div>
