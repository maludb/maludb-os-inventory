<?php /** Make or change a customer (screens `customer-add`, `customer-edit`; the form `customer-form`). Data: cur, rates */
$id = $cur['customer_id'] ?? null;
$screen = $cur === null ? 'customer-add' : 'customer-edit';
$back = $id === null ? '/customers/' : '/customers/' . (int) $id;
$v = static fn (string $k, $d = '') => e($cur[$k] ?? $d);
$fld = static function (string $name, string $label, string $html): string {
    return '<div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="customer-form-field-' . $name . '">' . e($label) . '</label>' . $html . '</div>';
};
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New customer' : 'Change ' . $cur['name'], 'crumbs' => array_merge([['Home', '/'], ['Customers', '/customers/']], $cur === null ? [['New', null]] : [[$cur['name'], $back], ['Edit', null]]),
    'back' => back_link() ?? [$back, $cur === null ? 'Customers' : $cur['name']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/customers/save.php" hx-post="/customers/save.php" hx-target="#flash" id="customer-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="customer" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card-body row g-2">
            <?= $fld('name', 'Name', '<input type="text" name="name" id="customer-form-field-name" class="form-control btn-touch" maxlength="200" required value="' . $v('name') . '">') ?>
            <?= $fld('legal_name', 'Legal name', '<input type="text" name="legal_name" id="customer-form-field-legal_name" class="form-control btn-touch" maxlength="200" value="' . $v('legal_name') . '">') ?>
            <?= $fld('email', 'Email', '<input type="email" name="email" id="customer-form-field-email" class="form-control btn-touch" maxlength="200" autocomplete="off" value="' . $v('email') . '">') ?>
            <?= $fld('phone', 'Phone', '<input type="tel" name="phone" id="customer-form-field-phone" class="form-control btn-touch" maxlength="40" value="' . $v('phone') . '">') ?>
            <?= $fld('phone_alt', 'Second phone', '<input type="tel" name="phone_alt" id="customer-form-field-phone_alt" class="form-control btn-touch" maxlength="40" value="' . $v('phone_alt') . '">') ?>
            <?= $fld('source', 'How they came', '<select name="source" id="customer-form-field-source" class="form-select btn-touch">' . implode('', array_map(static fn ($k, $w) => '<option value="' . $k . '"' . (($cur['source'] ?? 'walk_in') === $k ? ' selected' : '') . '>' . e($w) . '</option>', array_keys(CUSTOMER_SOURCES), CUSTOMER_SOURCES)) . '</select>') ?>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="customer-form-field-billing_address">Billing address</label><textarea name="billing_address" id="customer-form-field-billing_address" class="form-control" rows="3" maxlength="500"><?= $v('billing_address') ?></textarea></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="customer-form-field-shipping_address">Shipping address</label><textarea name="shipping_address" id="customer-form-field-shipping_address" class="form-control" rows="3" maxlength="500"><?= $v('shipping_address') ?></textarea></div>
            <?= $fld('tax_rate', 'Tax rate', '<select name="tax_rate" id="customer-form-field-tax_rate" class="form-select btn-touch"><option value="">— the default —</option>' . implode('', array_map(static fn ($r) => '<option value="' . (int) $r['tax_rate_id'] . '"' . ((int) ($cur['tax_rate_id'] ?? 0) === (int) $r['tax_rate_id'] ? ' selected' : '') . '>' . e($r['name'] . ' (' . rtrim(rtrim((string) $r['rate'], '0'), '.') . '%)') . '</option>', $rates)) . '</select>') ?>
            <?= $fld('terms_days', 'Payment terms (days)', '<input type="number" name="terms_days" id="customer-form-field-terms_days" class="form-control btn-touch" min="0" max="365" inputmode="numeric" value="' . $v('terms_days') . '">') ?>
            <?= $fld('tax_id', 'Tax id', '<input type="text" name="tax_id" id="customer-form-field-tax_id" class="form-control btn-touch" maxlength="40" value="' . $v('tax_id') . '">') ?>
            <?php if (!empty($cur['income_account_id'])): ?><div class="col-12 col-md-6"><label class="form-label fs-12 text-muted">Ledger account (set by the ledger)</label><div class="form-control btn-touch bg-light" id="customer-form-income-account"><?= (int) $cur['income_account_id'] ?></div></div><?php endif; ?>
            <div class="col-12">
                <input type="hidden" name="email_opt_in" value="no">
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="customer-form-field-email_opt_in"><input type="checkbox" class="form-check-input mt-0" name="email_opt_in" value="yes" id="customer-form-field-email_opt_in" <?= !empty($cur['email_opt_in']) ? 'checked' : '' ?>>They agreed to hear from us by email</label>
            </div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="customer-form-field-notes">Notes</label><textarea name="notes" id="customer-form-field-notes" class="form-control" rows="3" maxlength="2000"><?= $v('notes') ?></textarea></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="customer-form-save-btn"><?= $cur === null ? 'Make the customer' : 'Save' ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="customer-form-cancel-link"') ?></div>
    </form>
</div>
