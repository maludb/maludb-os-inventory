<?php /** One shipment (screen `shipment-view`). Data: s, mayShip, tz, here, notice */
$sid = (int) $s['shipment_id'];
$actions = $mayShip && $s['delivered_at'] === null ? '<form method="post" action="/orders/deliver.php" hx-post="/orders/deliver.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="shipment" value="' . $sid . '"><button type="submit" class="btn btn-primary btn-touch" id="shipment-view-deliver">Deliver</button></form>' : '';
?>
<?= view('shared/header.php', ['id' => 'shipment-view', 'title' => 'Shipment of ' . $s['order_number'], 'crumbs' => [['Home', '/'], ['Shipments', '/shipments/'], [$s['order_number'], null]], 'back' => back_link() ?? ['/shipments/', 'Shipments'], 'action' => $actions]) ?>
<div class="main-content" id="shipment-view-content" data-entity="shipment">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body fs-12">
        <div class="mb-1"><?= shipment_kind_chip($s['kind']) ?> <?= shipment_state_chip($s) ?></div>
        <div>Order <?= hx_link(with_back('/orders/' . (int) $s['sales_order_id'], $here), e($s['order_number']), 'fw-semibold', 'id="shipment-view-order"') ?> · <?= e($s['customer_name']) ?></div>
        <div id="shipment-view-tracking"><?= $s['carrier'] ? e($s['carrier']) . ' ' : '' ?><?= $s['tracking_number'] ? ($s['tracking_url'] ? '<a href="' . e($s['tracking_url']) . '" target="_blank" rel="noopener noreferrer">' . e($s['tracking_number']) . '</a>' : e($s['tracking_number'])) : '<span class="text-muted">no tracking number</span>' ?></div>
        <div class="text-muted">Shipped <?= e(format_ts($s['shipped_at'], $tz, 'M j, Y g:i A')) ?><?= $s['delivered_at'] ? ' · delivered ' . e(format_ts($s['delivered_at'], $tz, 'M j, Y g:i A')) : '' ?></div>
        <?php if ($s['note']): ?><div class="text-muted"><?= e($s['note']) ?></div><?php endif; ?>
    </div></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="shipment-view-lines"><thead class="thead-light"><tr><th>#</th><th>SKU</th><th>Product</th><th class="text-end">Qty</th><th>Serials</th></tr></thead><tbody>
        <?php foreach ($s['lines'] as $l): ?><tr><td><?= (int) $l['line_no'] ?></td><td class="fw-semibold"><?= e($l['sku']) ?></td><td><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></td><td class="text-end"><?= (int) $l['qty'] ?></td><td><?= e(implode(', ', $l['serials'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>
