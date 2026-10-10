<?php /** A return's timeline (`return-view-timeline`): its rows of the activity log, oldest first. Data: rows, tz */ ?>
<div class="card mb-3" id="return-view-timeline"><div class="card-header fw-semibold">Timeline</div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
    <?php if ($rows === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $t): ?><tr id="return-timeline-row-<?= (int) $t['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($t['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($t)) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>
