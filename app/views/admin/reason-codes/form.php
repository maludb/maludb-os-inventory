<?php /** Add or change a reason code (screens `reason-code-add`, `reason-code-edit`; the form `reason-code-form`). Data: cur */
$id = $cur['reason_code_id'] ?? null;
$screen = $cur === null ? 'reason-code-add' : 'reason-code-edit';
$back = '/admin/reason-codes/';
$v = static fn (string $k, $d = '') => e($cur[$k] ?? $d);
$applies = $cur['applies_to'] ?? ['adjustment'];
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a reason code' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Reason codes', $back], [$cur === null ? 'New' : $cur['name'], null]], 'back' => back_link() ?? [$back, 'Reason codes']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/admin/reason-codes/save.php" hx-post="/admin/reason-codes/save.php" hx-target="#flash" id="reason-code-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="reason" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="page-header-form d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom" id="reason-code-form-header">
            <div class="fw-semibold"><?= $cur === null ? 'A new reason code' : e($cur['name']) ?></div>
            <div class="d-flex gap-2"><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="reason-code-form-cancel-btn"') ?><button type="submit" class="btn btn-primary btn-touch" id="reason-code-form-save-btn">Save</button></div>
        </div>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="reason-code-form-field-name">Name</label><input type="text" name="name" id="reason-code-form-field-name" class="form-control btn-touch" maxlength="80" required placeholder="Water damage" value="<?= $v('name') ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="reason-code-form-field-code">Code</label>
                <?php if ($cur === null): ?><input type="text" name="code" id="reason-code-form-field-code" class="form-control btn-touch" maxlength="40" pattern="[a-z][a-z0-9_]{0,39}" placeholder="water_damage" value=""><div class="fs-11 text-muted mt-1">Lower-case letters, digits and "_". Blank: made from the name. It cannot change later.</div>
                <?php else: ?><input type="text" id="reason-code-form-field-code" class="form-control btn-touch" value="<?= $v('code') ?>" readonly aria-readonly="true"><div class="fs-11 text-muted mt-1">The code is fixed — the system looks some of them up by name.</div><?php endif; ?></div>
            <div class="col-12"><div class="fs-12 text-muted mb-1">Applies to</div><input type="hidden" name="applies_to[]" value="">
                <div class="d-flex flex-wrap gap-2" id="reason-code-form-field-applies_to">
                    <?php foreach (['adjustment', 'return'] as $a): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch"><input type="checkbox" class="form-check-input mt-0" name="applies_to[]" value="<?= e($a) ?>" <?= in_array($a, $applies, true) ? 'checked' : '' ?>><?= e($a) ?></label><?php endforeach; ?>
                    <?php if (in_array('transaction', $applies, true)): ?><input type="hidden" name="applies_to[]" value="transaction"><?php endif; ?>
                </div></div>
            <div class="col-12 col-md-6">
                <input type="hidden" name="affects_qty" value="no">
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="reason-code-form-field-affects_qty"><input type="checkbox" class="form-check-input mt-0" name="affects_qty" value="yes" id="reason-code-form-field-affects_qty" <?= ($cur['affects_qty'] ?? true) ? 'checked' : '' ?>>Moves quantity</label>
                <div class="fs-11 text-muted mt-1">Off: a flag only, like a floor model.</div>
            </div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="reason-code-form-field-sort_order">Sort order</label><input type="number" name="sort_order" id="reason-code-form-field-sort_order" class="form-control btn-touch" min="0" max="999" value="<?= $v('sort_order', 0) ?>"></div>
            <div class="col-6 col-md-3">
                <input type="hidden" name="active" value="no">
                <label class="form-label fs-12 text-muted d-block">&nbsp;</label>
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="reason-code-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="reason-code-form-field-active" <?= ($cur['active'] ?? true) ? 'checked' : '' ?>>Active</label>
            </div>
        </div>
    </form>
</div>
