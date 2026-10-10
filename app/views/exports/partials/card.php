<?php /** One export (`export-card-{export}`): its fields and the CSV / JSON buttons (form posts to /exports/download.php); disabled with the reason below the wall. Data: c, from, to, asOf, sources */
$x = $c['export'];
$id = 'export-card-' . $x;
$disabled = !$c['may'];
?>
<div class="card h-100" id="<?= e($id) ?>">
    <form method="post" action="/exports/download.php" class="card-body d-flex flex-column">
        <?= csrf_field() ?><input type="hidden" name="export" value="<?= e($x) ?>">
        <h5 class="card-title mb-1"><?= e($c['title']) ?> <?php if ($c['accounting']): ?><span class="badge bg-soft-warning text-warning fs-11">carries cost</span><?php endif; ?></h5>
        <div class="fs-11 text-muted mb-1"><code><?= e($c['schema']) ?></code></div>
        <div class="fs-12 mb-2"><?= e($c['about']) ?></div>
        <div class="row g-2 mb-2">
        <?php if (in_array('from', $c['fields'], true)): ?>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="<?= e($id) ?>-field-from">From</label><input type="date" name="from" id="<?= e($id) ?>-field-from" class="form-control btn-touch" value="<?= e($from) ?>" <?= $disabled ? 'disabled' : 'required' ?>></div>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="<?= e($id) ?>-field-to">To</label><input type="date" name="to" id="<?= e($id) ?>-field-to" class="form-control btn-touch" value="<?= e($to) ?>" <?= $disabled ? 'disabled' : 'required' ?>></div>
        <?php endif; ?>
        <?php if (in_array('as_of', $c['fields'], true)): ?>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="<?= e($id) ?>-field-as_of">As of</label><input type="date" name="as_of" id="<?= e($id) ?>-field-as_of" class="form-control btn-touch" value="<?= e($asOf) ?>" <?= $disabled ? 'disabled' : 'required' ?>></div>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="<?= e($id) ?>-field-by">Group by</label><select name="by" id="<?= e($id) ?>-field-by" class="form-select btn-touch" <?= $disabled ? 'disabled' : '' ?>><option value="location">Location</option><option value="brand">Brand</option></select></div>
        <?php endif; ?>
        <?php if (in_array('source', $c['fields'], true)): ?>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="<?= e($id) ?>-field-source">Source</label><select name="source" id="<?= e($id) ?>-field-source" class="form-select btn-touch" <?= $disabled ? 'disabled' : '' ?>><option value="">Every active source</option><?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        </div>
        <?php if ($disabled): ?><div class="alert alert-warning fs-12 py-2 mb-2" id="<?= e($id) ?>-withheld"><i class="feather-lock me-1"></i>cost withheld — the admin or the Buyer exports these</div><?php endif; ?>
        <div class="d-flex gap-2 mt-auto">
            <button type="submit" name="format" value="csv" class="btn btn-light btn-touch" id="<?= e($id) ?>-csv-btn" <?= $disabled ? 'disabled' : '' ?>><i class="feather-download me-1"></i>CSV</button>
            <button type="submit" name="format" value="json" class="btn btn-light btn-touch" id="<?= e($id) ?>-json-btn" <?= $disabled ? 'disabled' : '' ?>><i class="feather-code me-1"></i>JSON</button>
        </div>
    </form>
</div>
