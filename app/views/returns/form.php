<?php
/**
 * Request or change a return (screens `return-add`, `return-edit`; the form `return-form`). Data: cur, order, returnable, have (the return's own lines by order line), reasons, locations, lookup, q, here, notice
 * No order yet: the picker (a GET to /returns/new?order=) and, without JavaScript, a list of links. An order: its returnable lines (cards under 992 px, a row from 992), how it comes back, when, where to.
 */
$id = $cur['return_id'] ?? null;
$screen = $cur === null ? 'return-add' : 'return-edit';
$back = $id === null ? '/returns/' : '/returns/' . (int) $id;
$h = $cur ?? ['method' => 'pickup', 'scheduled_on' => null, 'location_id' => null, 'notes' => null];
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Request a return' : 'Change ' . $cur['number'], 'crumbs' => array_merge([['Home', '/'], ['Returns', '/returns/']], $cur === null ? [['Request a return', null]] : [[$cur['number'], $back], ['Edit', null]]),
    'back' => back_link() ?? [$back, $cur === null ? 'Returns' : $cur['number']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($order === null): ?>
    <div class="card mb-3" id="return-form-order-card"><div class="card-body">
        <form method="get" action="/returns/new" hx-get="/returns/new" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" id="return-form-order-form" class="row g-2 align-items-end">
            <div class="col-12 col-md-8"><label class="form-label fs-12 text-muted" id="return-form-order-label" for="return-form-field-order-open">The order the goods come back from</label>
                <?= picker_field(['id' => 'return-form-field-order', 'name' => 'order', 'source' => 'returnable_order', 'value' => null, 'placeholder' => 'Choose an order', 'required' => true, 'labelledby' => 'return-form-order-label']) ?></div>
            <div class="col-12 col-md-4"><button type="submit" class="btn btn-primary btn-touch w-100" id="return-form-order-btn">Choose</button></div>
        </form>
        <noscript><div class="mt-3" id="return-form-lookup"><div class="fs-12 text-muted mb-1">Orders with something to come back:</div><div class="list-group">
            <?php foreach ($lookup as $o): ?><a class="list-group-item list-group-item-action btn-touch justify-content-start fs-12" id="return-form-lookup-<?= (int) $o['sales_order_id'] ?>" href="/returns/new?order=<?= (int) $o['sales_order_id'] ?>"><?= e($o['number'] . ' — ' . $o['customer_name'] . ' · ' . $o['status']) ?></a><?php endforeach; ?>
            <?php if ($lookup === []): ?><div class="text-muted fs-12">No order has anything shipped to take back.</div><?php endif; ?></div></div></noscript>
    </div></div>
    <?php else: ?>
    <form method="post" action="/returns/save.php" hx-post="/returns/save.php" hx-target="#flash" id="return-form" class="card"><?= csrf_field() ?>
        <?php if ($id !== null): ?><input type="hidden" name="return" value="<?= (int) $id ?>"><?php else: ?><input type="hidden" name="order" value="<?= (int) $order['sales_order_id'] ?>"><?php endif; ?>
        <div class="page-header-form d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom" id="return-form-header">
            <div class="fw-semibold"><?= $cur === null ? 'Return on ' . e($order['number']) : e($cur['number']) ?> <span class="text-muted fs-12 fw-normal">· <?= e($order['customer_name']) ?></span></div>
            <div class="d-flex gap-2"><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="return-form-cancel-btn"') ?><button type="submit" class="btn btn-primary btn-touch" id="return-form-save-btn"><?= $cur === null ? 'Request the return' : 'Save' ?></button></div>
        </div>
        <div class="card-body">
            <h6 class="fs-13 mb-2">What comes back</h6>
            <?php if ($returnable === []): ?><div class="text-muted fs-12" id="return-form-empty"><?= e($order['number']) ?> has nothing shipped that is not already coming back.</div><?php endif; ?>
            <?php foreach ($returnable as $l): $lid = (int) $l['line_id']; $mine = $have[$lid] ?? null; $max = (int) $l['returnable'] + ($mine === null ? 0 : (int) $mine['qty']); ?>
            <div class="row g-2 border rounded p-2 mb-2" id="return-form-line-<?= $lid ?>">
                <div class="col-12 col-lg-3"><div class="fw-semibold"><?= e($l['sku']) ?></div><div class="fs-12 text-muted"><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></div>
                    <div class="fs-11 text-muted">Shipped <?= (int) $l['qty_shipped'] ?><?= (int) $l['qty_returned'] > 0 ? ' · returned ' . (int) $l['qty_returned'] : '' ?><?= (int) $l['pending'] > 0 ? ' · ' . (int) $l['pending'] . ' on another return' : '' ?></div></div>
                <div class="col-4 col-lg-1"><label class="form-label fs-12 text-muted" for="return-form-line-<?= $lid ?>-qty">Quantity</label>
                    <input type="number" name="lines[<?= $lid ?>][qty]" id="return-form-line-<?= $lid ?>-qty" class="form-control btn-touch" min="0" max="<?= $max ?>" step="1" inputmode="numeric" value="<?= $mine !== null ? (int) $mine['qty'] : 0 ?>"></div>
                <div class="col-8 col-lg-2"><label class="form-label fs-12 text-muted" for="return-form-line-<?= $lid ?>-reason">Reason</label>
                    <select name="lines[<?= $lid ?>][reason]" id="return-form-line-<?= $lid ?>-reason" class="form-select btn-touch"><option value="">Choose…</option><?php foreach ($reasons as $rs): ?><option value="<?= (int) $rs['reason_code_id'] ?>"<?= $mine !== null && (int) $mine['reason_code_id'] === (int) $rs['reason_code_id'] ? ' selected' : '' ?>><?= e($rs['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-lg-2"><label class="form-label fs-12 text-muted" for="return-form-line-<?= $lid ?>-disposition">Disposition</label>
                    <select name="lines[<?= $lid ?>][disposition]" id="return-form-line-<?= $lid ?>-disposition" class="form-select btn-touch"><?php foreach (RETURN_DISPOSITIONS as $k => $w): ?><option value="<?= $k ?>"<?= ($mine['disposition'] ?? 'restock') === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-lg-2"><label class="form-label fs-12 text-muted" for="return-form-line-<?= $lid ?>-location">Lands at</label>
                    <select name="lines[<?= $lid ?>][location]" id="return-form-line-<?= $lid ?>-location" class="form-select btn-touch"><option value="">The return's</option><?php foreach ($locations as $loc): ?><option value="<?= (int) $loc['location_id'] ?>"<?= $mine !== null && (int) ($mine['location_id'] ?? 0) === (int) $loc['location_id'] ? ' selected' : '' ?>><?= e($loc['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-lg-2"><label class="form-label fs-12 text-muted" for="return-form-line-<?= $lid ?>-condition_note">Condition</label>
                    <input type="text" name="lines[<?= $lid ?>][condition_note]" id="return-form-line-<?= $lid ?>-condition_note" class="form-control btn-touch" maxlength="500" value="<?= e($mine['condition_note'] ?? '') ?>"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card-body row g-2 pt-0">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="return-form-field-method">How it comes back</label>
                <select name="method" id="return-form-field-method" class="form-select btn-touch"><?php foreach (RETURN_METHODS as $k => $w): ?><option value="<?= $k ?>"<?= $h['method'] === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="return-form-field-scheduled_on">On</label>
                <input type="date" name="scheduled_on" id="return-form-field-scheduled_on" class="form-control btn-touch" value="<?= e($h['scheduled_on'] ?? '') ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="return-form-field-location">It comes back to</label>
                <select name="location" id="return-form-field-location" class="form-select btn-touch"><option value="">Choose…</option><?php foreach ($locations as $loc): ?><option value="<?= (int) $loc['location_id'] ?>"<?= (int) ($h['location_id'] ?? 0) === (int) $loc['location_id'] ? ' selected' : '' ?>><?= e($loc['name']) ?><?= $loc['is_sellable'] ? '' : ' (not for sale)' ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="return-form-field-notes">Notes</label><textarea name="notes" id="return-form-field-notes" class="form-control" rows="2" maxlength="2000"><?= e($h['notes'] ?? '') ?></textarea></div>
        </div>
    </form>
    <?php endif; ?>
</div>
