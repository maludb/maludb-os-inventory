<?php /** One dispatch: a table row (`dispatch-row-{id}`) from 992 px, a card under it. Data: d, tz, mode (row|card) */
$id = (int) $d['dispatch_id'];
$run = dispatch_run_url($d['run_id'] === null ? null : (int) $d['run_id']);
$retry = in_array($d['status'], ['failed', 'refused'], true)
    ? '<form method="post" action="/admin/dispatches/retry.php" hx-post="/admin/dispatches/retry.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<input type="hidden" name="dispatch" value="' . $id . '">'
        . '<button type="submit" class="btn btn-light btn-sm" id="dispatch-row-' . $id . '-retry-btn' . ($mode === 'card' ? '-card' : '') . '"><i class="feather-refresh-cw me-1"></i>Retry</button></form>' : '';
$runHtml = $d['run_id'] === null ? '<span class="text-muted">—</span>' : ($run !== null ? '<a href="' . e($run) . '" target="_blank" rel="noopener">#' . (int) $d['run_id'] . '</a>' : '#' . (int) $d['run_id']);
if ($mode === 'row'): ?>
<tr id="dispatch-row-<?= $id ?>">
    <td><?= $id ?></td>
    <td><span class="badge bg-soft-secondary text-secondary"><i class="feather-cpu me-1"></i><?= e($d['agent_name'] ?? '') ?></span></td>
    <td><?= e($d['asker_name'] ?? '—') ?></td>
    <td><?= e($d['kind']) ?> · <?= e($d['via']) ?></td>
    <td class="text-break" style="max-width: 18rem"><?= e($d['detail'] ?? '') ?></td>
    <td><?= dispatch_status_chip($d) ?></td>
    <td class="text-end"><?= (int) $d['attempts'] ?></td>
    <td><?= $runHtml ?></td>
    <td class="text-nowrap"><?= e(format_ts($d['created_at'], $tz, 'M j, g:i A')) ?><?= $d['answered_at'] !== null ? '<div class="text-muted">answered ' . e(format_ts($d['answered_at'], $tz, 'g:i A')) . '</div>' : '' ?></td>
    <td class="text-break" style="max-width: 18rem"><?= e(mb_strimwidth((string) ($d['reply_excerpt'] ?? ''), 0, 120, '…')) ?></td>
    <td class="text-end"><?= $retry ?></td>
</tr>
<?php else: ?>
<div class="card mb-2" id="dispatch-card-<?= $id ?>"><div class="card-body fs-12">
    <div class="d-flex justify-content-between gap-2"><span class="badge bg-soft-secondary text-secondary"><i class="feather-cpu me-1"></i><?= e($d['agent_name'] ?? '') ?></span><?= dispatch_status_chip($d) ?></div>
    <div class="mt-1 text-break"><?= e($d['detail'] ?? '') ?></div>
    <div class="text-muted mt-1">Asked by <?= e($d['asker_name'] ?? '—') ?> · <?= e($d['kind']) ?> · <?= (int) $d['attempts'] ?> call<?= (int) $d['attempts'] === 1 ? '' : 's' ?> · run <?= $runHtml ?> · <?= e(format_ts($d['created_at'], $tz, 'M j, g:i A')) ?></div>
    <?php if ((string) ($d['reply_excerpt'] ?? '') !== ''): ?><div class="mt-1"><?= e(mb_strimwidth((string) $d['reply_excerpt'], 0, 160, '…')) ?></div><?php endif; ?>
    <?php if ($retry !== ''): ?><div class="mt-2"><?= $retry ?></div><?php endif; ?>
</div></div>
<?php endif; ?>
