<?php /** The confirmation (screen `order-confirm`). Data: o, preview (confirm_preview()), seesCost, locations, here, notice */
$oid = (int) $o['sales_order_id'];
$cannot = array_filter($preview, static fn ($p) => !$p['ok']);
$bySupplier = [];
foreach ($preview as $p) { if ($p['kind'] === 'dropship') { $bySupplier[$p['supplier']] = ($bySupplier[$p['supplier']] ?? 0) + 1; } }
?>
<?= view('shared/header.php', ['id' => 'order-confirm', 'title' => 'Confirm ' . $o['number'], 'crumbs' => [['Home', '/'], ['Orders', '/orders/'], [$o['number'], '/orders/' . $oid], ['Confirm', null]], 'back' => back_link() ?? ['/orders/' . $oid, $o['number']]]) ?>
<div class="main-content" id="order-confirm-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($o['status'] !== 'quote'): ?>
        <div class="alert alert-warning" id="order-confirm-not-quote"><?= e($o['number']) ?> is <?= e(str_replace('_', ' ', $o['status'])) ?> — only a quote is confirmed.</div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-body fs-12">
        <div class="fw-semibold mb-2">What confirming <?= e($o['number']) ?> will do</div>
        <ul class="list-unstyled mb-0" id="order-confirm-lines">
        <?php foreach ($preview as $p): $l = $p['line']; $lid = (int) $l['line_id']; ?>
            <li class="py-2 border-bottom" id="order-confirm-line-<?= $lid ?>">
                <div class="d-flex justify-content-between gap-2"><span class="fw-semibold"><?= (int) $l['line_no'] ?>. <?= (int) $l['qty'] ?> × <?= e($l['sku']) ?> <span class="text-muted fw-normal"><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></span></span><?= fulfilment_chip($l['fulfilment_kind']) ?></div>
                <div class="<?= $p['ok'] ? 'text-muted' : 'text-danger fw-semibold' ?>" id="order-confirm-line-<?= $lid ?>-will"><?= e(ucfirst($p['text'])) ?><?= $p['kind'] === 'dropship' && $seesCost && $p['cost'] !== null ? ' · cost ' . e(money($p['cost'])) : '' ?></div>
                <?php if ($p['fix'] && $o['status'] === 'quote'): ?>
                    <div class="d-flex flex-wrap gap-2 mt-1">
                        <form method="post" action="/orders/lines/fulfilment.php" hx-post="/orders/lines/fulfilment.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><input type="hidden" name="fulfilment_kind" value="backorder"><input type="hidden" name="location" value="<?= (int) $l['location_id'] ?>"><input type="hidden" name="return_to" value="/orders/<?= $oid ?>/confirm">
                            <button type="submit" class="btn btn-light btn-touch" id="order-confirm-line-<?= $lid ?>-backorder-btn">Make it a backorder</button></form>
                        <?= hx_link('/orders/' . $oid . '/edit#order-line-' . $lid, 'Choose another source', 'btn btn-light btn-touch', 'id="order-confirm-line-' . $lid . '-pick-link"') ?>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if ($bySupplier !== []): ?><div class="mt-2" id="order-confirm-pos"><strong><?= count($bySupplier) ?></strong> purchase order<?= count($bySupplier) === 1 ? '' : 's' ?> will be drafted: <?= e(implode(', ', array_map(static fn ($s, $n) => $s . ' (' . $n . ' line' . ($n === 1 ? '' : 's') . ')', array_keys($bySupplier), $bySupplier))) ?>. A person places them.</div><?php endif; ?>
        <div class="mt-2 text-muted">Confirming sends nothing to the customer — the confirmation email is the Send button.</div>
    </div></div>
    <?= view('orders/partials/totals.php', ['o' => $o]) ?>
    <?php if ($o['status'] === 'quote'): ?>
    <form method="post" action="/orders/confirm.php" hx-post="/orders/confirm.php" hx-target="#flash" id="order-confirm-form" class="d-flex gap-2 mt-3"><?= csrf_field() ?><input type="hidden" name="order" value="<?= $oid ?>">
        <button type="submit" class="btn btn-primary btn-touch" id="order-confirm-submit"<?= $cannot !== [] ? ' title="Some lines cannot be covered — the confirmation will be refused"' : '' ?>>Confirm <?= e($o['number']) ?></button>
        <?= hx_link('/orders/' . $oid, 'Not yet', 'btn btn-light btn-touch', 'id="order-confirm-cancel-link"') ?></form>
    <?php endif; ?>
</div>
