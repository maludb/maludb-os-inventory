<?php
/** The filter form of a report (`report-form`): the fields the report takes, Run (HTMX into #report-results) and CSV (a form post). Data: report, spec, params, errors, lists */
$f = $spec['fields'];
$v = static fn (string $k, $d = '') => e($params[$k] ?? $d);
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="text-danger fs-12 mt-1" id="report-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$byLabels = ['location' => 'Location', 'brand' => 'Brand', 'type' => 'Product type', 'variant' => 'Variant', 'salesperson' => 'Salesperson', 'day' => 'Day', 'week' => 'Week', 'month' => 'Month'];
?>
<form method="post" action="/reports/run.php" class="card" id="report-form">
    <?= csrf_field() ?><input type="hidden" name="report" value="<?= e($report) ?>">
    <div class="card-body row g-2 align-items-end">
        <?php if (in_array('as_of', $f, true)): ?>
        <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="report-form-field-as_of">As of (blank = now)</label><input type="datetime-local" name="as_of" id="report-form-field-as_of" class="form-control btn-touch" value="<?= $v('as_of') ?>"><?= $err('as_of') ?></div>
        <?php endif; ?>
        <?php if (in_array('days', $f, true)): ?>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-form-field-days"><?= $report === 'source-health' ? 'Reliability window (days)' : 'Days' ?></label><input type="number" min="1" max="365" name="days" id="report-form-field-days" class="form-control btn-touch" value="<?= $v('days', 30) ?>"><?= $err('days') ?></div>
        <?php endif; ?>
        <?php if (in_array('from', $f, true)): ?>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-form-field-from">From</label><input type="date" name="from" id="report-form-field-from" class="form-control btn-touch" value="<?= $v('from') ?>"><?= $err('from') ?></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-form-field-to">To</label><input type="date" name="to" id="report-form-field-to" class="form-control btn-touch" value="<?= $v('to') ?>"><?= $err('to') ?></div>
        <?php endif; ?>
        <?php if ($spec['bys'] !== []): ?>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-form-field-by">Group by</label><select name="by" id="report-form-field-by" class="form-select btn-touch"><?php foreach ($spec['bys'] as $b): ?><option value="<?= e($b) ?>" <?= ($params['by'] ?? '') === $b ? 'selected' : '' ?>><?= e($byLabels[$b] ?? ucfirst($b)) ?></option><?php endforeach; ?></select><?= $err('by') ?></div>
        <?php endif; ?>
        <?php if (in_array('kind', $f, true)): ?>
        <div class="col-12 col-md-5"><div class="fs-12 text-muted mb-1">Kinds (none = all)</div><div class="d-flex flex-wrap gap-2" id="report-form-field-kind">
            <?php foreach (['retail_under_map' => 'Retail under MAP', 'reference_undercut' => 'Reference undercut', 'cost_moved' => 'Cost moved'] as $k => $l): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch"><input type="checkbox" class="form-check-input mt-0" name="kind[]" value="<?= e($k) ?>" <?= in_array($k, (array) ($params['kind'] ?? []), true) ? 'checked' : '' ?>><?= e($l) ?></label>
            <?php endforeach; ?></div><?= $err('kind') ?></div>
        <?php endif; ?>
        <?php if (in_array('brand', $f, true)): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="report-form-field-brand">Brand</label><select name="brand" id="report-form-field-brand" class="form-select btn-touch"><option value="">Every brand</option><?php foreach ($lists['brands'] as $b): ?><option value="<?= (int) $b['brand_id'] ?>" <?= (int) ($params['brand'] ?? 0) === (int) $b['brand_id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select><?= $err('brand') ?></div>
        <?php endif; ?>
        <?php if (in_array('health', $f, true)): ?>
        <div class="col-12 col-md-7"><div class="fs-12 text-muted mb-1">Health (none = all)</div><div class="d-flex flex-wrap gap-2" id="report-form-field-health">
            <?php foreach (REPORT_HEALTHS as $h): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch"><input type="checkbox" class="form-check-input mt-0" name="health[]" value="<?= e($h) ?>" <?= in_array($h, (array) ($params['health'] ?? []), true) ? 'checked' : '' ?>><?= e(str_replace('_', ' ', $h)) ?></label>
            <?php endforeach; ?></div><?= $err('health') ?></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="report-form-field-role">Role</label><select name="role" id="report-form-field-role" class="form-select btn-touch"><option value="">Every role</option><?php foreach (['supplier', 'reference', 'own'] as $r): ?><option <?= ($params['role'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select><?= $err('role') ?></div>
        <?php endif; ?>
        <?php if (in_array('supplier', $f, true)): ?>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="report-form-field-supplier">Supplier</label><select name="supplier" id="report-form-field-supplier" class="form-select btn-touch"><option value="">Every supplier</option><?php foreach ($lists['suppliers'] as $s): ?><option value="<?= (int) $s['supplier_id'] ?>" <?= (int) ($params['supplier'] ?? 0) === (int) $s['supplier_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?= $err('supplier') ?></div>
        <?php endif; ?>
        <div class="col-12 col-md-auto d-flex gap-2">
            <button type="submit" name="format" value="html" class="btn btn-primary btn-touch" id="report-form-run-btn" hx-post="/reports/run.php" hx-include="#report-form" hx-vals='{"format":"html"}' hx-target="#report-results" hx-swap="outerHTML" data-bs-dismiss="offcanvas">Run</button>
            <button type="submit" name="format" value="csv" class="btn btn-light btn-touch" id="report-form-csv-btn"><i class="feather-download me-1"></i>CSV</button>
        </div>
    </div>
</form>
