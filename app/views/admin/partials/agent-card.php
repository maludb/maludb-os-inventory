<?php /** One agent (`agent-card-{member_id}` or `agent-card-declared-{key}`). Data: a, tz */
$id = $a['member_id'] !== null ? 'agent-card-' . (int) $a['member_id'] : 'agent-card-declared-' . $a['declared_key'];
?>
<div class="card h-100" id="<?= e($id) ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div><div class="fw-semibold"><?= e($a['display_name']) ?></div><div class="fs-12 text-muted"><?= e($a['declared_name'] ?? $a['job_title'] ?? '') ?><?= $a['job_title'] && $a['declared_name'] && $a['job_title'] !== $a['declared_name'] ? ' · ' . e($a['job_title']) : '' ?></div></div>
        <span class="badge bg-soft-<?= $a['hired'] ? 'success text-success' : 'secondary text-secondary' ?>" id="<?= e($id) ?>-hired"><?= $a['hired'] ? 'hired here' : 'Not hired here yet' ?></span>
    </div>
    <dl class="row fs-12 mb-0 mt-2">
        <dt class="col-4 text-muted fw-normal">Roles here</dt><dd class="col-8"><?= $a['roles'] === [] ? '<span class="text-muted">none</span>' : e(implode(', ', $a['roles'])) ?></dd>
        <?php if ($a['duty'] !== null): ?><dt class="col-4 text-muted fw-normal">Duty</dt><dd class="col-8" id="<?= e($id) ?>-duty"><?= e($a['duty']['name']) ?> — <?= e(cron_in_words($a['duty']['schedule_cron'])) ?></dd><?php endif; ?>
        <?php if ($a['skills'] !== []): ?><dt class="col-4 text-muted fw-normal">Skills</dt><dd class="col-8"><?= e(implode(', ', $a['skills'])) ?></dd><?php endif; ?>
        <dt class="col-4 text-muted fw-normal">Last action</dt>
        <dd class="col-8" id="<?= e($id) ?>-last-action"><?php if ($a['last_action'] !== null): ?><?= e($a['last_action']['sentence']) ?> <span class="text-muted">· <?= e(format_ts($a['last_action']['occurred_at'], $tz, 'M j, g:i A')) ?></span><?php else: ?><span class="text-muted">nothing yet</span><?php endif; ?></dd>
        <dt class="col-4 text-muted fw-normal">Last dispatch</dt>
        <dd class="col-8" id="<?= e($id) ?>-last-dispatch"><?php if ($a['last_dispatch'] !== null): ?><?= e(str_replace('_', ' ', $a['last_dispatch']['status'])) ?> <span class="text-muted">· <?= e(format_ts($a['last_dispatch']['created_at'], $tz, 'M j, g:i A')) ?></span><?php else: ?><span class="text-muted">none</span><?php endif; ?></dd>
    </dl>
    <?php if ($a['member_id'] !== null): ?>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <?= hx_link('/admin/dispatches?agent=' . (int) $a['member_id'], 'pending <b>' . (int) $a['dispatches_pending'] . '</b>', 'badge bg-soft-secondary text-dark', 'id="' . e($id) . '-pending"') ?>
        <?= hx_link('/admin/dispatches?agent=' . (int) $a['member_id'] . '&status=failed', 'failed <b>' . (int) $a['dispatches_failed'] . '</b>', 'badge bg-soft-' . ($a['dispatches_failed'] > 0 ? 'danger text-danger' : 'secondary text-dark'), 'id="' . e($id) . '-failed"') ?>
        <?= hx_link('/proposals/', 'proposals today <b>' . (int) $a['proposals_today'] . '</b>', 'badge bg-soft-info text-info', 'id="' . e($id) . '-proposals"') ?>
    </div>
    <?php endif; ?>
</div></div>
