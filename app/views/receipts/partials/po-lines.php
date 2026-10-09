<?php /** The open lines of a stock PO to prefill a new receipt (`receipt-form-po-lines`): checked rows with their quantities. Data: poLines, po */ ?>
<div class="mt-3" id="receipt-form-po-lines">
    <div class="fs-12 text-muted mb-1">The open lines of <?= e($po['number']) ?> — uncheck what did not arrive, change a quantity if short.</div>
    <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><thead class="thead-light"><tr><th></th><th>SKU</th><th class="d-none d-md-table-cell">Product</th><th class="text-end">Open</th><th class="text-end">Arrived</th></tr></thead><tbody>
    <?php foreach ($poLines as $pl): $plid = (int) $pl['purchase_order_line_id']; ?>
        <tr id="receipt-form-po-line-<?= $plid ?>"><td><input type="checkbox" class="form-check-input" name="po_lines[]" value="<?= $plid ?>" checked id="receipt-form-po-line-<?= $plid ?>-check" aria-label="Receive line <?= (int) $pl['line_no'] ?>"></td>
            <td><label for="receipt-form-po-line-<?= $plid ?>-check" class="fw-semibold mb-0"><?= e($pl['sku']) ?></label></td><td class="d-none d-md-table-cell"><?= e($pl['product_name']) ?></td><td class="text-end"><?= (int) $pl['qty_open'] ?></td>
            <td class="text-end"><input type="number" name="po_qty[<?= $plid ?>]" value="<?= (int) $pl['qty_open'] ?>" min="1" class="form-control form-control-sm btn-touch text-end ms-auto" style="width: 5.5rem" inputmode="numeric" aria-label="Arrived" id="receipt-form-po-line-<?= $plid ?>-qty"></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
