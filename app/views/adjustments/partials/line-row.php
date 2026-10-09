<?php /** One adjustment line (`adjustment-line-row-{id}`); a draft's edits in place (Pattern C). Data: l, a, seesCost, here */
$lid = (int) $l['adjustment_line_id'];
$draft = $a['status'] === 'draft';
$fid = 'adjustment-line-row-' . $lid . '-form';
?>
<tr id="adjustment-line-row-<?= $lid ?>">
    <td class="text-muted d-none d-md-table-cell"><?= (int) $l['line_no'] ?></td>
    <td class="text-nowrap"><?= hx_link(with_back('/variants/' . (int) $l['variant_id'], $here), e($l['sku']), 'fw-semibold') ?><div class="d-md-none text-muted"><?= e($l['product_name']) ?></div></td>
    <td class="d-none d-md-table-cell"><?= e($l['product_name']) ?><?= $l['size_name'] ? ' · ' . e($l['size_name']) : '' ?></td>
    <?php if ($draft): ?>
        <td class="text-end"><input form="<?= $fid ?>" type="number" name="qty_delta" value="<?= (int) $l['qty_delta'] ?>" class="form-control btn-touch text-end ms-auto" style="width: 5rem" aria-label="Change" id="adjustment-line-row-<?= $lid ?>-qty"></td>
        <td class="text-end"><?php if ($seesCost): ?><input form="<?= $fid ?>" type="text" name="unit_cost" value="<?= e($l['unit_cost'] ?? '') ?>" class="form-control btn-touch text-end ms-auto" style="width: 6rem" inputmode="decimal" aria-label="Unit cost" id="adjustment-line-row-<?= $lid ?>-cost"><?php else: ?><?= cost_cell(null, false) ?><?php endif; ?></td>
        <td class="d-none d-lg-table-cell"><input form="<?= $fid ?>" type="text" name="note" value="<?= e($l['note'] ?? '') ?>" class="form-control btn-touch" maxlength="1000" aria-label="Note" id="adjustment-line-row-<?= $lid ?>-note"></td>
        <td class="text-end text-nowrap">
            <form method="post" action="/adjustments/lines/save.php" hx-post="/adjustments/lines/save.php" hx-target="closest tr" hx-swap="outerHTML" id="<?= $fid ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="adjustment" value="<?= (int) $a['adjustment_id'] ?>"><input type="hidden" name="line" value="<?= $lid ?>"><input type="hidden" name="variant" value="<?= (int) $l['variant_id'] ?>"><button type="submit" class="btn btn-light btn-touch" id="adjustment-line-row-<?= $lid ?>-save-btn">Save</button></form>
            <form method="post" action="/adjustments/lines/remove.php" hx-post="/adjustments/lines/remove.php" hx-target="#flash" hx-confirm="Remove line <?= (int) $l['line_no'] ?> (<?= e($l['sku']) ?>)?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-touch" id="adjustment-line-row-<?= $lid ?>-remove-btn" aria-label="Remove"><i class="feather-x"></i></button></form>
        </td>
    <?php else: ?>
        <td class="text-end" id="adjustment-line-row-<?= $lid ?>-qty"><?= signed_qty($l['qty_delta']) ?></td>
        <td class="text-end"><?= cost_cell($l['unit_cost'], $seesCost) ?></td>
        <td class="d-none d-lg-table-cell"><?= e($l['note'] ?? '') ?></td>
        <td></td>
    <?php endif; ?>
</tr>
