<?php /** The order's timeline (`order-timeline`, rows `order-timeline-{n}`). Data: rows (order_timeline()), tz, seesCost */ ?>
<div class="card" id="order-timeline"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
    <?php if ($rows === []): ?><tr><td class="text-muted text-center py-3">Nothing yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $i => $r): ?><tr id="order-timeline-<?= $i ?>" data-kind="<?= e($r['kind']) ?>"><td class="text-nowrap"><?= e(format_ts($r['at'], $tz, 'M j, g:i A')) ?></td><td><?= e($r['title']) ?><?php if ($r['kind'] === 'po_draft' && $seesCost && isset($r['detail']['total'])): ?> <span class="text-muted">(<?= money((string) $r['detail']['total']) ?>)</span><?php endif; ?></td><td class="text-muted d-none d-md-table-cell"><?= e($r['member_name'] ?? '') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>
