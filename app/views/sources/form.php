<?php
/**
 * Add or change a source (screens `source-add`, `source-edit`; the form `source-form`): the common fields and every connector's sub-form
 * (`source-form-{connector}`) rendered server-side; sources.js shows the chosen one — JavaScript off shows them all with the chosen one marked.
 * Data: cur, settings, tpl (the template), prefill, suppliers, defaults, sizes, sftpTool
 */
$id = $cur['source_id'] ?? null;
$screen = $cur === null ? 'source-add' : 'source-edit';
$back = $id === null ? '/sources/' : '/sources/' . (int) $id;
$val = static fn (string $k, $d = '') => $cur[$k] ?? ($prefill[$k] ?? $d);
$connector = (string) $val('connector', 'shopify');
$role = (string) $val('role', 'reference');
$set = static fn (string $conn, string $k, $d = '') => $connector === $conn ? ($settings[$k] ?? $d) : $d;
$fid = static fn (string $conn, string $k): string => 'source-form-field-' . $conn . '-' . $k;
$input = static function (string $conn, string $k, string $label, string $type = 'text', string $value = '', string $extra = '') use ($fid): string {
    return '<div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="' . $fid($conn, $k) . '">' . e($label) . '</label><input type="' . $type . '" name="settings_' . $conn . '[' . $k . ']" id="' . $fid($conn, $k) . '" class="form-control btn-touch" value="' . e($value) . '" ' . $extra . '></div>';
};
$blocked = $tpl !== null && $tpl['connector'] === 'shopify' && $tpl['survey_result'] === 'blocked';
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? ($tpl !== null ? 'New source from ' . $tpl['name'] : 'New source') : 'Change ' . $cur['name'],
    'crumbs' => array_merge([['Home', '/'], ['Sources', '/sources/']], $cur === null ? [['New', null]] : [[$cur['name'], $back], ['Edit', null]]), 'back' => back_link() ?? [$back, $cur === null ? 'Sources' : $cur['name']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?php if ($blocked): ?>
    <div class="alert alert-warning fs-12" id="source-form-blocked-doors">
        <div class="fw-semibold mb-1"><?= e($tpl['name']) ?> answers products.json with a block for a non-browser agent.</div>
        Two doors work: <?= hx_link('/sources/new?connector=jsonld&name=' . rawurlencode($tpl['name'] . ' (sitemap)') . '&base_url=' . rawurlencode((string) $tpl['base_url']), 'read its product sitemap with the marked-up site connector, daily', 'fw-semibold', 'id="source-form-door-jsonld"') ?>,
        or add a Storefront token: save this source and set its credential (<span id="source-form-door-token">the button below</span>).
    </div>
    <?php endif; ?>
    <form method="post" action="/sources/save.php" hx-post="/sources/save.php" hx-target="#flash" id="source-form" class="card" data-source-form>
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="source" value="<?= (int) $id ?>"><?php endif; ?>
        <?php if ($tpl !== null): ?><input type="hidden" name="template" value="<?= e($tpl['key']) ?>"><?php endif; ?>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="source-form-field-connector">Connector</label>
                <?php if ($cur === null): ?>
                <select name="connector" id="source-form-field-connector" class="form-select btn-touch" data-connector-select><?php foreach (inv_connectors() as $k => $d): ?><option value="<?= $k ?>" title="<?= e($d['description']) ?>" <?= $connector === $k ? 'selected' : '' ?>><?= e($d['label']) ?></option><?php endforeach; ?></select>
                <?php else: ?>
                <div class="form-control btn-touch bg-light" id="source-form-field-connector"><?= e(inv_connectors()[$connector]['label'] ?? $connector) ?></div><input type="hidden" name="connector" value="<?= e($connector) ?>" data-connector-select>
                <?php endif; ?>
                <div class="fs-11 text-muted mt-1" id="source-form-connector-note"><?= e(inv_connectors()[$connector]['description'] ?? '') ?></div></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="source-form-field-name">Name</label>
                <input type="text" name="name" id="source-form-field-name" class="form-control btn-touch" maxlength="120" required value="<?= e((string) $val('name')) ?>"></div>
            <div class="col-12 col-md-6"><span class="form-label fs-12 text-muted d-block">Role</span>
                <div class="d-flex gap-2" id="source-form-field-role">
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch flex-fill" for="source-form-field-role-reference"><input type="radio" class="form-check-input mt-0" name="role" value="reference" id="source-form-field-role-reference" <?= $role === 'reference' ? 'checked' : '' ?>>Reference — what others charge</label>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch flex-fill" for="source-form-field-role-supplier"><input type="radio" class="form-check-input mt-0" name="role" value="supplier" id="source-form-field-role-supplier" <?= $role === 'supplier' ? 'checked' : '' ?>>Supplier — we buy here</label>
                </div></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="source-form-field-supplier">Supplier (a supplier source names one)</label>
                <select name="supplier" id="source-form-field-supplier" class="form-select btn-touch"><option value="">— none —</option><?php foreach ($suppliers as $sp): ?><option value="<?= (int) $sp['supplier_id'] ?>" <?= (int) $val('supplier_id', 0) === (int) $sp['supplier_id'] ? 'selected' : '' ?>><?= e($sp['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12" data-needs-base><label class="form-label fs-12 text-muted" for="source-form-field-base_url">Address (the store's https://…; not for a feed or a manual source)</label>
                <input type="url" name="base_url" id="source-form-field-base_url" class="form-control btn-touch" placeholder="https://store.example" value="<?= e((string) $val('base_url')) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="source-form-field-schedule_minutes">Schedule (minutes; 0 = manual)</label>
                <input type="number" name="schedule_minutes" id="source-form-field-schedule_minutes" class="form-control btn-touch" min="0" inputmode="numeric" value="<?= e($cur === null ? '' : (string) $cur['schedule_minutes']) ?>" placeholder="default">
                <div class="fs-11 text-muted mt-1">Blank: every <?= (int) $defaults['schedule_supplier_minutes'] ?> min for a supplier, <?= e(schedule_words((int) $defaults['schedule_reference_minutes'])) ?> for a reference, <?= e(schedule_words((int) $defaults['schedule_jsonld_minutes'])) ?> for a marked-up site.</div></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="source-form-field-rate_per_second">Rate (requests a second)</label>
                <input type="text" name="rate_per_second" id="source-form-field-rate_per_second" class="form-control btn-touch" inputmode="decimal" value="<?= e($cur === null ? '' : (string) $cur['rate_per_second']) ?>" placeholder="1">
                <div class="fs-11 text-muted mt-1">At most <?= e(number_format((float) $defaults['crawl_rate_per_second'], 2)) ?> — the policy.</div></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="source-form-field-user_agent">User-agent override (optional)</label>
                <input type="text" name="user_agent" id="source-form-field-user_agent" class="form-control btn-touch" maxlength="200" value="<?= e((string) ($cur === null ? '' : one_value(db(), 'SELECT user_agent FROM sources WHERE id = :id', ['id' => (int) $id]))) ?>" placeholder="Name/1.0 (+mailto:buyer@example.com)">
                <div class="fs-11 text-muted mt-1">An honest user-agent names the business and a contact.</div></div>
            <?php if ($cur !== null): ?>
            <div class="col-12"><input type="hidden" name="active" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="source-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="source-form-field-active" <?= $cur['active'] ? 'checked' : '' ?>>Active</label></div>
            <?php endif; ?>
        </div>

        <fieldset class="card-body border-top source-sub" id="source-form-shopify" data-connector="shopify" <?= $connector === 'shopify' ? '' : 'data-hidden' ?>>
            <legend class="fs-13 fw-semibold">Shopify store<?= $connector === 'shopify' ? ' <span class="badge bg-soft-primary text-primary">chosen</span>' : '' ?></legend>
            <input type="hidden" name="settings_shopify[_present]" value="1">
            <div class="row g-2">
                <div class="col-12"><label class="form-label fs-12 text-muted" for="<?= $fid('shopify', 'collections') ?>">Collections (one handle a line; blank = the whole catalog)</label><textarea name="settings_shopify[collections]" id="<?= $fid('shopify', 'collections') ?>" class="form-control" rows="2"><?= e(implode("\n", (array) $set('shopify', 'collections', []))) ?></textarea></div>
                <?= $input('shopify', 'currency', 'Currency', 'text', (string) $set('shopify', 'currency', 'USD'), 'maxlength="3"') ?>
                <?= $input('shopify', 'api_version', 'Storefront API version', 'text', (string) $set('shopify', 'api_version', '2024-10')) ?>
                <?= $input('shopify', 'max_products', 'At most this many products', 'number', (string) $set('shopify', 'max_products', ''), 'min="1"') ?>
                <div class="col-12 fs-11 text-muted">A Storefront access token is a credential, set on the source after saving — never a setting.</div>
            </div>
        </fieldset>
        <fieldset class="card-body border-top source-sub" id="source-form-woocommerce" data-connector="woocommerce" <?= $connector === 'woocommerce' ? '' : 'data-hidden' ?>>
            <legend class="fs-13 fw-semibold">WooCommerce store<?= $connector === 'woocommerce' ? ' <span class="badge bg-soft-primary text-primary">chosen</span>' : '' ?></legend>
            <input type="hidden" name="settings_woocommerce[_present]" value="1">
            <div class="row g-2">
                <?= $input('woocommerce', 'currency', 'Currency (blank = what the store says)', 'text', (string) $set('woocommerce', 'currency', ''), 'maxlength="3"') ?>
                <?= $input('woocommerce', 'vendor', 'Brand, when the store names none', 'text', (string) $set('woocommerce', 'vendor', '')) ?>
                <?= $input('woocommerce', 'max_products', 'At most this many products', 'number', (string) $set('woocommerce', 'max_products', ''), 'min="1"') ?>
            </div>
        </fieldset>
        <fieldset class="card-body border-top source-sub" id="source-form-jsonld" data-connector="jsonld" <?= $connector === 'jsonld' ? '' : 'data-hidden' ?>>
            <legend class="fs-13 fw-semibold">Marked-up site<?= $connector === 'jsonld' ? ' <span class="badge bg-soft-primary text-primary">chosen</span>' : '' ?></legend>
            <input type="hidden" name="settings_jsonld[_present]" value="1">
            <div class="row g-2">
                <?= $input('jsonld', 'sitemap_url', 'Sitemap (absolute or relative; blank = found from robots.txt)', 'text', (string) $set('jsonld', 'sitemap_url', '')) ?>
                <?= $input('jsonld', 'url_pattern', 'URL pattern (a regular expression the product pages match)', 'text', (string) $set('jsonld', 'url_pattern', '')) ?>
                <div class="col-12"><label class="form-label fs-12 text-muted" for="<?= $fid('jsonld', 'urls') ?>">Or the product URLs, one a line</label><textarea name="settings_jsonld[urls]" id="<?= $fid('jsonld', 'urls') ?>" class="form-control" rows="3"><?= e(implode("\n", (array) $set('jsonld', 'urls', []))) ?></textarea></div>
                <?= $input('jsonld', 'max_pages', 'At most this many pages', 'number', (string) $set('jsonld', 'max_pages', ''), 'min="1"') ?>
                <?= $input('jsonld', 'currency', 'Currency (when the offers name none)', 'text', (string) $set('jsonld', 'currency', ''), 'maxlength="3"') ?>
            </div>
        </fieldset>
        <fieldset class="card-body border-top source-sub" id="source-form-feed" data-connector="feed" <?= $connector === 'feed' ? '' : 'data-hidden' ?>>
            <legend class="fs-13 fw-semibold">Supplier feed<?= $connector === 'feed' ? ' <span class="badge bg-soft-primary text-primary">chosen</span>' : '' ?></legend>
            <input type="hidden" name="settings_feed[_present]" value="1">
            <?php $sftp = (array) $set('feed', 'sftp', []); $isSftp = !empty($sftp['host']); ?>
            <div class="row g-2">
                <div class="col-12"><span class="form-label fs-12 text-muted d-block">Transport</span><div class="d-flex gap-2" id="<?= $fid('feed', 'transport') ?>">
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch flex-fill" for="<?= $fid('feed', 'transport') ?>-https"><input type="radio" class="form-check-input mt-0" name="settings_feed[transport]" value="https" id="<?= $fid('feed', 'transport') ?>-https" <?= $isSftp ? '' : 'checked' ?>>HTTPS URL</label>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch flex-fill" for="<?= $fid('feed', 'transport') ?>-sftp"><input type="radio" class="form-check-input mt-0" name="settings_feed[transport]" value="sftp" id="<?= $fid('feed', 'transport') ?>-sftp" <?= $isSftp ? 'checked' : '' ?>>SFTP</label></div></div>
                <div class="col-12"><label class="form-label fs-12 text-muted" for="<?= $fid('feed', 'url') ?>">The file's URL (https)</label><input type="url" name="settings_feed[url]" id="<?= $fid('feed', 'url') ?>" class="form-control btn-touch" value="<?= e((string) $set('feed', 'url', '')) ?>"></div>
                <?= $input('feed', 'sftp_host', 'SFTP host', 'text', (string) ($sftp['host'] ?? '')) ?>
                <?= $input('feed', 'sftp_port', 'SFTP port', 'number', (string) ($sftp['port'] ?? '22'), 'min="1"') ?>
                <?= $input('feed', 'sftp_user', 'SFTP user', 'text', (string) ($sftp['user'] ?? '')) ?>
                <?= $input('feed', 'sftp_path', 'SFTP path', 'text', (string) ($sftp['path'] ?? '')) ?>
                <div class="col-12 fs-11 text-muted" id="source-form-feed-sftp-tool">SFTP on this server: <?= e($sftpTool) ?><?= $sftpTool === 'sftp' ? ' — the sftp command takes a key, not a password' : ($sftpTool === 'none' ? ' — install php-ssh2 or curl with sftp' : '') ?>. The credential (basic, sftp_password or sftp_key) is set on the source after saving.</div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="<?= $fid('feed', 'format') ?>">Format</label><select name="settings_feed[format]" id="<?= $fid('feed', 'format') ?>" class="form-select btn-touch"><?php foreach (['auto', 'csv', 'xlsx'] as $o): ?><option <?= $set('feed', 'format', 'auto') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="<?= $fid('feed', 'delimiter') ?>">Delimiter</label><select name="settings_feed[delimiter]" id="<?= $fid('feed', 'delimiter') ?>" class="form-select btn-touch"><?php foreach (['auto' => 'auto', ',' => ',', ';' => ';', 'tab' => 'tab', '|' => '|'] as $o => $w): ?><option value="<?= e($o) ?>" <?= (string) $set('feed', 'delimiter', 'auto') === $o ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="<?= $fid('feed', 'has_header') ?>">Has a header row</label><select name="settings_feed[has_header]" id="<?= $fid('feed', 'has_header') ?>" class="form-select btn-touch"><option value="yes">yes</option><option value="no" <?= $set('feed', 'has_header', true) === false ? 'selected' : '' ?>>no</option></select></div>
                <?= str_replace('col-md-6', 'col-6 col-md-3', $input('feed', 'skip_rows', 'Skip rows', 'number', (string) $set('feed', 'skip_rows', '0'), 'min="0"')) ?>
                <?= str_replace('col-md-6', 'col-6 col-md-3', $input('feed', 'encoding', 'Encoding', 'text', (string) $set('feed', 'encoding', 'auto'))) ?>
                <?= str_replace('col-md-6', 'col-6 col-md-3', $input('feed', 'currency', 'Currency', 'text', (string) $set('feed', 'currency', 'USD'), 'maxlength="3"')) ?>
                <?= str_replace('col-md-6', 'col-6 col-md-3', $input('feed', 'vendor', 'Default brand', 'text', (string) $set('feed', 'vendor', ''))) ?>
                <?= str_replace('col-md-6', 'col-6 col-md-3', $input('feed', 'lead_time_days', 'Default lead time (days)', 'number', (string) $set('feed', 'lead_time_days', ''), 'min="0"')) ?>
                <div class="col-12"><button type="button" class="btn btn-light btn-touch" id="feed-read-btn" hx-post="/sources/preview.php" hx-include="#source-form" hx-target="#feed-preview-area" hx-swap="innerHTML">Read the file</button>
                    <span class="fs-11 text-muted ms-2">Reads the first rows and offers the columns to map (a big file may take a minute).</span></div>
                <div class="col-12" id="feed-preview-area"><?= view('sources/partials/feed-mapping.php', ['columns' => [], 'mapping' => (array) $set('feed', 'mapping', []), 'missing' => []]) ?></div>
            </div>
        </fieldset>
        <fieldset class="card-body border-top source-sub" id="source-form-manual" data-connector="manual" <?= $connector === 'manual' ? '' : 'data-hidden' ?>>
            <legend class="fs-13 fw-semibold">Typed by a person<?= $connector === 'manual' ? ' <span class="badge bg-soft-primary text-primary">chosen</span>' : '' ?></legend>
            <input type="hidden" name="settings_manual[_present]" value="1">
            <?= view('sources/partials/manual-editor.php', ['listings' => (array) $set('manual', 'listings', []), 'sizes' => $sizes]) ?>
        </fieldset>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary btn-touch" id="source-form-save-btn"><?= $cur === null ? 'Make the source' : 'Save' ?></button>
            <?php if ($cur === null && $connector === 'shopify'): ?><button type="submit" name="then_credential" value="yes" class="btn btn-light btn-touch" id="source-form-save-credential-btn">Make it and set a Storefront token</button><?php endif; ?>
            <?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="source-form-cancel-link"') ?>
        </div>
    </form>
</div>
