<?php /** Ship (screen `order-ship`). Data: o, here, seesCost, notice */
$oid = (int) $o['sales_order_id'];
$ship = array_values(array_filter($o['lines'], static fn ($l) => in_array($l['fulfilment_kind'], ['stock', 'pickup', 'backorder'], true) && $l['status'] !== 'cancelled' && $l['qty_shipped'] < $l['qty']));
$drop = array_values(array_filter($o['lines'], static fn ($l) => $l['fulfilment_kind'] === 'dropship' && $l['status'] !== 'cancelled'));
$kind = ship_kind_for($o['delivery_method']);
$canShip = in_array($o['status'], ['confirmed', 'in_fulfilment'], true);
?>
<?= view('shared/header.php', ['id' => 'order-ship', 'title' => 'Ship ' . $o['number'], 'crumbs' => [['Home', '/'], ['Orders', '/orders/'], [$o['number'], '/orders/' . $oid], ['Ship', null]], 'back' => back_link() ?? ['/orders/' . $oid, $o['number']]]) ?>
<div class="main-content" id="order-ship-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (!$canShip): ?><div class="alert alert-warning" id="order-ship-not-ready"><?= e($o['number']) ?> is <?= e(str_replace('_', ' ', $o['status'])) ?> — a confirmed order ships.</div><?php endif; ?>
    <form method="post" action="/orders/ship.php" hx-post="/orders/ship.php" hx-target="#flash" id="order-ship-form" class="card"><?= csrf_field() ?><input type="hidden" name="order" value="<?= $oid ?>">
        <div class="card-body">
            <div class="fw-semibold mb-2">Pick the lines</div>
            <?php if ($ship === []): ?><div class="text-muted fs-12" id="order-ship-none">Nothing is left to ship from stock.</div><?php endif; ?>
            <?php foreach ($ship as $l): $lid = (int) $l['line_id']; $left = $l['qty'] - $l['qty_shipped']; ?>
            <div class="border rounded p-2 mb-2" id="order-ship-line-<?= $lid ?>">
                <div class="d-flex align-items-start gap-2">
                    <input type="hidden" name="lines[<?= $lid ?>][ship]" value="0">
                    <label class="d-flex align-items-center gap-2 btn-touch flex-grow-1 min-w-0" for="order-ship-line-<?= $lid ?>-check"><input type="checkbox" class="form-check-input mt-0 flex-shrink-0" style="width: 1.4rem; height: 1.4rem" name="lines[<?= $lid ?>][ship]" value="1" id="order-ship-line-<?= $lid ?>-check" <?= $l['fulfilment_kind'] !== 'backorder' ? 'checked' : '' ?>>
                        <span class="min-w-0"><span class="fw-semibold"><?= e($l['sku']) ?></span> <span class="text-muted"><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></span><br><span class="fs-12"><?= fulfilment_chip($l['fulfilment_kind']) ?> from <?= e($l['location_name'] ?? 'no location') ?> · <?= (int) $l['qty_allocated'] ?> held</span></span></label>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-4"><label class="form-label fs-12 text-muted" for="order-ship-line-<?= $lid ?>-qty">To ship (<?= $left ?> left)</label><input type="number" name="lines[<?= $lid ?>][qty]" id="order-ship-line-<?= $lid ?>-qty" class="form-control btn-touch" min="0" max="<?= $left ?>" value="<?= $left ?>" inputmode="numeric"></div>
                    <div class="col-8"><label class="form-label fs-12 text-muted" for="order-ship-line-<?= $lid ?>-serials">Serial numbers (comma-separated)</label><input type="text" name="lines[<?= $lid ?>][serials]" id="order-ship-line-<?= $lid ?>-serials" class="form-control btn-touch" autocomplete="off"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php foreach ($drop as $l): ?>
            <div class="border rounded p-2 mb-2 bg-light fs-12" id="order-ship-drop-<?= (int) $l['line_id'] ?>"><span class="fw-semibold"><?= e($l['sku']) ?></span> <?= fulfilment_chip('dropship') ?> — tracking comes from the supplier (<?= hx_link(with_back('/orders/' . $oid, $here), 'see the purchase orders') ?>).</div>
            <?php endforeach; ?>
            <div class="row g-2 mt-1">
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="order-ship-field-kind">How</label><select name="kind" id="order-ship-field-kind" class="form-select btn-touch"><?php foreach (['own_delivery' => 'Our own delivery', 'parcel' => 'Parcel', 'ltl' => 'LTL freight', 'pickup' => 'Customer pickup'] as $k => $w): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="order-ship-field-carrier">Carrier</label><input type="text" name="carrier" id="order-ship-field-carrier" class="form-control btn-touch" maxlength="60" list="order-ship-carriers"><datalist id="order-ship-carriers"><option value="UPS"><option value="FedEx"><option value="USPS"><option value="DHL"></datalist></div>
                <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="order-ship-field-tracking">Tracking number</label><input type="text" name="tracking" id="order-ship-field-tracking" class="form-control btn-touch" maxlength="100" autocomplete="off"></div>
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="order-ship-field-shipped_at">Shipped (now when empty)</label><input type="datetime-local" name="shipped_at" id="order-ship-field-shipped_at" class="form-control btn-touch"></div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="order-ship-submit"<?= $canShip && $ship !== [] ? '' : ' disabled' ?> hx-confirm="Ship the ticked lines?">Ship</button><?= hx_link('/orders/' . $oid, 'Cancel', 'btn btn-light btn-touch', 'id="order-ship-cancel-link"') ?></div>
    </form>
</div>
