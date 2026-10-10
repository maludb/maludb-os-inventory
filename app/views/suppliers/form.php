<?php /** Make or change a supplier (screens `supplier-add`, `supplier-edit`; the form `supplier-form`). Data: cur, seesAccount */
$id = $cur['supplier_id'] ?? null;
$screen = $cur === null ? 'supplier-add' : 'supplier-edit';
$back = $id === null ? '/suppliers/' : '/suppliers/' . (int) $id;
$v = static fn (string $k, $d = '') => e($cur[$k] ?? $d);
$fld = static function (string $name, string $label, string $html, string $cls = 'col-12 col-md-6'): string {
    return '<div class="' . $cls . '"><label class="form-label fs-12 text-muted" for="supplier-form-field-' . $name . '">' . e($label) . '</label>' . $html . '</div>';
};
$kind = $cur['kind'] ?? 'vendor';
$kinds = SUPPLIER_KINDS + (isset(SUPPLIER_KINDS[$kind]) ? [] : [$kind => supplier_kind_word($kind)]);
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New supplier' : 'Change ' . $cur['name'], 'crumbs' => array_merge([['Home', '/'], ['Suppliers', '/suppliers/']], $cur === null ? [['New', null]] : [[$cur['name'], $back], ['Edit', null]]),
    'back' => back_link() ?? [$back, $cur === null ? 'Suppliers' : $cur['name']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/suppliers/save.php" hx-post="/suppliers/save.php" hx-target="#flash" id="supplier-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="supplier" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card-body row g-2">
            <?= $fld('name', 'Name', '<input type="text" name="name" id="supplier-form-field-name" class="form-control btn-touch" maxlength="200" required value="' . $v('name') . '">') ?>
            <?= $fld('kind', 'Kind', '<select name="kind" id="supplier-form-field-kind" class="form-select btn-touch">' . implode('', array_map(static fn ($k, $w) => '<option value="' . e($k) . '"' . ($kind === $k ? ' selected' : '') . '>' . e($w) . '</option>', array_keys($kinds), $kinds)) . '</select>') ?>
            <?= $fld('contact_name', 'Contact', '<input type="text" name="contact_name" id="supplier-form-field-contact_name" class="form-control btn-touch" maxlength="120" value="' . $v('contact_name') . '">') ?>
            <?= $fld('email', 'Email', '<input type="email" name="email" id="supplier-form-field-email" class="form-control btn-touch" maxlength="200" autocomplete="off" value="' . $v('email') . '">') ?>
            <?= $fld('phone', 'Phone', '<input type="tel" name="phone" id="supplier-form-field-phone" class="form-control btn-touch" maxlength="40" value="' . $v('phone') . '">') ?>
            <?= $fld('website', 'Website', '<input type="url" name="website" id="supplier-form-field-website" class="form-control btn-touch" maxlength="300" placeholder="https://" value="' . $v('website') . '">') ?>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="supplier-form-field-address">Address</label><textarea name="address" id="supplier-form-field-address" class="form-control" rows="2" maxlength="500"><?= $v('address') ?></textarea></div>
            <?php if ($seesAccount): ?><?= $fld('account_number', 'Our account number with them', '<input type="text" name="account_number" id="supplier-form-field-account_number" class="form-control btn-touch" maxlength="60" autocomplete="off" value="' . $v('account_number') . '">') ?><?php endif; ?>
            <?= $fld('terms', 'Terms', '<input type="text" name="terms" id="supplier-form-field-terms" class="form-control btn-touch" maxlength="60" placeholder="Net 30" value="' . $v('terms') . '">') ?>
            <?= $fld('lead_time_days', 'Lead time (days)', '<input type="number" name="lead_time_days" id="supplier-form-field-lead_time_days" class="form-control btn-touch" min="0" max="365" inputmode="numeric" value="' . $v('lead_time_days') . '">') ?>
            <?= $fld('min_order', 'Minimum order', '<input type="text" name="min_order" id="supplier-form-field-min_order" class="form-control btn-touch" inputmode="decimal" value="' . $v('min_order') . '">') ?>
            <?= $fld('order_method', 'How we order', '<select name="order_method" id="supplier-form-field-order_method" class="form-select btn-touch">' . implode('', array_map(static fn ($k, $w) => '<option value="' . e($k) . '"' . (($cur['order_method'] ?? 'email') === $k ? ' selected' : '') . '>' . e($w) . '</option>', array_keys(SUPPLIER_ORDER_METHODS), SUPPLIER_ORDER_METHODS)) . '</select>') ?>
            <?= $fld('order_email', 'Order email', '<input type="email" name="order_email" id="supplier-form-field-order_email" class="form-control btn-touch" maxlength="200" autocomplete="off" value="' . $v('order_email') . '">') ?>
            <?= $fld('portal_url', "The supplier's portal", '<input type="url" name="portal_url" id="supplier-form-field-portal_url" class="form-control btn-touch" maxlength="300" placeholder="https://" value="' . $v('portal_url') . '">') ?>
            <div class="col-12">
                <input type="hidden" name="dropships" value="no">
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="supplier-form-field-dropships"><input type="checkbox" class="form-check-input mt-0" name="dropships" value="yes" id="supplier-form-field-dropships" <?= !empty($cur['dropships']) ? 'checked' : '' ?>>They ship to our customers (drop-ship)</label>
            </div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="supplier-form-field-notes">Notes</label><textarea name="notes" id="supplier-form-field-notes" class="form-control" rows="3" maxlength="2000"><?= $v('notes') ?></textarea></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="supplier-form-save-btn"><?= $cur === null ? 'Make the supplier' : 'Save' ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="supplier-form-cancel-link"') ?></div>
    </form>
</div>
