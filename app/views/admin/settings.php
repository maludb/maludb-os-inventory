<?php
/**
 * The settings (screen `admin-settings`): one form (`admin-settings-form`) over every column of inv_settings in seven groups as cards; Save and Cancel pinned in the header. Data: s (find_settings), groups, inUse (size_key => variants), shipsHow, buyers, timezones, tz, notice
 */
$fid = static fn (string $col): string => 'admin-settings-field-' . $col;
$h = static fn ($v): string => e($v ?? '');
$text = static fn (string $col, string $label, string $hint = '', string $type = 'text', string $extra = ''): string =>
    '<div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="' . $fid($col) . '">' . e($label) . '</label><input type="' . $type . '" name="' . $col . '" id="' . $fid($col) . '" class="form-control btn-touch" value="' . e($s[$col] ?? '') . '" ' . $extra . '>'
    . ($hint !== '' ? '<div class="fs-11 text-muted mt-1">' . e($hint) . '</div>' : '') . '</div>';
$num = static fn (string $col, string $label, string $hint, string $min, string $max, string $step = '1'): string =>
    $text($col, $label, $hint, 'number', 'inputmode="decimal" min="' . $min . '" max="' . $max . '" step="' . $step . '" required');
$sw = static fn (string $col, string $label, string $hint): string =>
    '<div class="col-12 col-md-6"><input type="hidden" name="' . $col . '" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="' . $fid($col) . '"><input type="checkbox" class="form-check-input mt-0" name="' . $col . '" value="yes" id="' . $fid($col) . '" ' . ($s[$col] ? 'checked' : '') . '>' . e($label) . '</label><div class="fs-11 text-muted mt-1">' . e($hint) . '</div></div>';
