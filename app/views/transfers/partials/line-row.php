<?php /** One transfer line (`transfer-line-row-{id}`): a draft's qty edits in place (Pattern C); in transit, the received input (`transfer-line-row-{id}-received`, part of the receive form). Data: l, t, here */
$lid = (int) $l['transfer_line_id'];
$fid = 'transfer-line-row-' . $lid . '-form';
?>
<tr id="transfer-line-row-<?= $lid ?>">
    <td class="text-muted d-none d-md-table-cell"><?= (int) $l['line_no'] ?></td>
    <td class="text-nowrap"><?= hx_link(with_back('/variants/' . (int) $l['variant_id'], $here), e($l['sku']), 'fw-semibold') ?><div class="d-md-none text-muted"><?= e($l['product_name']) ?></div></td>
    <td class="d-none d-md-table-cell"><?= e($l['product_name']) ?><?= $l['size_name'] ? ' · ' . e($l['size_name']) : '' ?></td>
    <?php if ($t['status'] === 'draft'): ?>
        <td class="text-end"><input form="<?= $fid ?>" type="number" name="qty" min="1" value="<?= (int) $l['qty'] ?>" class="form-control btn-touch text-end ms-auto" style="width: 5rem" inputmode="numeric" aria-label="Quantity" id="transfer-line-row-<?= $lid ?>-qty"></td>
        <td class="text-end">—</td>
        <td class="text-end text-nowrap">
            <form method="post" action="/transfers/lines/save.php" hx-post="/transfers/lines/save.php" hx-target="closest tr" hx-swap="outerHTML" id="<?= $fid ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="transfer" value="<?= (int) $t['transfer_id'] ?>"><input type="hidden" name="line" value="<?= $lid ?>"><input type="hidden" name="variant" value="<?= (int) $l['variant_id'] ?>"><button type="submit" class="btn btn-light btn-touch" id="transfer-line-row-<?= $lid ?>-save-btn">Save</button></form>
            <form method="post" action="/transfers/lines/remove.php" hx-post="/transfers/lines/remove.php" hx-target="#flash" hx-confirm="Remove line <?= (int) $l['line_no'] ?> (<?= e($l['sku']) ?>)?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-touch" id="transfer-line-row-<?= $lid ?>-remove-btn" aria-label="Remove"><i class="feather-x"></i></button></form>
        </td>
    <?php elseif ($t['status'] === 'in_transit'): ?>
        <td class="text-end" id="transfer-line-row-<?= $lid ?>-qty"><?= (int) $l['qty'] ?></td>
        <td class="text-end"><input form="transfer-receive-form" type="number" name="qty[<?= $lid ?>]" min="0" max="<?= (int) $l['qty'] ?>" value="<?= (int) $l['qty'] ?>" class="form-control btn-touch text-end ms-auto" style="width: 5rem" inputmode="numeric" aria-label="Received" id="transfer-line-row-<?= $lid ?>-received"></td>
        <td></td>
    <?php else: ?>
        <td class="text-end" id="transfer-line-row-<?= $lid ?>-qty"><?= (int) $l['qty'] ?></td>
        <td class="text-end" id="transfer-line-row-<?= $lid ?>-received"><?= (int) $l['qty_received'] ?><?= (int) $l['qty_received'] < (int) $l['qty'] && $t['status'] === 'received' ? ' <span class="badge bg-soft-warning text-warning">' . ((int) $l['qty'] - (int) $l['qty_received']) . ' left in transit</span>' : '' ?></td>
        <td></td>
    <?php endif; ?>
</tr>
