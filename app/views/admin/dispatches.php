<?php /** Dispatches (screen `dispatch-list`): a table, cards at 375 px. Data: rows, total, page, pages, status, agent, agents, tz, here, notice */
$qs = static fn (array $over = []): string => http_build_query(array_filter(array_merge(['status' => $status, 'agent' => $agent], $over), static fn ($v) => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'dispatch-list', 'title' => 'Dispatches', 'crumbs' => [['Home', '/'], ['Dispatches', null]], 'back' => back_link()]) ?>
<div class="main-content" id="dispatch-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2">What watches, questions and duties handed to an agent — each one chat turn through the kernel. Retry puts a failed one back.</div>
    <form method="get" action="/admin/dispatches" hx-get="/admin/dispatches" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="dispatch-list-filters">
        <div class="col-6 col-md-3"><select name="status" class="form-select btn-touch" id="dispatch-list-filter-status" aria-label="Status"><option value="">Every status</option><?php foreach (DISPATCH_STATUSES as $k => $w): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><select name="agent" class="form-select btn-touch" id="dispatch-list-filter-agent" aria-label="Agent"><option value="">Every agent</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['member_id'] ?>"<?= (int) $agent === (int) $a['member_id'] ? ' selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="dispatch-list-filter-btn">Filter</button></div>
    </form>
    <div class="fs-12 text-muted mb-2" id="dispatch-list-count"><?= (int) $total ?> dispatch<?= (int) $total === 1 ? '' : 'es' ?></div>
    <?php if ($rows === []): ?><div class="card" id="dispatch-list-empty"><div class="card-body text-center text-muted">No dispatches. A watch that names an agent creates one when it fires.</div></div><?php endif; ?>
    <div class="d-none d-lg-block"><?php if ($rows !== []): ?><div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12" id="dispatch-list-table"><thead class="thead-light"><tr><th>#</th><th>Agent</th><th>Asked by</th><th>Kind</th><th>About</th><th>Status</th><th class="text-end">Calls</th><th>Run</th><th>When</th><th>Reply</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $d): ?><?= view('admin/partials/dispatch-row.php', ['d' => $d, 'tz' => $tz, 'mode' => 'row']) ?><?php endforeach; ?>
        </tbody></table></div></div></div><?php endif; ?></div>
    <div class="d-lg-none" id="dispatch-list-cards"><?php foreach ($rows as $d): ?><?= view('admin/partials/dispatch-row.php', ['d' => $d, 'tz' => $tz, 'mode' => 'card']) ?><?php endforeach; ?></div>
    <?php if ($pages > 1): ?>
    <div class="d-flex gap-2 mt-3" id="dispatch-list-pages">
        <?php if ($page > 1): ?><?= hx_link('/admin/dispatches?' . $qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch', 'id="dispatch-list-prev"') ?><?php endif; ?>
        <?php if ($page < $pages): ?><?= hx_link('/admin/dispatches?' . $qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch', 'id="dispatch-list-next"') ?><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
