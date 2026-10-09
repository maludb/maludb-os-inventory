<?php /** One receipt line (`receipt-line-row-{id}`): a draft's row edits in place (Pattern C: hx-target="closest tr"). Data: l, r, seesCost, locations, here */
$lid = (int) $l['goods_receipt_line_id'];
$draft = $r['status'] === 'draft';
$fid = 'receipt-line-row-' . $lid . '-form';
?>
<tr id="receipt-line-row-<?= $lid ?>">
    <td class="text-muted d-none d-md-table-cell"><?= (int) $l['line_no'] ?></td>
    <td class="text-nowrap"><?= hx_link(with_back('/variants/' . (int) $l['variant_id'], $here), e($l['sku']), 'fw-semibold') ?><div class="d-md-none text-muted"><?= e($l['product_name']) ?><?= $l['size_name'] ? ' · ' . e($l['size_name']) : '' ?></div></td>
    <td class="d-none d-md-table-cell"><?= e($l['product_name']) ?></td>
    <td class="d-none d-md-table-cell"><?= e($l['size_name'] ?? '') ?></td>
    <?php if ($draft): ?>
        <td class="text-end"><input form="<?= $fid ?>" type="number" name="qty" min="1" value="<?= (int) $l['qty'] ?>" class="form-control btn-touch text-end ms-auto" style="width: 5rem" inputmode="numeric" aria-label="Quantity" id="receipt-line-row-<?= $lid ?>-qty"></td>
        <td class="text-end"><?php if ($seesCost): ?><input form="<?= $fid ?>" type="text" name="unit_cost" value="<?= e($l['unit_cost'] ?? '') ?>" class="form-control btn-touch text-end ms-auto" style="width: 6rem" inputmode="decimal" aria-label="Unit cost" id="receipt-line-row-<?= $lid ?>-cost"><?php else: ?><?= cost_cell(null, false) ?><?php endif; ?></td>
        <td class="d-none d-lg-table-cell"><select form="<?= $fid ?>" name="putaway_location" class="form-select btn-touch" aria-label="Put away at" id="receipt-line-row-<?= $lid ?>-putaway"><option value="">The receipt's</option><?php foreach ($locations as $loc): ?><option value="<?= (int) $loc['location_id'] ?>" <?= (int) $l['putaway_location_id'] === (int) $loc['location_id'] ? 'selected' : '' ?>><?= e($loc['name']) ?></option><?php endforeach; ?></select></td>
        <td class="d-none d-lg-table-cell"><select form="<?= $fid ?>" name="discrepancy_kind" class="form-select btn-touch" aria-label="Discrepancy" id="receipt-line-row-<?= $lid ?>-discrepancy"><?php foreach (DISCREPANCY_KINDS as $k => $w): ?><option value="<?= $k ?>" <?= $l['discrepancy_kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></td>
        <td class="text-end text-nowrap">
            <form method="post" action="/receipts/lines/save.php" hx-post="/receipts/lines/save.php" hx-target="closest tr" hx-swap="outerHTML" id="<?= $fid ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="receipt" value="<?= (int) $r['goods_receipt_id'] ?>"><input type="hidden" name="line" value="<?= $lid ?>"><input type="hidden" name="variant" value="<?= (int) $l['variant_id'] ?>"><button type="submit" class="btn btn-light btn-touch" id="receipt-line-row-<?= $lid ?>-save-btn">Save</button></form>
            <form method="post" action="/receipts/lines/remove.php" hx-post="/receipts/lines/remove.php" hx-target="#flash" hx-confirm="Remove line <?= (int) $l['line_no'] ?> (<?= e($l['sku']) ?>)?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-touch" id="receipt-line-row-<?= $lid ?>-remove-btn" aria-label="Remove"><i class="feather-x"></i></button></form>
        </td>
    <?php else: ?>
        <td class="text-end" id="receipt-line-row-<?= $lid ?>-qty"><?= (int) $l['qty'] ?></td>
        <td class="text-end" id="receipt-line-row-<?= $lid ?>-cost"><?= cost_cell($l['unit_cost'], $seesCost) ?></td>
        <td class="d-none d-lg-table-cell"><?= e($l['putaway_location_name'] ?? $r['location_name']) ?></td>
        <td class="d-none d-lg-table-cell"><?= discrepancy_chip($l['discrepancy_kind']) ?><?= $l['discrepancy_note'] ? ' <span class="text-muted">' . e($l['discrepancy_note']) . '</span>' : '' ?></td>
        <td></td>
    <?php endif; ?>
</tr>
