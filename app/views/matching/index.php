<?php /** The match queue (screen `match-queue`). Data: rows, more, page, source, q, min, counts, sources, here, notice */
$qp = array_filter(['source' => $source, 'q' => $q, 'min_confidence' => $min], static fn ($v) => $v !== null && $v !== '');
$qs = static fn (array $x): string => '/matching/?' . http_build_query($qp + $x);
?>
<?= view('shared/header.php', ['id' => 'match-queue', 'title' => 'Match queue', 'crumbs' => [['Home', '/'], ['Sources', '/sources/'], ['Match queue', null]], 'back' => back_link()]) ?>
<div class="main-content" id="match-queue-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/matching/" hx-get="/matching/" hx-target="#page-content" hx-push-url="true" class="row g-2 mb-2" id="queue-filters">
        <div class="col-12 col-md-4"><input type="search" name="q" class="form-control btn-touch" placeholder="Title, SKU or GTIN" value="<?= e($q) ?>" id="queue-filter-q" aria-label="Search"></div>
        <div class="col-6 col-md-3"><select name="source" class="form-select btn-touch" id="queue-filter-source" aria-label="Source"><option value="">Every source</option><?php foreach ($sources as $s): ?><option value="<?= $s['source_id'] ?>" <?= $source === $s['source_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><select name="min_confidence" class="form-select btn-touch" id="queue-filter-min" aria-label="Minimum confidence"><option value="">Any confidence</option><?php foreach (['0.5', '0.6', '0.7', '0.8', '0.9'] as $c): ?><option value="<?= $c ?>" <?= $min !== null && abs($min - (float) $c) < 0.001 ? 'selected' : '' ?>>≥ <?= $c ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="queue-filter-btn">Filter</button></div>
    </form>
    <div class="fs-12 mb-2" id="queue-counts"><span class="fw-semibold"><?= $counts['queued'] ?></span> in the queue · <?= $counts['with_proposal'] ?> with a proposal · <?= $counts['without'] ?> without</div>
    <div id="queue-table">
        <?php if ($rows === []): ?><div class="card"><div class="card-body text-center text-muted" id="queue-empty">Nothing waits to be matched.</div></div><?php endif; ?>
        <?php foreach ($rows as $r): ?><?= view('matching/partials/queue-row.php', ['r' => $r, 'here' => $here]) ?><?php endforeach; ?>
    </div>
    <?php if ($page > 1 || $more): ?><nav class="mt-3"><ul class="pagination mb-0"><?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?><?php if ($more): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Next', 'page-link') ?></li><?php endif; ?></ul></nav><?php endif; ?>
</div>
