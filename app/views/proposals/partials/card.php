<?php /** One proposal (`proposal-card-{id}`). Data: p, may, tz, here */
$id = (int) $p['proposal_id'];
$subjectUrl = proposal_subject_url($p);
$draftedUrl = proposal_drafted_url($p);
$evidence = is_array($p['detail']['evidence'] ?? null) ? $p['detail']['evidence'] : [];
$facts = array_filter($p['detail'], static fn ($v, $k): bool => !in_array($k, ['evidence', 'confidence', 'dismiss_reason'], true) && is_scalar($v), ARRAY_FILTER_USE_BOTH);
$facts += array_filter($evidence, static fn ($v): bool => is_scalar($v));
?>
<div class="card h-100" id="proposal-card-<?= $id ?>"><div class="card-body">
    <div class="d-flex flex-wrap gap-1 align-items-center mb-1"><?= proposal_kind_chip($p['kind']) ?> <?= proposal_status_chip($p['status']) ?> <span class="fs-11 text-muted ms-auto"><?= e(format_date($p['note_date'])) ?></span></div>
    <div class="fw-semibold"><?= e($p['title']) ?></div>
    <div class="fs-12 text-muted">About <?= $subjectUrl !== null ? hx_link(with_back($subjectUrl, $here), e($p['subject_label'] ?? ('#' . $p['subject_id']))) : e($p['subject_label'] ?? ('#' . $p['subject_id'])) ?></div>
    <?php if ($facts !== []): ?><dl class="row fs-12 mb-0 mt-2"><?php foreach (array_slice($facts, 0, 8, true) as $k => $v): ?><dt class="col-5 text-muted fw-normal"><?= e(str_replace('_', ' ', (string) $k)) ?></dt><dd class="col-7 mb-0"><?= e(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v) ?></dd><?php endforeach; ?></dl><?php endif; ?>
    <?php if (isset($p['detail']['confidence'])): ?><div class="fs-12 mt-1"><span class="text-muted">Confidence</span> <?= (int) round((float) $p['detail']['confidence'] * 100) ?>%</div><?php endif; ?>
    <?php if ($p['drafted_record_type'] !== null): ?><div class="fs-12 mt-1"><span class="text-muted">Drafted</span> <?= $draftedUrl !== null ? hx_link(with_back($draftedUrl, $here), e($p['drafted_label'] ?? ('#' . $p['drafted_record_id'])), 'fw-semibold', 'id="proposal-card-' . $id . '-drafted"') : e($p['drafted_label'] ?? '') ?></div><?php endif; ?>
    <div class="fs-11 text-muted mt-2">Proposed by <span class="badge bg-soft-secondary text-secondary"><i class="feather-cpu me-1"></i><?= e($p['proposed_by_name'] ?? 'an agent') ?></span> · <?= e(format_ts($p['created_at'], $tz, 'M j, g:i A')) ?></div>
    <?php if ($p['status'] !== 'proposed'): ?><div class="fs-11 text-muted">Decided by <?= e($p['decided_by_name'] ?? 'someone') ?> · <?= e(format_ts($p['decided_at'], $tz, 'M j, g:i A')) ?><?= isset($p['detail']['dismiss_reason']) ? ' · ' . e($p['detail']['dismiss_reason']) : '' ?></div><?php endif; ?>
</div>
<?php if ($p['status'] === 'proposed' && $may['decide']): ?>
<div class="card-footer">
    <div class="d-flex flex-wrap gap-2">
        <?php if ($may['human']): ?><form method="post" action="/proposals/accept.php" hx-post="/proposals/accept.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>">
            <button type="submit" class="btn btn-primary btn-touch" id="proposal-card-<?= $id ?>-accept-btn"><i class="feather-check me-1"></i>Accept</button></form><?php endif; ?>
        <details class="flex-grow-1" id="proposal-card-<?= $id ?>-dismiss"><summary class="btn btn-light btn-touch d-inline-flex" id="proposal-card-<?= $id ?>-dismiss-btn" style="cursor: pointer"><i class="feather-x me-1"></i>Dismiss</summary>
            <?= view('proposals/partials/dismiss-form.php', ['id' => $id]) ?></details>
    </div>
</div>
<?php endif; ?>
</div>
