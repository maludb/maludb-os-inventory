<?php /** The inline deny form of a return (opens in place under its button). Data: r */ ?>
<div class="card-body pt-0">
    <form method="post" action="/returns/deny.php" hx-post="/returns/deny.php" hx-target="#flash" id="return-deny-form" hx-confirm="<?= e('Deny ' . $r['number'] . '?') ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= (int) $r['return_id'] ?>">
        <label class="form-label fs-12 text-muted" for="return-deny-field-reason">Why</label><input type="text" name="reason" id="return-deny-field-reason" class="form-control btn-touch" maxlength="500" required>
        <button type="submit" class="btn btn-light-danger btn-touch mt-2" id="return-deny-submit">Deny the return</button></form>
</div>
