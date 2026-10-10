<?php
/**
 * A quote (screens `order-add`, `order-edit`; the form `order-form`). Data: cur, head, first, results, pre, locations, rates, people, sellable, seesCost, mayCustomer, here
 * New: the header and the lines are one form, posted together. Edit (a quote): the header is the form; each line saves on its own (partials/lines.php).
 */
$id = $cur['sales_order_id'] ?? null;
$screen = $cur === null ? 'order-add' : 'order-edit';
$back = $id === null ? '/orders/' : '/orders/' . (int) $id;
$h = $head;
$val = static fn (string $k) => e($h[$k] ?? '');
$pickup = ($h['delivery_method'] ?? 'delivery') === 'pickup';
$ship = array_intersect_key($h, array_flip(['ship_to_name', 'ship_to_address1', 'ship_to_address2', 'ship_to_city', 'ship_to_region', 'ship_to_postal', 'ship_to_country', 'ship_to_phone', 'ship_to_notes']));
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New quote' : 'Change ' . $cur['number'], 'crumbs' => array_merge([['Home', '/'], ['Orders', '/orders/']], $cur === null ? [['New quote', null]] : [[$cur['number'], $back], ['Edit', null]]),
    'back' => back_link() ?? [$back, $cur === null ? 'Orders' : $cur['number']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?php if ($cur === null): ?>
    <noscript><div class="card mb-3" id="order-form-lookup"><div class="card-body">
        <form method="get" action="/orders/new" class="row g-2"><?php if ($pre['customer']): ?><input type="hidden" name="customer" value="<?= (int) $pre['customer'] ?>"><?php endif; ?>
            <div class="col-8"><label class="form-label fs-12 text-muted" for="order-form-lookup-q">Look up a variant</label><input type="search" name="q" id="order-form-lookup-q" class="form-control btn-touch" value="<?= e($pre['q']) ?>" placeholder="A SKU, a GTIN or a name"></div>
            <div class="col-4"><label class="form-label fs-12 text-muted">&nbsp;</label><button type="submit" class="btn btn-light btn-touch w-100">Look up</button></div></form>
        <?php if ($results !== []): ?><div class="list-group mt-2" id="order-form-lookup-results"><?php foreach ($results as $r): ?>
            <a class="list-group-item list-group-item-action btn-touch justify-content-start fs-12" id="order-form-lookup-<?= (int) $r['variant_id'] ?>" href="/orders/new?<?= e(http_build_query(array_filter(['customer' => $pre['customer'], 'variant' => $r['variant_id'], 'qty' => 1]))) ?>"><?= e($r['sku'] . ' — ' . $r['product_name'] . ($r['size_name'] ? ', ' . $r['size_name'] : '')) ?> · <?= money($r['retail_price']) ?></a><?php endforeach; ?></div><?php endif; ?>
    </div></div></noscript>
    <?php endif; ?>
    <form method="post" action="/orders/save.php" hx-post="/orders/save.php" hx-target="#flash" id="order-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="order" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="page-header-form d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom" id="order-form-header">
            <div class="fw-semibold"><?= $cur === null ? 'New quote' : e($cur['number']) ?></div>
            <div class="d-flex gap-2"><?php if ($cur === null): ?><button type="button" class="btn btn-light btn-touch" id="order-lines-add" data-add-line><i class="feather-plus me-1"></i>Add line</button><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-touch" id="order-form-save-btn"><?= $cur === null ? 'Save the quote' : 'Save' ?></button></div>
        </div>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6">
                <label class="form-label fs-12 text-muted" id="order-form-customer-label" for="order-form-field-customer-open">Customer</label>
                <?= view('shared/customer-select.php', ['value' => $h['customer_id'] ?? null, 'extra' => ' hx-get="/customers/ship-to" hx-trigger="change" hx-target="#order-form-ship-to" hx-swap="outerHTML" hx-include="this"']) ?>
                <?php if ($mayCustomer): ?><details class="mt-2" id="order-form-new-customer-box"><summary class="fs-12 fw-semibold text-primary" style="cursor: pointer" id="order-form-new-customer-toggle">+ New customer</summary>
                    <div class="row g-2 mt-1" id="order-form-new-customer">
                        <div class="col-12"><label class="form-label fs-12 text-muted" for="order-form-field-new_customer_name">Name</label><input type="text" name="new_customer_name" id="order-form-field-new_customer_name" class="form-control btn-touch" maxlength="200"></div>
                        <div class="col-6"><label class="form-label fs-12 text-muted" for="order-form-field-new_customer_email">Email</label><input type="email" name="new_customer_email" id="order-form-field-new_customer_email" class="form-control btn-touch" maxlength="200" autocomplete="off"></div>
                        <div class="col-6"><label class="form-label fs-12 text-muted" for="order-form-field-new_customer_phone">Phone</label><input type="tel" name="new_customer_phone" id="order-form-field-new_customer_phone" class="form-control btn-touch" maxlength="40"></div>
                    </div></details><?php endif; ?>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fs-12 text-muted" for="order-form-field-location">Store</label>
                <select name="location" id="order-form-field-location" class="form-select btn-touch"><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) ($h['location_id'] ?? 0) === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fs-12 text-muted" for="order-form-field-salesperson">Salesperson</label>
                <select name="salesperson" id="order-form-field-salesperson" class="form-select btn-touch"><?php foreach ($people as $p): ?><option value="<?= (int) $p['member_id'] ?>" <?= (int) ($h['salesperson_member_id'] ?? 0) === (int) $p['member_id'] ? 'selected' : '' ?>><?= e($p['display_name']) ?><?= $p['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label fs-12 text-muted" for="order-form-field-delivery_method">Delivery</label>
                <select name="delivery_method" id="order-form-field-delivery_method" class="form-select btn-touch" data-delivery-method><?php foreach (DELIVERY_METHODS as $k => $w): ?><option value="<?= $k ?>" <?= ($h['delivery_method'] ?? 'delivery') === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label fs-12 text-muted" for="order-form-field-promised_on">Promised for</label>
                <input type="date" name="promised_on" id="order-form-field-promised_on" class="form-control btn-touch" min="<?= e($cur['ordered_on'] ?? date('Y-m-d')) ?>" value="<?= $val('promised_on') ?>">
            </div>
            <div class="col-12" id="order-form-ship-to-box"<?= $pickup ? ' hidden' : '' ?>><?= view('customers/partials/ship-to.php', ['ship' => $ship]) ?></div>
            <div class="col-6 col-md-4">
                <label class="form-label fs-12 text-muted" for="order-form-field-tax_rate">Tax rate</label>
                <select name="tax_rate" id="order-form-field-tax_rate" class="form-select btn-touch"><?php foreach ($rates as $r): ?><option value="<?= (int) $r['tax_rate_id'] ?>" <?= (int) ($h['tax_rate_id'] ?? 0) === (int) $r['tax_rate_id'] ? 'selected' : '' ?>><?= e($r['name']) ?> (<?= e(rtrim(rtrim((string) $r['rate'], '0'), '.')) ?>%)</option><?php endforeach; ?></select>
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label fs-12 text-muted" for="order-form-field-shipping">Shipping charge</label>
                <input type="text" name="shipping" id="order-form-field-shipping" class="form-control btn-touch" inputmode="decimal" value="<?= e(number_format((float) ($h['shipping_charge'] ?? 0), 2, '.', '')) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fs-12 text-muted" for="order-form-field-customer_reference">Customer's reference</label>
                <input type="text" name="customer_reference" id="order-form-field-customer_reference" class="form-control btn-touch" maxlength="100" value="<?= $val('customer_reference') ?>">
            </div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="order-form-field-notes">Notes</label><textarea name="notes" id="order-form-field-notes" class="form-control" rows="2" maxlength="2000"><?= $val('notes') ?></textarea></div>
        </div>
        <?php if ($cur === null): ?>
        <div class="card-body pt-0">
            <h6 class="fs-13 mb-2">Lines</h6>
            <div id="order-lines" data-next="<?= $first ? 2 : 1 ?>">
                <?= view('orders/partials/line-row.php', ['mode' => 'new', 'n' => 0, 'first' => $first, 'seesCost' => $seesCost]) ?>
                <?php if ($first): ?><?= view('orders/partials/line-row.php', ['mode' => 'new', 'n' => 1, 'first' => null, 'seesCost' => $seesCost]) ?><?php endif; ?>
            </div>
            <template id="order-line-template"><?= view('orders/partials/line-row.php', ['mode' => 'new', 'n' => '__N__', 'first' => null, 'seesCost' => $seesCost]) ?></template>
            <div class="fs-12 text-muted" id="order-lines-hint">The totals are worked out when you save.</div>
        </div>
        <?php endif; ?>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="order-form-save-bottom-btn"><?= $cur === null ? 'Save the quote' : 'Save' ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="order-form-cancel-link"') ?></div>
    </form>
    <?php if ($cur !== null): ?>
    <h6 class="fs-13 mt-3 mb-2">Lines</h6>
    <?= view('orders/partials/lines.php', ['o' => $cur, 'editable' => true, 'seesCost' => $seesCost, 'form' => true, 'locations' => $sellable]) ?>
    <div id="order-totals"><?= view('orders/partials/totals.php', ['o' => $cur]) ?></div>
    <?php endif; ?>
</div>
