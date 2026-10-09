<?php /** A receipt's header (screens `receipt-add`, `receipt-edit`; the form `receipt-form`). Data: cur, po, poLines, supplierId, poOptions, locationId, locations, suppliers, seesCost */
$id = $cur['goods_receipt_id'] ?? null;
$screen = $cur === null ? 'receipt-add' : 'receipt-edit';
$back = $id === null ? '/receipts/' : '/receipts/' . (int) $id;
$poSel = $cur['purchase_order_id'] ?? ($po['purchase_order_id'] ?? null);
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New receipt' : 'Change ' . $cur['number'], 'crumbs' => array_merge([['Home', '/'], ['Receipts', '/receipts/']], $cur === null ? [['New', null]] : [[$cur['number'], $back], ['Edit', null]]), 'back' => back_link() ?? [$back, $cur === null ? 'Receipts' : $cur['number']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/receipts/save.php" hx-post="/receipts/save.php" hx-target="#flash" id="receipt-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="receipt" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="receipt-form-field-location">Where it lands</label>
                <select name="location" id="receipt-form-field-location" class="form-select btn-touch" required><option value="">Choose…</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $locationId === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="receipt-form-field-received_on">Received on</label>
                <input type="date" name="received_on" id="receipt-form-field-received_on" class="form-control btn-touch" max="<?= date('Y-m-d') ?>" value="<?= e($cur['received_on'] ?? date('Y-m-d')) ?>" required></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="receipt-form-field-supplier">Supplier</label>
                <select name="supplier" id="receipt-form-field-supplier" class="form-select btn-touch" hx-get="/receipts/po-options" hx-trigger="change" hx-target="#receipt-form-field-purchase_order" hx-swap="innerHTML" hx-include="this"><option value="">— none (a free receipt) —</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['supplier_id'] ?>" <?= (int) $supplierId === (int) $s['supplier_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="receipt-form-field-purchase_order">Purchase order</label>
                <select name="purchase_order" id="receipt-form-field-purchase_order" class="form-select btn-touch"><?= view('receipts/partials/po-options.php', ['options' => $poOptions, 'selected' => $poSel, 'supplier' => $supplierId]) ?></select></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="receipt-form-field-delivery_note_ref">Delivery note</label>
                <input type="text" name="delivery_note_ref" id="receipt-form-field-delivery_note_ref" class="form-control btn-touch" maxlength="100" value="<?= e($cur['delivery_note_ref'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="receipt-form-field-notes">Notes</label>
                <textarea name="notes" id="receipt-form-field-notes" class="form-control" rows="2" maxlength="2000"><?= e($cur['notes'] ?? '') ?></textarea></div>
            <?php if ($poLines !== []): ?><div class="col-12"><?= view('receipts/partials/po-lines.php', ['poLines' => $poLines, 'po' => $po]) ?></div><?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="receipt-form-save-btn"><?= $cur === null ? 'Create' : 'Save' ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="receipt-form-cancel-link"') ?></div>
    </form>
</div>
