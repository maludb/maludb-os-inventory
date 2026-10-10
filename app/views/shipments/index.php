<?php /** The shipments (screen `shipment-list`). Data: rows, total, filters, tz, here */
$f = $filters;
?>
<?= view('shared/header.php', ['id' => 'shipment-list', 'title' => 'Shipments', 'crumbs' => [['Home', '/'], ['Shipments', null]], 'back' => back_link()]) ?>
<div class="main-content" id="shipment-list-content">
    <form method="get" action="/shipments/" hx-get="/shipments/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="shipment-list-filters">
        <div class="col-6 col-md-3"><select name="kind" class="form-select btn-touch" id="shipment-list-filter-kind" aria-label="Kind"><option value="">Every kind</option><?php foreach (SHIPMENT_KINDS as $k => $w): ?><option value="<?= $k ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><input type="date" name="from" class="form-control btn-touch" id="shipment-list-filter-from" value="<?= e($f['from']) ?>" aria-label="Shipped from" title="Shipped from"></div>
        <div class="col-6 col-md-3"><input type="date" name="to" class="form-control btn-touch" id="shipment-list-filter-to" value="<?= e($f['to']) ?>" aria-label="Shipped to" title="Shipped to"></div>
        <div class="col-6 col-md-3"><input type="hidden" name="undelivered" value="0"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="shipment-list-filter-undelivered"><input type="checkbox" class="form-check-input mt-0" name="undelivered" value="1" id="shipment-list-filter-undelivered" <?= $f['undelivered'] ? 'checked' : '' ?>>Not delivered yet</label></div>
        <div class="col-12 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="shipment-list-filter-btn">Filter</button></div>
    </form>
    <div class="fs-12 text-muted mb-2" id="shipment-list-count"><?= (int) $total ?> shipment<?= (int) $total === 1 ? '' : 's' ?></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="shipment-list-table"><thead class="thead-light"><tr><th>Order</th><th>Customer</th><th>Kind</th><th>Carrier</th><th>Tracking</th><th>Shipped</th><th>Delivered</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-muted py-4" id="shipment-list-empty">No shipments match.</td></tr><?php endif; ?>
        <?php foreach ($rows as $s): $sid = (int) $s['shipment_id']; ?><tr id="shipment-row-<?= $sid ?>"><td class="text-nowrap"><?= hx_link(with_back('/shipments/' . $sid, $here), e($s['order_number']), 'fw-semibold') ?></td><td><?= hx_link(with_back('/customers/' . (int) $s['customer_id'], $here), e($s['customer_name'])) ?></td>
            <td><?= shipment_kind_chip($s['kind']) ?></td><td><?= e($s['carrier'] ?? '') ?></td>
            <td><?php if ($s['tracking_number']): ?><?= $s['tracking_url'] ? '<a href="' . e($s['tracking_url']) . '" target="_blank" rel="noopener noreferrer">' . e($s['tracking_number']) . '</a>' : e($s['tracking_number']) ?><?php endif; ?></td>
            <td class="text-nowrap"><?= e(format_ts($s['shipped_at'], $tz, 'M j, Y')) ?></td><td class="text-nowrap"><?= $s['delivered_at'] ? e(format_ts($s['delivered_at'], $tz, 'M j, Y')) : '<span class="badge bg-soft-info text-info">on its way</span>' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>
