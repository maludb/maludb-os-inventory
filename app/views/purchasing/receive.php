<?php /** Receive against a stock purchase order (screen `purchase-order-receive`). Data: o, open (the open lines), refusal, locations, seesCost, here, notice */
$poid = (int) $o['purchase_order_id'];
?>
<?= view('shared/header.php', ['id' => 'purchase-order-receive', 'title' => 'Receive ' . $o['number'], 'crumbs' => [['Home', '/'], ['Purchase orders', '/purchasing/'], [$o['number'], '/purchasing/' . $poid], ['Receive', null]], 'back' => back_link() ?? ['/purchasing/' . $poid, $o['number']]]) ?>
<div class="main-content" id="purchase-order-receive-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($refusal !== null): ?><div class="alert alert-warning" id="po-receive-refusal"><?= e($refusal) ?></div><?php endif; ?>
    <div class="card mb-3" id="po-receive-lines"><div class="card-header fw-semibold">What is still open</div><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>#</th><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Open</th><?php if ($seesCost): ?><th class="text-end">Cost</th><?php endif; ?></tr></thead><tbody>
            <?php if ($open === []): ?><tr><td colspan="6" class="text-center text-muted py-3">Nothing is open.</td></tr><?php endif; ?>
            <?php foreach ($open as $l): ?><tr id="po-receive-line-<?= (int) $l['purchase_order_line_id'] ?>"><td><?= (int) $l['line_no'] ?></td><td><span class="fw-semibold"><?= e($l['sku']) ?></span><div class="text-muted"><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></div></td>
                <td class="text-end"><?= (int) $l['qty_ordered'] ?></td><td class="text-end"><?= (int) $l['qty_received'] ?></td><td class="text-end fw-semibold"><?= (int) $l['qty_ordered'] - (int) $l['qty_received'] ?></td><?php if ($seesCost): ?><td class="text-end"><?= money($l['unit_cost'], $l['cost_withheld']) ?></td><?php endif; ?></tr><?php endforeach; ?>
        </tbody></table>
    </div></div></div>
    <form method="post" action="/purchasing/receive.php" hx-post="/purchasing/receive.php" hx-target="#flash" id="po-receive-form" class="card"><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poid ?>">
        <div class="card-body"><label class="form-label fs-12 text-muted" for="po-receive-location">Receiving location</label>
            <select name="location" id="po-receive-location" class="form-select btn-touch"><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>"<?= (int) $o['location_id'] === (int) $l['location_id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
            <div class="fs-12 text-muted mt-2">This drafts a goods receipt with a line for everything still open. You check the quantities and costs, and post it on the receipt's own screen.</div></div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="po-receive-submit"<?= $refusal !== null ? ' disabled' : '' ?>>Draft the receipt</button><?= hx_link('/purchasing/' . $poid, 'Cancel', 'btn btn-light btn-touch', 'id="po-receive-cancel-link"') ?></div>
    </form>
</div>
