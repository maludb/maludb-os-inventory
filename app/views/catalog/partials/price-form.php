<?php /** One inline price form (`price-form-{kind}`): kind + price + reason → price_set. Data: kind, vid, current */ ?>
<form method="post" action="/variants/price.php" hx-post="/variants/price.php" hx-target="#flash" class="row g-1 align-items-end mb-2 price-inline-form" id="price-form-<?= e($kind) ?>">
    <?= csrf_field() ?><input type="hidden" name="variant" value="<?= (int) $vid ?>"><input type="hidden" name="kind" value="<?= e($kind) ?>">
    <div class="col-4"><label class="form-label fs-12 text-muted" for="price-form-<?= e($kind) ?>-field-price">Set <?= e(PRICE_KINDS[$kind]) ?></label><input type="number" step="0.01" min="0" name="price" id="price-form-<?= e($kind) ?>-field-price" class="form-control btn-touch" required value="<?= e((string) ($current ?? '')) ?>"></div>
    <div class="col-5"><label class="form-label fs-12 text-muted" for="price-form-<?= e($kind) ?>-field-reason">Reason</label><input type="text" name="reason" id="price-form-<?= e($kind) ?>-field-reason" class="form-control btn-touch" maxlength="200" placeholder="sale event, new cost sheet…"></div>
    <div class="col-3"><button type="submit" class="btn btn-light btn-touch w-100" id="price-form-<?= e($kind) ?>-save-btn">Set</button></div>
</form>
