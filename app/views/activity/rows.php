<?php /** The trail's list — the swapped region (`#trail-results`, `#trail-row-{id}`). Data: rows, page, more, query, tz */
$pageUrl = static fn (int $p): string => '/trail?' . http_build_query($query + ['page' => $p]);
?>
<div id="trail-results" class="card-body custom-card-action p-0">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="trail-list">
            <thead class="thead-light"><tr>
                <th id="trail-col-when">When</th><th id="trail-col-what">What</th><th id="trail-col-source" class="d-none d-md-table-cell">Source</th>
            </tr></thead>
            <tbody id="trail-tbody">
            <?php if ($rows === []): ?>
                <tr><td colspan="3" class="text-center text-muted py-4" id="trail-empty">Nothing here that you may see.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): $src = (string) ($r['source'] ?? ''); ?>
                <tr id="trail-row-<?= (int) $r['activity_id'] ?>">
                    <td class="text-nowrap fs-12" title="<?= e($r['occurred_at']) ?>"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td>
                    <td class="text-wrap"><?= e($r['sentence']) ?><?= ($u = activity_record_link($r)) !== null ? ' ' . hx_link(with_back($u[0], here_url()), e($u[1]), 'fs-12') : '' ?></td>
                    <td class="d-none d-md-table-cell"><span class="badge bg-soft-<?= $src === 'agent' ? 'info text-info' : ($src === 'web' ? 'secondary text-dark' : ($src === 'cron' ? 'dark text-dark' : 'warning text-warning')) ?>"><?= e($src) ?></span><?= !empty($r['agent_run_id']) ? ' <small class="text-muted">run #' . (int) $r['agent_run_id'] . '</small>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($page > 1 || $more): ?>
    <nav class="p-3" id="trail-pagination"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="<?= e($pageUrl($page - 1)) ?>" hx-get="<?= e($pageUrl($page - 1)) ?>" hx-target="#trail-results" hx-swap="outerHTML" hx-push-url="<?= e($pageUrl($page - 1)) ?>">Newer</a></li><?php endif; ?>
        <?php if ($more): ?><li class="page-item"><a class="page-link" href="<?= e($pageUrl($page + 1)) ?>" hx-get="<?= e($pageUrl($page + 1)) ?>" hx-target="#trail-results" hx-swap="outerHTML" hx-push-url="<?= e($pageUrl($page + 1)) ?>">Older</a></li><?php endif; ?>
    </ul></nav>
    <?php endif; ?>
</div>
