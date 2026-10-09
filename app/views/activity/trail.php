<?php /** My trail (screen `trail`). Data: rows, page, more, filters, query, tz, resultsHtml, record ([key, id]|null). Slice 9 makes it whole. */
$actions = ['' => 'Anything', 'member.' => 'Sign-ons', 'token.' => 'Tokens', 'prefs.' => 'Settings', 'notification.' => 'Notifications', 'assistant.' => 'The expert', 'screen.' => 'Screens opened',
            'product.' => 'Products', 'variant.' => 'Variants', 'stock.' => 'Stock', 'source.' => 'Sources', 'listing.' => 'Listings', 'order.' => 'Orders', 'po.' => 'Purchase orders', 'return.' => 'Returns', 'directory.' => 'Directory refreshes'];
$titles = ['product' => 'the product', 'variant' => 'the variant', 'source' => 'the source', 'order' => 'the order', 'purchase_order' => 'the purchase order', 'member' => 'the member'];
?>
<?= view('shared/header.php', ['id' => 'trail', 'title' => 'My trail', 'crumbs' => [['Home', '/'], ['My trail', null]], 'back' => back_link()]) ?>
<div class="main-content" id="trail-content">
    <div class="card stretch stretch-full" id="trail-card">
        <div class="card-header">
            <h5 class="card-title"><?= $record !== null ? ucfirst($titles[$record[0]] ?? 'the record') . "'s history" : 'What you did' ?></h5>
            <form id="trail-filters" class="d-flex flex-wrap gap-2" method="get" action="/trail" hx-get="/trail" hx-target="#trail-results" hx-swap="outerHTML" hx-trigger="change" hx-push-url="true">
                <?php if ($record !== null): ?><input type="hidden" name="<?= e($record[0]) ?>" value="<?= e((string) $record[1]) ?>"><?php endif; ?>
                <select name="action" id="trail-filter-action" class="form-select form-select-sm w-auto btn-touch" aria-label="What"><?php foreach ($actions as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['action'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <select name="period" id="trail-filter-period" class="form-select form-select-sm w-auto btn-touch" aria-label="Period"><option value="">All time</option><?php foreach ([1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $v => $l): ?><option value="<?= $v ?>" <?= $filters['since'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </form>
        </div>
        <?= $resultsHtml ?>
    </div>
</div>
