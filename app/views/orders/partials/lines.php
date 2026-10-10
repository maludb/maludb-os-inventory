<?php /** The lines region (`order-lines`). Data: o (find_order), editable (a quote's edit form: each line its own form, an add row), seesCost, form (true on the edit form: refreshes itself on orderChanged), locations, here?, mayCancel? */
$oid = (int) $o['sales_order_id'];
$here = $here ?? '/orders/' . $oid;
$refresh = !empty($form) ? ' hx-get="/orders/' . $oid . '/lines" hx-trigger="orderChanged from:body" hx-swap="outerHTML"' : '';
?>
<div id="order-lines"<?= $refresh ?>>
<?php if (!empty($editable)): ?>
    <?php foreach ($o['lines'] as $l): ?><?= view('orders/partials/line-row.php', ['mode' => 'saved', 'l' => $l, 'o' => $o, 'seesCost' => $seesCost]) ?><?php endforeach; ?>
    <?php if ($o['lines'] === []): ?><div class="text-muted fs-12 mb-2" id="order-lines-empty">No lines yet.</div><?php endif; ?>
    <details class="card mb-2" id="order-lines-add"<?= $o['lines'] === [] ? ' open' : '' ?>><summary class="card-body py-2 fw-semibold d-flex">Add a line</summary>
        <div class="card-body pt-0">
            <form method="post" action="/orders/lines/save.php" hx-post="/orders/lines/save.php" hx-target="#order-lines" hx-swap="outerHTML" id="order-line-add-form">
                <?= csrf_field() ?><input type="hidden" name="order" value="<?= $oid ?>">
                <div class="row g-2">
                    <div class="col-8"><label class="form-label fs-12 text-muted" for="order-line-add-variant">SKU, barcode or variant</label><input type="text" name="variant" id="order-line-add-variant" class="form-control btn-touch" required autocomplete="off" maxlength="60"></div>
                    <div class="col-4"><label class="form-label fs-12 text-muted" for="order-line-add-qty">Qty</label><input type="number" name="qty" id="order-line-add-qty" class="form-control btn-touch" min="1" max="9999" value="1" inputmode="numeric"></div>
                </div>
                <div class="fs-12 text-muted mt-1">It is filled from the recommended place — change it on the line afterwards.</div>
                <div class="mt-2"><button type="submit" class="btn btn-light btn-touch" id="order-line-add-btn">Add the line</button></div>
            </form>
        </div>
    </details>
<?php else: ?>
    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12" id="order-lines-table"><thead class="thead-light"><tr><th>#</th><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit</th><th class="text-end d-none d-md-table-cell">Discount</th><th class="text-end">Total</th><th>Fulfilment</th><th>Status</th><th class="d-none d-md-table-cell">Held · sent</th><th></th></tr></thead>
        <tbody><?php foreach ($o['lines'] as $l): ?><?= view('orders/partials/line-row.php', ['mode' => 'view', 'l' => $l, 'o' => $o, 'seesCost' => $seesCost, 'here' => $here, 'mayCancel' => !empty($mayCancel)]) ?><?php endforeach; ?>
        <?php if ($o['lines'] === []): ?><tr><td colspan="10" class="text-center text-muted py-3">No lines.</td></tr><?php endif; ?></tbody></table>
    </div></div></div>
<?php endif; ?>
</div>
