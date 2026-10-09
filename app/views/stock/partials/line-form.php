<?php /** The by-hand line form of a draft (`{kind}-line-form`), folded under "Add by hand" on a phone. Data: kind (receipt / adjustment / transfer), docId, seesCost, locations, poLines (receipt), open (bool) */
$p = $kind . '-line-form';
$action = '/' . $kind . 's/lines/save.php';
?>
<details class="card mb-3" id="<?= $p ?>-wrap" <?= !empty($open) ? 'open' : '' ?>>
    <summary class="card-header btn-touch d-flex align-items-center"><span class="card-title mb-0 fw-semibold">Add by hand</span></summary>
    <form method="post" action="<?= $action ?>" hx-post="<?= $action ?>" hx-target="#flash" id="<?= $p ?>" class="card-body row g-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="<?= $kind ?>" value="<?= (int) $docId ?>">
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-variant-open">Variant</label><?= picker_field(['id' => $p . '-field-variant', 'name' => 'variant', 'source' => 'variant', 'required' => true, 'params' => ['single' => '1']]) ?></div>
        <?php if ($kind === 'adjustment'): ?>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-qty_delta">Change (+/−)</label><input type="number" name="qty_delta" id="<?= $p ?>-field-qty_delta" class="form-control btn-touch" required placeholder="-1"></div>
        <?php else: ?>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-qty">Qty</label><input type="number" name="qty" id="<?= $p ?>-field-qty" class="form-control btn-touch" min="1" value="1" required inputmode="numeric"></div>
        <?php endif; ?>
        <?php if ($kind !== 'transfer' && $seesCost): ?>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-unit_cost">Unit cost</label><input type="text" name="unit_cost" id="<?= $p ?>-field-unit_cost" class="form-control btn-touch" inputmode="decimal" placeholder="0.00"></div>
        <?php endif; ?>
        <?php if ($kind === 'receipt'): ?>
            <?php if (!empty($poLines)): ?><div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-purchase_order_line">Purchase order line</label><select name="purchase_order_line" id="<?= $p ?>-field-purchase_order_line" class="form-select btn-touch"><option value="">— none —</option><?php foreach ($poLines as $pl): ?><option value="<?= (int) $pl['purchase_order_line_id'] ?>">Line <?= (int) $pl['line_no'] ?> · <?= e($pl['sku']) ?> · <?= (int) $pl['qty_open'] ?> open</option><?php endforeach; ?></select></div><?php endif; ?>
            <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-putaway_location">Put away at</label><select name="putaway_location" id="<?= $p ?>-field-putaway_location" class="form-select btn-touch"><option value="">The receipt's location</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-discrepancy_kind">Discrepancy</label><select name="discrepancy_kind" id="<?= $p ?>-field-discrepancy_kind" class="form-select btn-touch"><?php foreach (DISCREPANCY_KINDS as $k => $w): ?><option value="<?= $k ?>"><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-discrepancy_note">Discrepancy note</label><input type="text" name="discrepancy_note" id="<?= $p ?>-field-discrepancy_note" class="form-control btn-touch" maxlength="1000"></div>
        <?php elseif ($kind === 'adjustment'): ?>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="<?= $p ?>-field-note">Note</label><input type="text" name="note" id="<?= $p ?>-field-note" class="form-control btn-touch" maxlength="1000"></div>
        <?php endif; ?>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="<?= $p ?>-save-btn">Add line</button></div>
    </form>
</details>
