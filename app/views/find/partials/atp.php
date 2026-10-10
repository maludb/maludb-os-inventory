<?php /** The promise line (`atp-{id}`). Data: atp (inv_atp()), vid, seesCost */
$ok = !empty($atp['can_promise']);
$from = ($atp['from'] ?? null) === 'stock' ? ($atp['location'] ?? '') : (($atp['from'] ?? null) === 'source' ? ($atp['supplier'] ?? $atp['source'] ?? '') . ($seesCost && ($atp['cost'] ?? null) !== null ? ' at cost ' . money((string) $atp['cost']) : '') : '');
?>
<div id="atp-<?= $vid ?>" class="<?= $ok ? 'text-muted' : 'alert alert-warning py-2 px-3 mb-0 text-danger' ?> mt-1 fs-12" role="status">
    <?= e(ucfirst((string) ($atp['reason'] ?? ''))) ?><?php if ($ok): ?> — from <?= e($from) ?>, by <?= e(format_date((string) $atp['by'])) ?><?= !empty($atp['stale']) ? ' <span class="badge bg-soft-warning text-warning">stale</span>' : '' ?><?php endif; ?>
</div>
