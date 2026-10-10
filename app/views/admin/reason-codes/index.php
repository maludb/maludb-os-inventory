<?php /** The reason codes (screen `reason-code-list`, `reason-code-list-table`, `reason-code-row-{id}`). Data: rows, notice */ ?>
<?= view('shared/header.php', ['id' => 'reason-code-list', 'title' => 'Reason codes', 'crumbs' => [['Home', '/'], ['Reason codes', null]], 'back' => back_link(),
    'action' => hx_link('/admin/reason-codes/new', '<i class="feather-plus me-1"></i>Add a reason code', 'btn btn-primary btn-touch', 'id="reason-code-list-add-btn"')]) ?>
<div class="main-content" id="reason-code-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2" id="reason-code-list-count"><?= count($rows) ?> reason code<?= count($rows) === 1 ? '' : 's' ?> — what an adjustment or a return may say it is for</div>
    <div class="card d-none d-lg-block"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover mb-0 fs-12" id="reason-code-list-table">
            <thead class="thead-light"><tr><th>Code</th><th>Name</th><th>Applies to</th><th>Moves quantity</th><th>Active</th><th class="text-end"></th></tr></thead>
            <tbody><?php foreach ($rows as $r): ?><?= view('admin/reason-codes/partials/row.php', ['r' => $r, 'as' => 'row']) ?><?php endforeach; ?></tbody>
        </table>
    </div></div></div>
    <div class="d-lg-none" id="reason-code-list-cards"><?php foreach ($rows as $r): ?><?= view('admin/reason-codes/partials/row.php', ['r' => $r, 'as' => 'card']) ?><?php endforeach; ?></div>
</div>
