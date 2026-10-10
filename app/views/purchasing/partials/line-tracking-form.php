<?php /** The inline tracking form of one line of a sent order (`po-line-{id}-tracking`). Data: l (a po_lines row), o */
$lid = (int) $l['purchase_order_line_id'];
?>
<details class="mb-2" id="po-line-<?= $lid ?>-tracking"><summary class="btn btn-light btn-touch w-100 text-start" id="po-line-<?= $lid ?>-tracking-open">Add tracking</summary>
    <form method="post" action="/purchasing/tracking.php" hx-post="/purchasing/tracking.php" hx-target="#flash" class="pt-2" id="po-line-<?= $lid ?>-tracking-form">
        <?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>">
        <div class="row g-2">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="po-line-<?= $lid ?>-carrier">Carrier</label><input type="text" name="carrier" id="po-line-<?= $lid ?>-carrier" class="form-control btn-touch" maxlength="60"></div>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="po-line-<?= $lid ?>-tracking-number">Tracking number</label><input type="text" name="tracking" id="po-line-<?= $lid ?>-tracking-number" class="form-control btn-touch" maxlength="100" required></div>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="po-line-<?= $lid ?>-shipped">Shipped on</label><input type="datetime-local" name="shipped_at" id="po-line-<?= $lid ?>-shipped" class="form-control btn-touch"></div>
        </div>
        <button type="submit" class="btn btn-light btn-touch mt-2 w-100" id="po-line-<?= $lid ?>-tracking-btn">Record the tracking</button></form></details>
