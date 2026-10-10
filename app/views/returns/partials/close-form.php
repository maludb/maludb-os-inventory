<?php /** The inline close form of a received return: the refund and the restocking fee RECORDED (nothing is paid here). Data: r */
$lineTotal = 0;
?>
<div class="card-body pt-0">
    <form method="post" action="/returns/close.php" hx-post="/returns/close.php" hx-target="#flash" id="return-close-form" hx-confirm="<?= e('Close ' . $r['number'] . '?') ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= (int) $r['return_id'] ?>">
        <div class="row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="return-close-field-refund_amount">Refund to give back (recorded here; the money is recorded on the order)</label>
                <input type="text" name="refund_amount" id="return-close-field-refund_amount" class="form-control btn-touch" inputmode="decimal" value="<?= e(number_format((float) $r['refund_amount'], 2, '.', '')) ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="return-close-field-restocking_fee">Restocking fee kept</label>
                <input type="text" name="restocking_fee" id="return-close-field-restocking_fee" class="form-control btn-touch" inputmode="decimal" value="<?= e(number_format((float) $r['restocking_fee'], 2, '.', '')) ?>"></div>
        </div>
        <div class="fs-12 text-muted mt-1">The order has been paid <?= money($r['amount_paid']) ?>; a refund is never more.</div>
        <button type="submit" class="btn btn-primary btn-touch mt-2" id="return-close-submit">Close the return</button></form>
</div>
