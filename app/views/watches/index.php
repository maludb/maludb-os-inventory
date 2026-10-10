<?php /** The watch list (screen `watch-list`). Data: rows, filters, page, total, all (watches.all), members, tz, here, notice */
$q = array_filter(['kind' => $filters['kind'], 'fired' => $filters['fired'], 'member' => $filters['member'], 'cleared' => $filters['cleared'] ? '1' : null], static fn ($v) => $v !== null && $v !== '');
$qs = static fn (array $x): string => '/watches/?' . http_build_query($q + $x);
$pages = (int) ceil($total / WATCH_PAGE);
?>
<?= view('shared/header.php', ['id' => 'watch-list', 'title' => $all ? 'Watches' : 'My watches', 'crumbs' => [['Home', '/'], ['Watches', null]]]) ?>
<div class="main-content" id="watch-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/watches/" hx-get="/watches/" hx-target="#page-content" hx-push-url="true" class="row g-2 mb-3" id="watch-filters">
        <div class="col-6 col-md-3"><select name="kind" class="form-select btn-touch" id="watch-filter-kind" aria-label="Kind"><option value="">Every kind</option><?php foreach (WATCH_KINDS as $k => [$w]): ?><option value="<?= $k ?>"<?= $filters['kind'] === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="fired" class="form-select btn-touch" id="watch-filter-fired" aria-label="Fired"><option value="">Any</option><option value="fired"<?= $filters['fired'] === 'fired' ? ' selected' : '' ?>>Fired</option><option value="never"<?= $filters['fired'] === 'never' ? ' selected' : '' ?>>Never fired</option></select></div>
        <?php if ($all): ?><div class="col-12 col-md-3"><select name="member" class="form-select btn-touch" id="watch-filter-member" aria-label="Set by"><option value="">Everyone</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['member_id'] ?>"<?= $filters['member'] === (int) $m['member_id'] ? ' selected' : '' ?>><?= e($m['member_name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="watch-filter-cleared"><input type="checkbox" class="form-check-input mt-0" name="cleared" value="1" id="watch-filter-cleared"<?= $filters['cleared'] ? ' checked' : '' ?>>Show cleared</label></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="watch-filter-btn">Filter</button></div>
    </form>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="watch-list-table">
        <thead class="thead-light"><tr><th>Target</th><th>Kind</th><th class="text-end">Threshold</th><th>Now</th><th>Last fired</th><th>Text</th><th>Agent</th><?php if ($all): ?><th>Set by</th><?php endif; ?><th></th></tr></thead>
        <tbody>
            <?php if ($rows === []): ?><tr><td colspan="<?= $all ? 9 : 8 ?>" class="text-center text-muted py-4" id="watch-list-empty">Nothing watched. Set one from Find or a variant's page.</td></tr><?php endif; ?>
            <?php foreach ($rows as $w): ?><?= view('watches/partials/watch-row.php', ['w' => $w, 'all' => $all, 'tz' => $tz, 'here' => $here]) ?><?php endforeach; ?>
        </tbody>
    </table></div></div></div>
    <?php if ($pages > 1): ?><nav class="mt-2"><ul class="pagination mb-0"><?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?><li class="page-item disabled"><span class="page-link"><?= $page ?> of <?= $pages ?></span></li><?php if ($page < $pages): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Next', 'page-link') ?></li><?php endif; ?></ul></nav><?php endif; ?>
</div>
