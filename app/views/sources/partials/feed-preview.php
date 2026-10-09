<?php /** The feed's preview (`feed-preview`): the first five rows under their headers, the row count, the format and the transport. Data: p */ ?>
<div id="feed-preview" class="mt-2">
    <?php if (!$p['ok']): ?>
        <div class="alert alert-warning fs-12 mb-2" id="feed-preview-error">The file could not be read: <?= e((string) $p['error']) ?></div>
    <?php else: ?>
        <div class="fs-12 text-muted mb-1" id="feed-preview-facts"><?= (int) $p['row_count'] ?> rows · <?= e((string) $p['format']) ?> · via <?= e((string) $p['via']) ?></div>
        <div class="table-responsive"><table class="table table-sm mb-2 fs-12"><thead class="thead-light"><tr><?php foreach ($p['columns'] as $i => $c): ?><th title="column <?= $i ?>"><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach ($p['rows'] as $r): ?><tr><?php foreach ($r as $cell): ?><td><?= e((string) $cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if (!empty($p['mapped'])): ?>
            <div class="fs-12 fw-semibold mb-1">As mapped</div>
            <div class="table-responsive"><table class="table table-sm mb-2 fs-12" id="feed-preview-mapped"><thead class="thead-light"><tr><?php foreach (array_keys($p['mapped'][0]) as $k): ?><th><?= e($k) ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($p['mapped'] as $r): ?><tr><?php foreach ($r as $cell): ?><td><?= e(is_scalar($cell) || $cell === null ? (string) $cell : json_encode($cell)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
