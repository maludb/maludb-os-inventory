<?php
/**
 * The lines region (`po-lines`). Data: o (find_purchase_order), editable (a draft's edit form: each line its own form, an add row), seesCost, form (true on the edit form: refreshes itself on purchaseOrderChanged), may, here
 * Read-only (the order's page): a table, and under it — for a sent order — a card per live line with the Decline and Tracking forms.
 */
$poid = (int) $o['purchase_order_id'];
$here = $here ?? '/purchasing/' . $poid;
$refresh = !empty($form) ? ' hx-get="/purchasing/' . $poid . '/lines" hx-trigger="purchaseOrderChanged from:body" hx-swap="outerHTML"' : '';
$sent = in_array($o['status'], PO_SENT_STATUSES, true);
?>
<div id="po-lines"<?= $refresh ?>>
<?php if (!empty($editable)): ?>
    <?php foreach ($o['lines'] as $l): ?><?= view('purchasing/partials/line-row.php', ['mode' => 'saved', 'l' => $l, 'o' => $o, 'seesCost' => $seesCost]) ?><?php endforeach; ?>
    <?php if ($o['lines'] === []): ?><div class="text-muted fs-12 mb-2" id="po-lines-empty">No lines yet.</div><?php endif; ?>
    <?php if ($o['kind'] === 'stock'): ?>
    <details class="card mb-2" id="po-lines-add"<?= $o['lines'] === [] ? ' open' : '' ?>><summary class="card-body py-2 fw-semibold d-flex">Add a line</summary>
        <div class="card-body pt-0">
            <form method="post" action="/purchasing/lines/save.php" hx-post="/purchasing/lines/save.php" hx-target="#po-lines" hx-swap="outerHTML" id="po-line-add-form">
                <?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poid ?>">
                <div class="row g-2">
                    <div class="col-8"><label class="form-label fs-12 text-muted" for="po-line-add-variant">SKU, barcode or variant</label><input type="text" name="variant" id="po-line-add-variant" class="form-control btn-touch" required autocomplete="off" maxlength="60"></div>
                    <div class="col-4"><label class="form-label fs-12 text-muted" for="po-line-add-qty">Qty</label><input type="number" name="qty" id="po-line-add-qty" class="form-control btn-touch" min="1" max="99999" value="1" inputmode="numeric"></div>
                </div>
                <div class="fs-12 text-muted mt-1">The cost and the supplier's SKU come from their price sheet or their offer — change them on the line afterwards.</div>
                <div class="mt-2"><button type="submit" class="btn btn-light btn-touch" id="po-line-add-btn">Add the line</button></div>
            </form>
        </div>
    </details>
    <?php else: ?><div class="fs-12 text-muted mb-2" id="po-lines-dropship-note">A drop-ship's lines are the customer's: their variant and quantity do not change here, their cost and expected date may.</div><?php endif; ?>
<?php else: ?>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12" id="po-lines-table"><thead class="thead-light"><tr><th>#</th><th>Item</th><th class="d-none d-md-table-cell">Their SKU</th><th class="text-end">Ordered</th><th class="text-end">Received</th><?php if ($seesCost): ?><th class="text-end">Cost</th><th class="text-end d-none d-md-table-cell">Line</th><?php endif; ?><th>Expected</th><th>Status</th><th>Tracking</th><th class="d-none d-lg-table-cell">Offer</th><th class="d-none d-lg-table-cell">Customer's line</th></tr></thead>
        <tbody><?php foreach ($o['lines'] as $l): ?><?= view('purchasing/partials/line-row.php', ['mode' => 'view', 'l' => $l, 'o' => $o, 'seesCost' => $seesCost, 'here' => $here]) ?><?php endforeach; ?>
        <?php if ($o['lines'] === []): ?><tr><td colspan="12" class="text-center text-muted py-3">No lines.</td></tr><?php endif; ?></tbody></table>
    </div></div></div>
    <?php if ($sent && !empty($may['write'])): ?>
        <?php foreach ($o['lines'] as $l): if (in_array($l['status'], ['declined', 'cancelled', 'received', 'closed_short'], true)) { continue; } ?>
        <div class="card mb-2" id="po-line-<?= (int) $l['purchase_order_line_id'] ?>-actions"><div class="card-body p-2">
            <div class="fs-12 fw-semibold mb-2">Line <?= (int) $l['line_no'] ?> — <?= e($l['sku']) ?> × <?= (int) $l['qty_ordered'] ?> <?= po_line_status_chip($l['status']) ?></div>
            <?php if (in_array($l['status'], ['open', 'acknowledged'], true)): ?><?= view('purchasing/partials/line-decline-form.php', ['l' => $l, 'o' => $o]) ?><?php endif; ?>
            <?= view('purchasing/partials/line-tracking-form.php', ['l' => $l, 'o' => $o]) ?>
        </div></div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
</div>
