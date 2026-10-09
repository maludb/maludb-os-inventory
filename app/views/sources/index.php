<?php /** The sources (screen `source-list`): cards. Data: sources, filters, counts, mayWrite, here, notice */
$actions = $mayWrite ? hx_link('/sources/templates', '<i class="feather-copy me-1"></i>Add from a template', 'btn btn-light btn-touch', 'id="source-list-template-btn"') . ' ' . hx_link('/sources/new', '<i class="feather-plus me-1"></i>Add a source', 'btn btn-primary btn-touch', 'id="source-list-new-btn"') : '';
?>
<?= view('shared/header.php', ['id' => 'source-list', 'title' => 'Sources', 'crumbs' => [['Home', '/'], ['Sources', null]], 'back' => back_link(), 'action' => $actions === '' ? '' : '<div class="doc-actions">' . $actions . '</div>']) ?>
<div class="main-content" id="source-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/sources/" hx-get="/sources/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-2" id="source-list-filters">
        <div class="col-12 col-md-4"><input type="search" name="q" class="form-control btn-touch" placeholder="Search sources" value="<?= e($filters['q']) ?>" id="source-list-filter-q" aria-label="Search"></div>
        <div class="col-6 col-md-2"><select name="connector" class="form-select btn-touch" id="source-list-filter-connector" aria-label="Connector"><option value="">Every connector</option><?php foreach (inv_connectors() as $k => $d): ?><option value="<?= $k ?>" <?= $filters['connector'] === $k ? 'selected' : '' ?>><?= e($d['label']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="role" class="form-select btn-touch" id="source-list-filter-role" aria-label="Role"><option value="">Every role</option><?php foreach (SOURCE_ROLES as $k => $w): ?><option value="<?= $k ?>" <?= $filters['role'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="health" class="form-select btn-touch" id="source-list-filter-health" aria-label="Health"><option value="">Every health</option><?php foreach (SOURCE_HEALTHS as $k => $w): ?><option value="<?= $k ?>" <?= $filters['health'] === $k ? 'selected' : '' ?>><?= e($w) ?> (<?= (int) ($counts[$k] ?? 0) ?>)</option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="source-list-filter-btn">Filter</button></div>
    </form>
    <div class="chip-row mb-3" id="source-list-counts"><?php foreach ($counts as $k => $n): if ($n === 0) { continue; } ?><?= health_chip($k) ?> <span class="fs-12 me-2"><?= $n ?></span><?php endforeach; ?></div>
    <div class="row g-3" id="source-list">
        <?php if ($sources === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted" id="source-list-empty">No sources yet<?= $mayWrite ? ' — add one, or start from a template.' : '.' ?></div></div></div><?php endif; ?>
        <?php foreach ($sources as $s): ?><div class="col-12 col-md-6 col-xl-4"><?= view('sources/partials/source-card.php', ['s' => $s, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
</div>
