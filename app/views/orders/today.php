<?php /** Fulfilment today (screen `fulfilment-today`). Data: tabdata (day, groups, dropships_expected), day, loc, locations, mayShip, here */
$prev = date('Y-m-d', strtotime($day . ' -1 day'));
$next = date('Y-m-d', strtotime($day . ' +1 day'));
$q = static fn (string $d): string => '/orders/today?' . http_build_query(array_filter(['date' => $d, 'location' => $loc]));
?>
<?= view('shared/header.php', ['id' => 'fulfilment-today', 'title' => 'Fulfilment today', 'crumbs' => [['Home', '/'], ['Orders', '/orders/'], ['Today', null]], 'back' => back_link()]) ?>
<div class="main-content" id="fulfilment-today-content">
    <form method="get" action="/orders/today" hx-get="/orders/today" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="fulfilment-today-filters">
        <div class="col-6 col-md-3"><label class="visually-hidden" for="fulfilment-today-date">Date</label><input type="date" name="date" id="fulfilment-today-date" class="form-control btn-touch" value="<?= e($day) ?>"></div>
        <div class="col-6 col-md-3"><label class="visually-hidden" for="fulfilment-today-location">Store</label><select name="location" id="fulfilment-today-location" class="form-select btn-touch"><option value="">Every location</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $loc === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="fulfilment-today-btn">Show</button></div>
        <div class="col-6 col-md-4 d-flex gap-2"><?= hx_link($q($prev), '‹ Day before', 'btn btn-light btn-touch', 'id="fulfilment-today-prev"') ?><?= hx_link($q($next), 'Day after ›', 'btn btn-light btn-touch', 'id="fulfilment-today-next"') ?></div>
    </form>
    <?php if ($tabdata['groups'] === [] && $tabdata['dropships_expected'] === []): ?><div class="card"><div class="card-body text-center text-muted" id="fulfilment-today-empty">Nothing to deliver or collect on <?= e(format_date($day)) ?>.</div></div><?php endif; ?>
    <?php foreach ($tabdata['groups'] as $g): ?>
    <div class="card mb-3" id="today-<?= (int) ($g['location_id'] ?? 0) ?>-<?= e($g['delivery_method']) ?>"><div class="card-header fw-semibold"><?= e($g['location_name'] ?? 'No location') ?> · <?= e(DELIVERY_METHODS[$g['delivery_method']] ?? $g['delivery_method']) ?></div>
        <div class="card-body p-0"><?php foreach ($g['orders'] as $o): ?>
            <div class="p-3 border-bottom" id="today-order-<?= (int) $o['sales_order_id'] ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="fs-12"><?= hx_link(with_back('/orders/' . (int) $o['sales_order_id'], $here), e($o['order_number']), 'fw-semibold') ?> · <?= e($o['customer_name']) ?><br><span class="text-muted">promised <?= $o['promised_on'] ? e(format_date($o['promised_on'])) : 'any day' ?><?= $o['ship_to_city'] ? ' · ' . e($o['ship_to_city']) : '' ?></span></div>
                    <?php if ($mayShip): ?><?= hx_link('/orders/' . (int) $o['sales_order_id'] . '/ship', 'Ship', 'btn btn-light btn-touch', 'id="today-order-' . (int) $o['sales_order_id'] . '-ship"') ?><?php endif; ?>
                </div>
                <ul class="mb-0 mt-1 ps-3 fs-12"><?php foreach ($o['lines'] as $l): ?><li><?= (int) $l['to_pick'] ?> × <?= e($l['sku']) ?> <?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?> <?= fulfilment_chip($l['fulfilment_kind']) ?> <?= line_status_chip($l['line_status']) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endforeach; ?></div></div>
    <?php endforeach; ?>
    <div class="card" id="today-dropships"><div class="card-header fw-semibold">Drop-ships expected <?= e(format_date($day)) ?></div><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Purchase order</th><th>Supplier</th><th>Order</th><th>Item</th><th>Status</th><th>Tracking</th></tr></thead><tbody>
            <?php if ($tabdata['dropships_expected'] === []): ?><tr><td colspan="6" class="text-center text-muted py-3" id="today-dropships-empty">None expected.</td></tr><?php endif; ?>
            <?php foreach ($tabdata['dropships_expected'] as $d): ?><tr id="today-dropship-<?= (int) $d['purchase_order_id'] ?>-<?= (int) $d['line_no'] ?>"><td><?= hx_link(with_back('/purchasing/' . (int) $d['purchase_order_id'], $here), e($d['number'])) ?></td><td><?= e($d['supplier_name']) ?></td>
                <td><?= $d['sales_order_id'] ? hx_link(with_back('/orders/' . (int) $d['sales_order_id'], $here), e($d['sales_order_number'])) : '' ?></td><td><?= (int) $d['qty_ordered'] ?> × <?= e($d['sku']) ?></td><td><?= po_status_chip($d['po_status']) ?></td>
                <td><?= e(trim(($d['tracking_carrier'] ?? '') . ' ' . ($d['tracking_number'] ?? ''))) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
</div>
