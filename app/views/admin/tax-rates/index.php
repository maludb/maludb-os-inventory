<?php /** The tax rates (screen `tax-rate-list`, `tax-rate-list-table`, `tax-rate-row-{id}`): live ones, then the archived apart. Data: live, archived, notice */
$chip = static fn (string $t, string $c): string => '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e($t) . '</span>';
?>
<?= view('shared/header.php', ['id' => 'tax-rate-list', 'title' => 'Tax rates', 'crumbs' => [['Home', '/'], ['Tax rates', null]], 'back' => back_link(),
    'action' => hx_link('/admin/tax-rates/new', '<i class="feather-plus me-1"></i>Add a tax rate', 'btn btn-primary btn-touch', 'id="tax-rate-list-add-btn"')]) ?>
<div class="main-content" id="tax-rate-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2" id="tax-rate-list-count"><?= count($live) ?> tax rate<?= count($live) === 1 ? '' : 's' ?> — the default applies to a new order with no rate of its own</div>
    <div class="card d-none d-lg-block"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover mb-0 fs-12" id="tax-rate-list-table">
            <thead class="thead-light"><tr><th>Name</th><th class="text-end">Rate</th><th>Default</th><th class="text-end">Used by</th><th class="text-end"></th></tr></thead>
            <tbody><?php foreach ($live as $t): ?><?= view('admin/tax-rates/partials/row.php', ['t' => $t, 'as' => 'row']) ?><?php endforeach; ?></tbody>
        </table>
    </div></div></div>
    <div class="d-lg-none" id="tax-rate-list-cards"><?php foreach ($live as $t): ?><?= view('admin/tax-rates/partials/row.php', ['t' => $t, 'as' => 'card']) ?><?php endforeach; ?></div>
    <?php if ($archived !== []): ?>
    <h6 class="mt-4 mb-2" id="tax-rate-list-archived-heading">Archived</h6>
    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12 text-muted" id="tax-rate-list-archived-table">
            <tbody><?php foreach ($archived as $t): ?><tr id="tax-rate-archived-<?= (int) $t['tax_rate_id'] ?>"><td><?= e($t['name']) ?></td><td class="text-end"><?= e(rtrim(rtrim($t['rate'], '0'), '.')) ?>%</td><td><?= $chip('archived', 'secondary') ?></td><td class="text-end"><?= (int) $t['used_by'] ?> still name it</td></tr><?php endforeach; ?></tbody>
        </table>
    </div></div></div>
    <?php endif; ?>
</div>
