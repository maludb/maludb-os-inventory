<?php /** The availability partial, compact shape — the order form's picker per line. Data: a, vid, qty, field, slug, choices, checked, locations, store, atp, seesCost */ ?>
<div id="availability-<?= $vid ?>" class="fs-12">
<?php if (isset($a['components'])): ?>
    <div class="text-muted" id="<?= e($slug) ?>-bundle">Sold as its components — add them as lines</div>
<?php else: ?>
    <fieldset id="<?= e($slug) ?>-choices"><legend class="fs-12 fw-semibold mb-1">Fulfilment</legend>
    <?php foreach ($choices as $c): $cid = $slug . '-choice-' . $c['id_suffix']; ?>
        <div class="form-check d-flex align-items-center gap-2 btn-touch justify-content-start">
            <input class="form-check-input mt-0" type="radio" name="<?= e($field) ?>[fulfilment]" id="<?= e($cid) ?>" value="<?= e($c['value']) ?>"<?= $c['value'] === $checked ? ' checked' : '' ?>>
            <label class="form-check-label" for="<?= e($cid) ?>"><?= e($c['label']) ?><?= $seesCost && $c['cost'] !== null ? ' · cost ' . money((string) $c['cost']) : '' ?><?= $c['stale'] ? ' <span class="badge bg-soft-warning text-warning">stale</span>' : '' ?></label>
            <?php if ($c['kind'] === 'backorder'): $def = $store; if ($def === null) { foreach ($locations as $l) { if ($l['kind'] === 'warehouse') { $def = (int) $l['location_id']; break; } } $def ??= (int) ($locations[0]['location_id'] ?? 0); } ?>
                <select class="form-select form-select-sm w-auto" name="<?= e($field) ?>[backorder_location]" id="<?= e($slug) ?>-backorder-location" aria-label="Ship a back order from">
                    <?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>"<?= (int) $l['location_id'] === $def ? ' selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </fieldset>
    <?= view('find/partials/atp.php', ['atp' => $atp, 'vid' => $vid, 'seesCost' => $seesCost]) ?>
<?php endif; ?>
</div>
