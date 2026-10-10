<?php /** The inline watch form (`watch-form`). Data: target, kinds, default, prefill, agents (null unless watches.all), returnTo */
$tf = ['variant' => 'variant', 'listing_variant' => 'listing_variant', 'product' => 'product'][$target['kind']];
?>
<form method="post" action="/watches/save.php" hx-post="/watches/save.php" hx-target="#flash" class="border rounded p-2 mt-2 fs-12" id="watch-form" data-watch-form>
    <?= csrf_field() ?>
    <input type="hidden" name="<?= $tf ?>" value="<?= (int) $target['id'] ?>">
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
    <div class="fw-semibold mb-2">Watch <?= e($target['label']) ?></div>
    <div class="row g-2">
        <div class="col-12 col-sm-6"><label class="form-label mb-1" for="watch-form-field-kind">When</label>
            <select name="kind" class="form-select btn-touch" id="watch-form-field-kind" data-watch-kind>
                <?php foreach ($kinds as $k): ?><option value="<?= $k ?>"<?= $k === $default ? ' selected' : '' ?> data-threshold="<?= in_array($k, WATCH_THRESHOLD_KINDS, true) ? ($k === 'lead_time_over' ? 'days' : 'amount') : '' ?>"><?= e(WATCH_KINDS[$k][0]) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-12 col-sm-6" id="watch-form-threshold-wrap" data-watch-threshold><label class="form-label mb-1" for="watch-form-field-threshold">Threshold</label>
            <input type="number" name="threshold" step="0.01" min="0" class="form-control btn-touch" id="watch-form-field-threshold" inputmode="decimal" data-prefill="<?= e((string) ($prefill ?? '')) ?>"
                   value="<?= in_array($default, WATCH_THRESHOLD_KINDS, true) ? e((string) ($prefill ?? '')) : '' ?>"></div>
        <div class="col-12"><label class="d-flex align-items-center gap-2 btn-touch justify-content-start" for="watch-form-field-text_me"><input type="checkbox" class="form-check-input mt-0" name="text_me" value="1" id="watch-form-field-text_me"> text me when it fires — if your phone is verified in the OS</label></div>
        <?php if ($agents !== null): ?>
        <div class="col-12"><label class="form-label mb-1" for="watch-form-field-agent">Hand it to an agent</label>
            <select name="agent" class="form-select btn-touch" id="watch-form-field-agent"><option value="">No agent</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['member_id'] ?>"><?= e($a['display_name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-12"><label class="form-label mb-1" for="watch-form-field-note">Note</label><input type="text" name="note" maxlength="500" class="form-control btn-touch" id="watch-form-field-note"></div>
        <div class="col-12"><button type="submit" class="btn btn-primary btn-touch w-100" id="watch-form-save">Save</button></div>
    </div>
</form>
