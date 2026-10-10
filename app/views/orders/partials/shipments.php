<?php /** The shipments (`order-shipments`): a card each (`order-shipment-{id}`). Data: o, tz, may, here */ ?>
<div id="order-shipments" class="mb-3">
    <?php if ($o['shipments'] === []): ?><div class="card"><div class="card-body text-muted fs-12 text-center" id="order-shipments-empty">Nothing has shipped yet.</div></div><?php endif; ?>
    <?php foreach ($o['shipments'] as $s): $sid = (int) $s['shipment_id']; ?>
    <div class="card mb-2" id="order-shipment-<?= $sid ?>"><div class="card-body fs-12">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div><?= shipment_kind_chip($s['kind']) ?> <?= shipment_state_chip($s) ?>
                <div class="mt-1"><?= $s['carrier'] ? e($s['carrier']) . ' ' : '' ?><?php if ($s['tracking_number']): ?><?= $s['tracking_url'] ? '<a href="' . e($s['tracking_url']) . '" target="_blank" rel="noopener noreferrer" id="order-shipment-' . $sid . '-tracking">' . e($s['tracking_number']) . '</a>' : '<span id="order-shipment-' . $sid . '-tracking">' . e($s['tracking_number']) . '</span>' ?><?php endif; ?></div>
                <div class="text-muted">Shipped <?= e(format_ts($s['shipped_at'], $tz, 'M j, g:i A')) ?><?= $s['delivered_at'] ? ' · delivered ' . e(format_ts($s['delivered_at'], $tz, 'M j, g:i A')) : '' ?></div></div>
            <div class="d-flex flex-column gap-1"><?= hx_link(with_back('/shipments/' . $sid, $here), 'Open', 'btn btn-light btn-touch', 'id="order-shipment-' . $sid . '-open"') ?>
            <?php if (!empty($may['ship']) && $s['delivered_at'] === null): ?><form method="post" action="/orders/deliver.php" hx-post="/orders/deliver.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="shipment" value="<?= $sid ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="order-shipment-<?= $sid ?>-deliver-btn">Deliver</button></form><?php endif; ?></div>
        </div>
        <ul class="mb-0 mt-1 ps-3"><?php foreach ($s['lines'] as $l): ?><li><?= (int) $l['qty'] ?> × <?= e($l['sku']) ?> <?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?><?= $l['serials'] ? ' · serials ' . e(implode(', ', $l['serials'])) : '' ?></li><?php endforeach; ?></ul>
    </div></div>
    <?php endforeach; ?>
</div>