$backoff = $s['crawl_backoff_minutes'];
?>
<?= view('shared/header.php', ['id' => 'admin-settings', 'title' => 'Settings', 'crumbs' => [['Home', '/'], ['Settings', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/admin/settings.php" hx-post="/admin/settings.php" hx-target="#flash" id="admin-settings-form">
        <?= csrf_field() ?>
        <div class="page-header-form d-flex align-items-center justify-content-between gap-2 px-3 py-2 border rounded bg-white mb-3" id="admin-settings-form-header">
            <div><div class="fw-semibold">The business's settings</div><div class="fs-11 text-muted">Last saved <?= e(format_ts($s['updated_at'], $tz, 'M j, Y g:i A')) ?></div></div>
            <div class="d-flex gap-2"><?= hx_link('/', 'Cancel', 'btn btn-light btn-touch', 'id="admin-settings-form-cancel-btn"') ?><button type="submit" class="btn btn-primary btn-touch" id="admin-settings-form-save-btn">Save</button></div>
        </div>
        <?php foreach ($groups as $g => [$title, $about, $cols]): ?>
        <div class="card mb-3" id="admin-settings-group-<?= e($g) ?>">
            <div class="card-header"><div><h5 class="card-title mb-0"><?= e($title) ?></h5><div class="fs-11 text-muted"><?= e($about) ?></div></div></div>
            <div class="card-body row g-2">
            <?php if ($g === 'business'): ?>
                <?= $text('business_name', 'Business name', 'Named in the crawler\'s user-agent and on the doors.', 'text', 'maxlength="120"') ?>
                <?= $text('business_contact_email', 'Contact e-mail', 'The crawl policy names this contact.', 'email', 'maxlength="254"') ?>
                <?= $text('business_phone', 'Phone', '', 'text', 'maxlength="40"') ?>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="<?= $fid('business_address') ?>">Address</label><textarea name="business_address" id="<?= $fid('business_address') ?>" class="form-control" rows="2" maxlength="500"><?= $h($s['business_address']) ?></textarea></div>
                <?= $text('currency', 'Currency', 'Three letters, like USD. One currency for now.', 'text', 'maxlength="3" required pattern="[A-Za-z]{3}" style="text-transform:uppercase"') ?>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="<?= $fid('units') ?>">Units</label><select name="units" id="<?= $fid('units') ?>" class="form-select btn-touch"><?php foreach (['imperial', 'metric'] as $u): ?><option <?= $s['units'] === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select><div class="fs-11 text-muted mt-1">How weights and lengths are shown; stored in grams and millimetres.</div></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="<?= $fid('timezone') ?>">Time zone</label><select name="timezone" id="<?= $fid('timezone') ?>" class="form-select btn-touch"><?php foreach ($timezones as $z): ?><option <?= $s['timezone'] === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select><div class="fs-11 text-muted mt-1">What "today" means for the morning note, the reports and the exports.</div></div>
            <?php elseif ($g === 'doors'): ?>
                <?= $sw('sales_sees_cost', 'Sales sees cost and margin', 'Off: only the Buyer and the admin see what goods cost.') ?>
                <div class="col-12 col-md-6"><div class="fs-12 text-muted mb-1">Suppliers see the customer's phone for</div><input type="hidden" name="supplier_sees_phone[]" value="">
                    <div class="d-flex flex-wrap gap-2" id="<?= $fid('supplier_sees_phone') ?>"><?php foreach ($shipsHow as $k): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch"><input type="checkbox" class="form-check-input mt-0" name="supplier_sees_phone[]" value="<?= e($k) ?>" <?= in_array($k, $s['supplier_sees_phone'], true) ? 'checked' : '' ?>><?= e(str_replace('_', ' ', $k)) ?></label><?php endforeach; ?></div>
                    <div class="fs-11 text-muted mt-1">The drop-ship ship-to carries the phone only for these shipping kinds.</div></div>
                <?= $sw('feed_shows_quantity', 'The feed says the quantity', 'Off: in stock, low or out only.') ?>
                <?= $num('order_link_days', 'Order link lives (days)', 'After the order closes: 1 to 3650.', '1', '3650') ?>
                <?= $num('supplier_link_days', 'Supplier link lives (days)', 'After the purchase order closes: 1 to 3650.', '1', '3650') ?>
            <?php elseif ($g === 'feed'): ?>
                <?= $num('feed_rate_per_minute', 'Calls per minute', 'A key with no limit of its own: 1 to 100,000.', '1', '100000') ?>
                <?= $num('feed_rate_per_day', 'Calls per day', '1 to 10,000,000.', '1', '10000000') ?>
                <?= $num('key_rotation_overlap_hours', 'Rotation overlap (hours)', 'The old key keeps working this long: 1 to 720.', '1', '720') ?>
            <?php elseif ($g === 'vocabulary'): ?>
                <div class="col-12 col-lg-6"><label class="form-label fs-12 text-muted" for="<?= $fid('sizes') ?>">Sizes (JSON)</label><textarea name="sizes" id="<?= $fid('sizes') ?>" class="form-control font-monospace fs-11" rows="10" spellcheck="false" data-render="admin-settings-sizes-table"><?= $h(json_encode($s['sizes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
                    <div class="fs-11 text-muted mt-1">[{"key", "name", "synonyms": []}] — a changed synonym applies to variants saved afterwards. A size a variant uses cannot be removed.</div></div>
                <div class="col-12 col-lg-6"><?= view('admin/partials/sizes-table.php', ['sizes' => $s['sizes'], 'inUse' => $inUse]) ?></div>
                <div class="col-12 col-lg-6"><label class="form-label fs-12 text-muted" for="<?= $fid('attribute_keys') ?>">Attribute keys (JSON)</label><textarea name="attribute_keys" id="<?= $fid('attribute_keys') ?>" class="form-control font-monospace fs-11" rows="10" spellcheck="false" data-render="admin-settings-attributes-table"><?= $h(json_encode($s['attribute_keys'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
                    <div class="fs-11 text-muted mt-1">[{"key", "name", "kind": choice · number · text · multi, "choices": []}] — choices for choice and multi.</div></div>
                <div class="col-12 col-lg-6"><?= view('admin/partials/attributes-table.php', ['attrs' => $s['attribute_keys']]) ?></div>
            <?php elseif ($g === 'buying'): ?>
                <?= $num('reorder_point_default', 'Default reorder point', 'Units available at or below which a variant is a candidate.', '0', '1000000') ?>
                <?= $num('reorder_qty_default', 'Default reorder quantity', 'Units to buy: at least 1.', '1', '1000000') ?>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="<?= $fid('cost_source') ?>">Where cost comes from</label><select name="cost_source" id="<?= $fid('cost_source') ?>" class="form-select btn-touch"><?php foreach (['last_receipt' => 'The last receipt', 'feed' => 'A supplier\'s feed', 'manual' => 'Typed by hand'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $s['cost_source'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <?= $num('cost_move_pct', 'A cost moved by (%)', 'More than this is a price exception: 0 to 999.99.', '0', '999.99', '0.01') ?>
                <?= $num('reference_undercut_pct', 'A reference undercuts by (%)', 'A reference under our retail by more than this: 0 to 999.99.', '0', '999.99', '0.01') ?>
                <?= $num('ack_days', 'Days to acknowledge', 'A sent purchase order unacknowledged past this is flagged: 1 to 90.', '1', '90') ?>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="<?= $fid('buyer') ?>">The Buyer</label><select name="buyer" id="<?= $fid('buyer') ?>" class="form-select btn-touch"><option value="">The first super-admin</option><?php foreach ($buyers as $b): ?><option value="<?= (int) $b['member_id'] ?>" <?= (int) $s['buyer'] === (int) $b['member_id'] ? 'selected' : '' ?>><?= e($b['display_name']) ?></option><?php endforeach; ?></select><div class="fs-11 text-muted mt-1">Who gets the morning note.</div></div>
            <?php elseif ($g === 'crawl'): ?>
                <div class="col-12"><label class="form-label fs-12 text-muted" for="<?= $fid('crawl_user_agent') ?>">User-agent</label><input type="text" name="crawl_user_agent" id="<?= $fid('crawl_user_agent') ?>" class="form-control btn-touch" maxlength="300" value="<?= $h($s['crawl_user_agent']) ?>" placeholder="(blank: built from the business name and contact)"><div class="fs-11 text-muted mt-1">An honest user-agent names the business and a contact: …(+https://example.com/bot or +mailto:you@example.com).</div></div>
                <?= $num('crawl_rate_per_second', 'Requests per second', 'Per host: 0.01 to 10.', '0.01', '10', '0.01') ?>
                <div class="col-12 col-md-6"><div class="fs-12 text-muted mb-1">Backoff ladder (minutes, rising)</div><div class="d-flex gap-2" id="<?= $fid('crawl_backoff_minutes') ?>"><?php for ($i = 0; $i < 3; $i++): ?><input type="number" min="1" max="43200" name="crawl_backoff_minutes[]" class="form-control btn-touch" aria-label="Step <?= $i + 1 ?>" value="<?= isset($backoff[$i]) ? (int) $backoff[$i] : '' ?>" <?= $i === 0 ? 'required' : '' ?>><?php endfor; ?></div><div class="fs-11 text-muted mt-1">One to three steps, each longer than the last; the last is followed by a pause until a person looks.</div></div>
                <?= $num('crawl_max_pages', 'Pages per pull', '1 to 10,000.', '1', '10000') ?>
                <?= $num('schedule_supplier_minutes', 'Suppliers every (minutes)', '5 to 10,080.', '5', '10080') ?>
                <?= $num('schedule_reference_minutes', 'References every (minutes)', '5 to 10,080.', '5', '10080') ?>
                <?= $num('schedule_jsonld_minutes', 'JSON-LD sources every (minutes)', '60 to 10,080.', '60', '10080') ?>
                <?= $num('removed_after_pulls', 'Removed after N pulls unseen', '1 to 20.', '1', '20') ?>
                <?= $num('snapshot_heartbeat_days', 'Snapshot heartbeat (days)', 'An offer is remembered at least this often: 1 to 30.', '1', '30') ?>
                <?= $num('raw_max_bytes', 'Raw object size (bytes)', 'A listing\'s trimmed raw object: 256 to 1,048,576.', '256', '1048576') ?>
            <?php elseif ($g === 'files'): ?>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="<?= $fid('max_attachment_bytes') ?>">Largest attachment (MB)</label><input type="number" min="1" max="1024" name="max_attachment_bytes" id="<?= $fid('max_attachment_bytes') ?>" class="form-control btn-touch" required value="<?= (int) round($s['max_attachment_bytes'] / 1048576) ?>"><div class="fs-11 text-muted mt-1">1 to 1024 megabytes.</div></div>
            <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </form>
</div>
