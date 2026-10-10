<?php /** The Buyer agent's proposals (screen `proposal-list`): cards. Data: rows, total, page, pages, status, kind, date, counts, note, dates, may, tz, here */
$qs = static fn (array $over = []): string => http_build_query(array_filter(array_merge(['status' => $status === 'proposed' ? null : $status, 'kind' => $kind, 'date' => $date], $over), static fn ($v) => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'proposal-list', 'title' => 'Buyer proposals', 'crumbs' => [['Home', '/'], ['Buyer proposals', null]], 'back' => back_link()]) ?>
<div class="main-content" id="proposal-list-content">
    <?= view('proposals/partials/note.php', ['note' => $note]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="proposal-list-tabs">
        <?php foreach (PROPOSAL_STATUSES as $k => $w): ?><?= hx_link('/proposals/?' . $qs(['status' => $k === 'proposed' ? null : $k, 'page' => null]), e($w) . ' <span class="badge bg-soft-secondary text-secondary ms-1">' . (int) $counts[$k] . '</span>', 'btn btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="proposal-list-tab-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <form method="get" action="/proposals/" hx-get="/proposals/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="proposal-list-filters">
        <?php if ($status !== 'proposed'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <div class="col-6 col-md-3"><select name="kind" class="form-select btn-touch" id="proposal-list-filter-kind" aria-label="Kind"><option value="">Every kind</option><?php foreach (PROPOSAL_KINDS as $k => $w): ?><option value="<?= $k ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><input type="date" name="date" class="form-control btn-touch" id="proposal-list-filter-date" value="<?= e($date) ?>" aria-label="Note date" title="The morning note's date"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="proposal-list-filter-btn">Filter</button></div>
    </form>
    <div class="fs-12 text-muted mb-2" id="proposal-list-count"><?= (int) $total ?> proposal<?= (int) $total === 1 ? '' : 's' ?></div>
    <?php if ($rows === []): ?><div class="card" id="proposal-list-empty"><div class="card-body"><div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-inbox"></i></span><div><div class="fw-semibold">Nothing here</div><div class="fs-12 text-muted">The Buyer agent's reorders, matches, prices and lines at risk wait here for a person to accept or dismiss.</div></div></div></div></div><?php endif; ?>
    <div class="row g-3">
        <?php foreach ($rows as $p): ?><div class="col-12 col-lg-6"><?= view('proposals/partials/card.php', ['p' => $p, 'may' => $may, 'tz' => $tz, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
    <?php if ($pages > 1): ?>
    <div class="d-flex gap-2 mt-3" id="proposal-list-pages">
        <?php if ($page > 1): ?><?= hx_link('/proposals/?' . $qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch', 'id="proposal-list-prev"') ?><?php endif; ?>
        <?php if ($page < $pages): ?><?= hx_link('/proposals/?' . $qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch', 'id="proposal-list-next"') ?><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
