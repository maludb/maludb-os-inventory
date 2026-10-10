<?php /** The availability feed's keys (screen `feed-key-list`): a table at 992 px and over, cards under. Data: keys, selected, usage, bucket, minted, tz, here, feedUrl, limits, notice */
$labelOf = static function (int $id) use ($keys): string { foreach ($keys as $k) { if ((int) $k['key_id'] === $id) { return (string) $k['label']; } } return ''; };
?>
<?= view('shared/header.php', ['id' => 'feed-key-list', 'title' => 'Feed keys', 'crumbs' => [['Home', '/'], ['Feed keys', null]], 'back' => back_link(),
    'action' => hx_link('/admin/feed-keys/new', '<i class="feather-plus me-1"></i>Mint a key', 'btn btn-primary btn-touch', 'id="feed-key-list-mint-btn"')]) ?>
<div class="main-content" id="feed-key-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($minted !== null): ?><?= view('admin/feed-keys/partials/key-minted.php', ['minted' => $minted, 'label' => $labelOf((int) $minted['key_id']), 'tz' => $tz]) ?><?php endif; ?>
    <div class="card mb-3" id="feed-key-list-howto"><div class="card-body fs-12">
        <div class="fw-semibold mb-1">How a website or a partner asks</div>
        <p class="mb-2">One key per consumer. It asks for one product at a time with the key as a Bearer token; the answer is each variant's retail price, whether it is in stock, on back order or out, the best lead time and how it ships (a partner's key also gets its price). Never cost, never where we buy it.</p>
        <pre class="mb-2 p-2 bg-light border rounded text-break" style="white-space: pre-wrap" id="feed-key-list-howto-curl">curl -H "Authorization: Bearer KEY" \
  "<?= e($feedUrl) ?>?gtin=00812345000123"
curl -H "Authorization: Bearer KEY" \
  "<?= e($feedUrl) ?>?q=casper&amp;size=queen"</pre>
        <div class="text-muted">Use <code>gtin</code>, <code>sku</code> or <code>q</code> (up to 120 characters; <code>size</code> goes with <code>q</code>) — exactly one. A key may ask <?= number_format((int) $limits['per_minute']) ?> times a minute and <?= number_format((int) $limits['per_day']) ?> times a day unless it was given other limits.</div>
    </div></div>

    <div class="fs-12 text-muted mb-2" id="feed-key-list-count"><?= count($keys) ?> key<?= count($keys) === 1 ? '' : 's' ?></div>
    <?php if ($keys === []): ?>
        <div class="card"><div class="card-body text-center text-muted" id="feed-key-list-empty">No feed keys yet — mint the first one for your website.</div></div>
    <?php else: ?>
    <div class="card d-none d-lg-block" id="feed-key-list-table-card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover mb-0 fs-12" id="feed-key-list-table">
            <thead class="thead-light"><tr><th>Label</th><th>Consumer</th><th>Price list</th><th class="text-end">Per minute</th><th class="text-end">Per day</th><th class="text-end">Today</th><th>Last used</th><th>Status</th><th>Minted</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($keys as $k): ?><?= view('admin/feed-keys/partials/key-row.php', ['k' => $k, 'tz' => $tz, 'as' => 'row', 'here' => $here, 'selected' => $selected]) ?><?php endforeach; ?>
            </tbody>
        </table>
    </div></div></div>
    <div class="d-lg-none" id="feed-key-list-cards">
        <?php foreach ($keys as $k): ?><?= view('admin/feed-keys/partials/key-row.php', ['k' => $k, 'tz' => $tz, 'as' => 'card', 'here' => $here, 'selected' => $selected]) ?><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($selected !== null && $usage !== null): ?><?= view('admin/feed-keys/partials/usage.php', ['k' => $selected, 'usage' => $usage, 'bucket' => $bucket, 'tz' => $tz]) ?><?php endif; ?>
    <?php if ($selected === null && isset($_GET['key'])): ?><div class="alert alert-warning" id="feed-key-usage-missing">That key is not here.</div><?php endif; ?>
</div>
