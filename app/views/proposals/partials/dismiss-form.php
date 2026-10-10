<?php /** The inline dismiss form (opens in place under its button). Data: id */ ?>
<form method="post" action="/proposals/dismiss.php" hx-post="/proposals/dismiss.php" hx-target="#flash" id="proposal-card-<?= (int) $id ?>-dismiss-form" class="mt-2"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= (int) $id ?>">
    <label class="form-label fs-12 text-muted" for="proposal-card-<?= (int) $id ?>-dismiss-reason">Why (it is not proposed again for 30 days)</label>
    <input type="text" name="reason" id="proposal-card-<?= (int) $id ?>-dismiss-reason" class="form-control btn-touch" maxlength="500">
    <button type="submit" class="btn btn-light-danger btn-touch mt-2" id="proposal-card-<?= (int) $id ?>-dismiss-submit">Dismiss</button></form>
