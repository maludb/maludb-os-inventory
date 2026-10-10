<?php /** One source (screen `source-view`). Data: s, full, settings, ua, may (write, credentials, delete, match), deleteCounts, trail, tz, here, notice */
$id = (int) $s['source_id'];
$cred = $full['credential'];
$counts = $full['counts'];
$caps = inv_connectors()[$s['connector']]['capabilities'] ?? [];
$actions = '';
if ($may['write']) {
    $actions .= hx_link(with_back('/sources/' . $id . '/edit', $here), 'Edit', 'btn btn-light btn-touch', 'id="source-view-edit"');
    $actions .= '<form method="post" action="/sources/pull.php" hx-post="/sources/pull.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="source" value="' . $id . '"><button type="submit" class="btn btn-primary btn-touch" id="source-pull-now">Pull now</button></form>';
    if ($s['paused_at'] === null) {
        $actions .= '<form method="post" action="/sources/pause.php" hx-post="/sources/pause.php" hx-target="#flash" class="d-inline-flex gap-1" id="source-pause-form">' . csrf_field() . '<input type="hidden" name="source" value="' . $id . '"><input type="text" name="reason" class="form-control btn-touch" style="max-width:10rem" placeholder="Reason" aria-label="Pause reason" maxlength="200" id="source-pause-reason"><button type="submit" class="btn btn-light btn-touch" id="source-pause">Pause</button></form>';
    } else {
        $actions .= '<form method="post" action="/sources/resume.php" hx-post="/sources/resume.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="source" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="source-resume">Resume</button></form>';
    }
}
if ($may['delete'] && $deleteCounts !== null) {
    $what = $deleteCounts['listings'] . ' listings, ' . $deleteCounts['offers'] . ' offers, ' . $deleteCounts['pulls'] . ' pulls' . ($deleteCounts['credential'] ? ' and the credential' : '') . ' go with it' . ($deleteCounts['matched'] ? '; ' . $deleteCounts['matched'] . ' matched variants are unmatched' : '') . '. The price sheet stays.';
    $actions .= '<form method="post" action="/sources/delete.php" hx-post="/sources/delete.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Delete ' . $s['name'] . '? ' . $what) . '">' . csrf_field() . '<input type="hidden" name="source" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch text-danger" id="source-delete">Delete</button></form>';
}
?>
<?= view('shared/header.php', ['id' => 'source-view', 'title' => $s['name'], 'crumbs' => [['Home', '/'], ['Sources', '/sources/'], [$s['name'], null]], 'back' => back_link() ?? ['/sources/', 'Sources'], 'action' => $actions === '' ? '' : '<div class="doc-actions">' . $actions . '</div>']) ?>
<div class="main-content" id="source-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="source-view-summary"><div class="card-body">
        <div class="chip-row"><?= connector_badge($s['connector']) ?> <?= role_chip($s['role']) ?> <?= robots_chip((string) $s['robots_state']) ?><?= $s['active'] ? '' : ' <span class="badge bg-soft-dark text-dark">inactive</span>' ?></div>
        <div class="mt-2"><?= view('sources/partials/health-chip.php', ['s' => $s]) ?></div>
        <?php if ($s['supplier_id'] !== null): ?><div class="fs-12 mt-1">Supplier: <?= hx_link(with_back('/suppliers/' . $s['supplier_id'], $here), e((string) $s['supplier_name']), 'fw-semibold') ?></div><?php endif; ?>
        <?php if ($s['base_url']): ?><div class="fs-12 mt-1 text-break"><a href="<?= e($s['base_url']) ?>" rel="noopener nofollow" target="_blank" id="source-view-base-url"><?= e($s['base_url']) ?></a></div><?php endif; ?>
        <div class="fs-12 text-muted mt-1" id="source-view-schedule"><?= e(schedule_words($s['schedule_minutes'])) ?> · <?= e(number_format($s['rate_per_second'], 2)) ?> a second<?= $s['next_due_at'] ? ' · next due ' . e(format_ts($s['next_due_at'], $tz, 'M j, g:i A')) : '' ?></div>
        <?php if ($may['write']): ?>
            <?php if ($ua === null): ?><div class="alert alert-warning fs-12 mt-2 mb-0" id="source-view-no-ua">The crawler has no honest user-agent: set the business name and contact in Settings.</div>
            <?php else: ?><div class="fs-11 text-muted mt-1 text-break" id="source-view-ua">User-agent: <?= e($ua) ?></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($may['write']): ?>
        <div class="mt-3 d-flex flex-wrap gap-2 align-items-center" id="source-probe">
            <form method="post" action="/sources/probe.php" hx-post="/sources/probe.php" hx-target="#source-probe-result" hx-swap="outerHTML" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="source-probe-btn"><i class="feather-activity me-1"></i>Probe</button></form>
            <span class="fs-11 text-muted">Can it be read as configured? A handful of requests; a feed reads its whole file.</span>
        </div>
        <div id="source-probe-result"></div>
        <?php endif; ?>
    </div></div>
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <?php if ($may['write'] || $may['credentials']): ?>
            <div class="card mb-3" id="source-credential"><div class="card-header d-flex align-items-center justify-content-between"><h5 class="card-title mb-0">Credential</h5>
                <?php if ($may['credentials'] && ($caps['credential_kinds'] ?? []) !== []): ?><?= hx_link(with_back('/sources/' . $id . '/credential', $here), $cred ? 'Rotate' : 'Set credential', 'btn btn-light btn-sm btn-touch', 'id="source-credential-set"') ?><?php endif; ?></div>
                <div class="card-body fs-12">
                    <?php if (($caps['credential_kinds'] ?? []) === []): ?><div class="text-muted">This connector takes no credential.</div>
                    <?php elseif ($cred === null): ?><div class="text-muted" id="source-credential-none">None set<?= $caps['needs_credential'] ? ' — ' . e(is_string($caps['needs_credential']) ? $caps['needs_credential'] : 'one is needed') : ' (optional)' ?>.</div>
                    <?php else: ?><div id="source-credential-summary"><span class="fw-semibold"><?= e($cred['label']) ?></span> · <?= e($cred['kind']) ?> · <code>…<?= e($cred['last4']) ?></code></div>
                        <div class="text-muted">Set <?= e(format_ts($cred['created_at'], $tz, 'M j, Y')) ?></div><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <div class="card mb-3" id="source-listings-card"><div class="card-header d-flex align-items-center justify-content-between"><h5 class="card-title mb-0">Listings</h5><?= hx_link(with_back('/sources/' . $id . '/listings', $here), 'All', 'btn btn-light btn-sm btn-touch', 'id="source-listings-link"') ?></div>
                <div class="card-body fs-12" id="source-listings-counts"><?= (int) $counts['live'] ?> live · <?= (int) $counts['matched'] ?> variants matched · <?= (int) $counts['unmatched'] ?> unmatched<?= $counts['removed'] ? ' · ' . (int) $counts['removed'] . ' removed' : '' ?><?= $counts['forgotten'] ? ' · ' . (int) $counts['forgotten'] . ' not ours' : '' ?>
                    <?php if ($may['match'] && $counts['unmatched'] > 0): ?><div class="mt-1"><?= hx_link('/matching/?source=' . $id, 'The match queue for this source', 'fw-semibold', 'id="source-queue-link"') ?></div><?php endif; ?></div></div>
            <?php if ($may['write'] && $settings !== null): ?>
            <div class="card mb-3" id="source-settings"><div class="card-header"><h5 class="card-title mb-0">Settings</h5></div><div class="card-body fs-12">
                <?php if ($settings === []): ?><div class="text-muted">The connector's defaults.</div><?php endif; ?>
                <dl class="row mb-0"><?php foreach ($settings as $k => $v): if ($k === 'listings') { $v = count((array) $v) . ' rows'; } ?>
                    <dt class="col-5 text-muted fw-normal"><?= e(str_replace('_', ' ', (string) $k)) ?></dt>
                    <dd class="col-7 mb-1 text-break"><?php if ($k === 'mapping' && is_array($v)): foreach ($v as $f => $col): ?><div><?= e($f) ?> → <?= e((string) $col) ?></div><?php endforeach; elseif (is_array($v)): ?><?= e(implode(', ', array_map(static fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v))) ?><?php else: ?><?= e(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v) ?><?php endif; ?></dd>
                <?php endforeach; ?></dl>
            </div></div>
            <?php endif; ?>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card mb-3" id="source-last-pulls"><div class="card-header d-flex align-items-center justify-content-between"><h5 class="card-title mb-0">Last pulls</h5><?= hx_link(with_back('/sources/' . $id . '/pulls', $here), 'All', 'btn btn-light btn-sm btn-touch', 'id="source-pulls-link"') ?></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="pulls-table"><thead class="thead-light"><tr><th>Started</th><th>Kind</th><th>Status</th><th>Counts</th><th class="d-none d-md-table-cell">Requests</th><th>Error</th></tr></thead><tbody>
                    <?php if ($full['pulls'] === []): ?><tr><td colspan="6" class="text-center text-muted py-3">Never pulled.</td></tr><?php endif; ?>
                    <?php foreach ($full['pulls'] as $p): ?><?= view('sources/partials/pull-row.php', ['p' => $p, 'tz' => $tz, 'full' => false]) ?><?php endforeach; ?>
                </tbody></table></div></div></div>
            <div class="card mb-3" id="source-trail"><div class="card-header"><h5 class="card-title mb-0">Trail</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
                <?php if ($trail === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
                <?php foreach ($trail as $r): ?><tr id="source-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
        </div>
    </div>
    <?= view('shared/notes.php', ['recordType' => 'source', 'recordId' => $id, 'tz' => $tz]) ?>
    <?= view('shared/attachments.php', ['recordType' => 'source', 'recordId' => $id, 'tz' => $tz]) ?>
</div>
