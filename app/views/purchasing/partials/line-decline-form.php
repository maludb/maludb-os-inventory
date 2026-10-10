<?php /** The inline decline form of one line of a sent order (`po-line-{id}-decline`). Data: l (a po_lines row), o */
$lid = (int) $l['purchase_order_line_id'];
?>
<details class="mb-2" id="po-line-<?= $lid ?>-decline"><summary class="btn btn-light btn-touch w-100 text-start" id="po-line-<?= $lid ?>-decline-open">Decline this line</summary>
    <form method="post" action="/purchasing/decline.php" hx-post="/purchasing/decline.php" hx-target="#flash" class="pt-2" id="po-line-<?= $lid ?>-decline-form" hx-confirm="<?= e('Decline line ' . $l['line_no'] . ' (' . $l['sku'] . ')? The customer\'s line goes back to open.') ?>">
        <?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>">
        <label class="form-label fs-12 text-muted" for="po-line-<?= $lid ?>-decline-reason">Why the supplier cannot fill it</label>
        <input type="text" name="reason" id="po-line-<?= $lid ?>-decline-reason" class="form-control btn-touch" maxlength="500" required>
        <button type="submit" class="btn btn-light-danger btn-touch mt-2 w-100" id="po-line-<?= $lid ?>-decline-btn">Decline the line</button></form></details>
