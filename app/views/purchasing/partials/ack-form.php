<?php /** The inline acknowledge form (`po-ack-form`) of a sent order. Data: o */
$oid = (int) $o['purchase_order_id'];
$open = array_values(array_filter($o['lines'], static fn (array $l): bool => $l['status'] === 'open'));
?>
<details class="card mb-3" id="po-ack"><summary class="card-body py-2 fw-semibold d-flex" id="po-ack-open">Record the supplier's acknowledgment</summary><div class="card-body pt-0">
    <form method="post" action="/purchasing/acknowledge.php" hx-post="/purchasing/acknowledge.php" hx-target="#flash" id="po-ack-form"><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $oid ?>">
        <div class="row g-2">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="po-ack-ref">Their reference</label><input type="text" name="supplier_ref" id="po-ack-ref" class="form-control btn-touch" maxlength="100"></div>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="po-ack-expected">Expected</label><input type="date" name="expected_on" id="po-ack-expected" class="form-control btn-touch"></div>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="po-ack-line">What</label><select name="line" id="po-ack-line" class="form-select btn-touch"><option value="">The whole order</option><?php foreach ($open as $l): ?><option value="<?= (int) $l['purchase_order_line_id'] ?>">Line <?= (int) $l['line_no'] ?> — <?= e($l['sku']) ?></option><?php endforeach; ?></select></div>
        </div>
        <button type="submit" class="btn btn-light btn-touch mt-2" id="po-ack-btn">Record it</button></form></div></details>
