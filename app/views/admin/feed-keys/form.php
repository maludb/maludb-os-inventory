<?php /** Mint a feed key (screen `feed-key-add`; the form `feed-key-form`). Data: priceLists (the active ones), defaults [rate_per_minute, rate_per_day], tomorrow */
$back = '/admin/feed-keys/';
$fld = static function (string $name, string $label, string $html, string $cls = 'col-12 col-md-6', string $hint = ''): string {
    return '<div class="' . $cls . '" id="feed-key-form-row-' . $name . '"><label class="form-label fs-12 text-muted" for="feed-key-form-field-' . $name . '">' . e($label) . '</label>' . $html . ($hint !== '' ? '<div class="fs-11 text-muted mt-1">' . $hint . '</div>' : '') . '</div>';
};
?>
<?= view('shared/header.php', ['id' => 'feed-key-add', 'title' => 'Mint a feed key', 'crumbs' => [['Home', '/'], ['Feed keys', $back], ['Mint', null]], 'back' => back_link() ?? [$back, 'Feed keys']]) ?>
<div class="main-content" id="feed-key-add-content">
    <form method="post" action="/admin/feed-keys/mint.php" hx-post="/admin/feed-keys/mint.php" hx-target="#flash" id="feed-key-form" class="card">
        <?= csrf_field() ?>
        <div class="page-header-form d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom" id="feed-key-form-header">
            <div class="fw-semibold">A new feed key</div>
            <div class="d-flex gap-2"><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="feed-key-form-cancel-btn"') ?><button type="submit" class="btn btn-primary btn-touch" id="feed-key-form-save-btn">Mint</button></div>
        </div>
        <div class="card-body row g-2">
            <?= $fld('label', 'Label — what will use this key', '<input type="text" name="label" id="feed-key-form-field-label" class="form-control btn-touch" maxlength="80" required placeholder="Our website" autocomplete="off">') ?>
            <?= $fld('consumer_kind', 'Who asks', '<select name="consumer_kind" id="feed-key-form-field-consumer_kind" class="form-select btn-touch">' . implode('', array_map(static fn ($k, $w) => '<option value="' . e($k) . '"' . ($k === 'website' ? ' selected' : '') . '>' . e($w) . '</option>', array_keys(FEED_CONSUMER_KINDS), FEED_CONSUMER_KINDS)) . '</select>') ?>
            <?= $fld('price_list', "Price list (a partner store's)", '<select name="price_list" id="feed-key-form-field-price_list" class="form-select btn-touch"><option value="">— choose —</option>' . implode('', array_map(static fn (array $p) => '<option value="' . (int) $p['price_list_id'] . '">' . e($p['name']) . ' (' . e($p['percent_off_retail']) . '% off)</option>', $priceLists)) . '</select>', 'col-12 col-md-6', $priceLists === [] ? 'There is no active price list yet — ' . hx_link('/admin/price-lists/new', 'add one', '', 'id="feed-key-form-add-price-list"') . ' first.' : "Only a partner store's key answers with a partner price.") ?>
            <?= $fld('rate_per_minute', 'Calls a minute', '<input type="number" name="rate_per_minute" id="feed-key-form-field-rate_per_minute" class="form-control btn-touch" min="1" max="100000" inputmode="numeric" placeholder="' . (int) $defaults['rate_per_minute'] . '">', 'col-6 col-md-3', 'Blank takes the setting.') ?>
            <?= $fld('rate_per_day', 'Calls a day', '<input type="number" name="rate_per_day" id="feed-key-form-field-rate_per_day" class="form-control btn-touch" min="1" max="10000000" inputmode="numeric" placeholder="' . (int) $defaults['rate_per_day'] . '">', 'col-6 col-md-3', 'Blank takes the setting.') ?>
            <?= $fld('expires_at', 'Expires on (optional)', '<input type="date" name="expires_at" id="feed-key-form-field-expires_at" class="form-control btn-touch" min="' . e($tomorrow) . '">', 'col-12 col-md-6', 'A date from tomorrow on; the key works through that day.') ?>
        </div>
    </form>
</div>
