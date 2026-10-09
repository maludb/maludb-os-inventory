<?php /** Start a count (screen `count-add`; `count-form`). Data: locations, locationId, open */ ?>
<?= view('shared/header.php', ['id' => 'count-add', 'title' => 'Start a count', 'crumbs' => [['Home', '/'], ['Counts', '/counts/'], ['New', null]], 'back' => back_link() ?? ['/counts/', 'Counts']]) ?>
<div class="main-content" id="count-add-content">
    <form method="post" action="/counts/start.php" hx-post="/counts/start.php" hx-target="#flash" id="count-form" class="card">
        <?= csrf_field() ?>
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="count-form-field-location">Where</label>
            <select name="location" id="count-form-field-location" class="form-select btn-touch" required><option value="">Choose…</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $locationId === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted mt-3" for="count-form-field-notes">Notes</label>
            <textarea name="notes" id="count-form-field-notes" class="form-control" rows="2" maxlength="2000"></textarea>
            <div class="fs-12 text-muted mt-2">The count lists every variant held there with the quantity the system has now. Corrections are counted against on hand at posting, so a receipt posted while you count is never counted twice.</div>
            <?php if ($open !== []): ?><div class="fs-12 mt-2" id="count-form-open">Open now: <?php foreach ($open as $o): ?><?= hx_link('/counts/' . (int) $o['count_id'], e($o['number'] . ' at ' . $o['location_name'])) ?> <?php endforeach; ?></div><?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="count-form-save-btn">Start</button><?= hx_link('/counts/', 'Cancel', 'btn btn-light btn-touch', 'id="count-form-cancel-link"') ?></div>
    </form>
</div>
