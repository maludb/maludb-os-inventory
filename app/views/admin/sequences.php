<?php /** The document sequences (screen `sequence-list`, `sequence-list-table`, `sequence-row-{kind}`): an inline form per row — table at 992 px and over, cards under. Data: rows, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'sequence-list', 'title' => 'Sequences', 'crumbs' => [['Home', '/'], ['Sequences', null]], 'back' => back_link()]) ?>
<div class="main-content" id="sequence-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2">Each kind of document is numbered by its prefix, the next value and the padding. A number is never reused — raising the next value leaves a hole; it cannot go back.</div>
    <div class="card d-none d-lg-block"><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12" id="sequence-list-table">
            <thead class="thead-light"><tr><th>Kind</th><th>Prefix</th><th>Next value</th><th>Padding</th><th>Next number</th><th>Updated</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $k = $r['kind']; $f = 'sequence-row-' . $k . '-form'; ?>
                <tr id="sequence-row-<?= e($k) ?>">
                    <td class="fw-semibold"><?= e($r['label']) ?><div class="fs-11 text-muted"><code><?= e($k) ?></code></div></td>
                    <td><input form="<?= e($f) ?>" type="text" name="prefix" class="form-control form-control-sm" style="width:6rem" maxlength="9" value="<?= e($r['prefix']) ?>" aria-label="Prefix of <?= e($r['label']) ?>"></td>
                    <td><input form="<?= e($f) ?>" type="number" name="next_value" class="form-control form-control-sm" style="width:9rem" min="<?= (int) $r['next_value'] ?>" value="<?= (int) $r['next_value'] ?>" aria-label="Next value of <?= e($r['label']) ?>"></td>
                    <td><input form="<?= e($f) ?>" type="number" name="padding" class="form-control form-control-sm" style="width:5rem" min="1" max="12" value="<?= (int) $r['padding'] ?>" aria-label="Padding of <?= e($r['label']) ?>"></td>
                    <td><code><?= e($r['next_number']) ?></code></td>
                    <td class="text-muted"><?= e(format_ts($r['updated_at'], $tz, 'M j, Y')) ?></td>
                    <td class="text-end"><form id="<?= e($f) ?>" method="post" action="/admin/sequences.php" onsubmit="return confirm('A number is never reused — raising the next value leaves a hole. Save?')"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($k) ?>"><button type="submit" class="btn btn-primary btn-touch" id="sequence-row-<?= e($k) ?>-save-btn">Save</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div></div>
    <div class="d-lg-none" id="sequence-list-cards">
    <?php foreach ($rows as $r): $k = $r['kind']; ?>
        <form method="post" action="/admin/sequences.php" class="card mb-2" id="sequence-card-<?= e($k) ?>" onsubmit="return confirm('A number is never reused — raising the next value leaves a hole. Save?')"><div class="card-body">
            <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($k) ?>">
            <div class="d-flex justify-content-between"><div class="fw-semibold"><?= e($r['label']) ?></div><code><?= e($r['next_number']) ?></code></div>
            <div class="row g-2 mt-1">
                <div class="col-4"><label class="form-label fs-11 text-muted">Prefix</label><input type="text" name="prefix" class="form-control btn-touch" maxlength="9" value="<?= e($r['prefix']) ?>"></div>
                <div class="col-5"><label class="form-label fs-11 text-muted">Next value</label><input type="number" name="next_value" class="form-control btn-touch" min="<?= (int) $r['next_value'] ?>" value="<?= (int) $r['next_value'] ?>"></div>
                <div class="col-3"><label class="form-label fs-11 text-muted">Padding</label><input type="number" name="padding" class="form-control btn-touch" min="1" max="12" value="<?= (int) $r['padding'] ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary btn-touch mt-2" id="sequence-card-<?= e($k) ?>-save-btn">Save</button>
        </div></form>
    <?php endforeach; ?>
    </div>
</div>
