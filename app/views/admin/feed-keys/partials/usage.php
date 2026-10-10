<?php /** A key's usage (`feed-key-usage`): day buckets of the last 35 days, or the minute buckets of the last hour. Data: k (the key), usage [bucket, rows, totals], bucket, tz */
$kid = (int) $k['key_id'];
$minute = $bucket === 'minute';
$fmt = static fn (string $at): string => $minute ? format_ts($at, $tz, 'M j, g:i A') : format_ts($at, 'UTC', 'D M j, Y');
$slug = static fn (string $at): string => gmdate($minute ? 'YmdHi' : 'Ymd', (int) strtotime($at));
?>
<div class="card mt-3" id="feed-key-usage">
    <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h5 class="card-title mb-0">Usage of "<?= e($k['label']) ?>"</h5>
        <div class="d-flex gap-2">
            <?= hx_link('/admin/feed-keys/?key=' . $kid, 'Days', 'btn btn-' . ($minute ? 'light' : 'primary') . ' btn-touch', 'id="feed-key-usage-days-btn"') ?>
            <?= hx_link('/admin/feed-keys/?key=' . $kid . '&bucket=minute', 'Last hour', 'btn btn-' . ($minute ? 'primary' : 'light') . ' btn-touch', 'id="feed-key-usage-minutes-btn"') ?>
        </div>
    </div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12" id="feed-key-usage-table">
            <thead class="thead-light"><tr><th><?= $minute ? 'Minute' : 'Day (UTC)' ?></th><th class="text-end">Calls</th><th class="text-end">Refused</th></tr></thead>
            <tbody>
            <?php if ($usage['rows'] === []): ?><tr><td colspan="3" class="text-center text-muted py-4" id="feed-key-usage-empty"><?= $minute ? 'No calls in the last hour.' : 'No calls yet.' ?></td></tr><?php endif; ?>
            <?php foreach ($usage['rows'] as $r): ?>
                <tr id="feed-key-usage-row-<?= e($slug((string) $r['bucket_start'])) ?>"><td><?= e($fmt((string) $r['bucket_start'])) ?></td><td class="text-end"><?= number_format($r['calls']) ?></td><td class="text-end<?= $r['refused'] > 0 ? ' text-danger' : '' ?>"><?= number_format($r['refused']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($usage['rows'] !== []): ?><tfoot><tr class="fw-semibold" id="feed-key-usage-totals"><td>Total</td><td class="text-end"><?= number_format($usage['totals']['calls']) ?></td><td class="text-end"><?= number_format($usage['totals']['refused']) ?></td></tr></tfoot><?php endif; ?>
        </table>
    </div></div>
</div>
