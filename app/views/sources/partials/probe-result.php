<?php /** A probe's answer (`source-probe-result`): the state badge, the sentence, the facts as a definition list; a feed's columns link to the mapping. Data: res, s */
$c = ['ok' => 'success', 'blocked' => 'danger'][$res['state']] ?? 'warning';
?>
<div id="source-probe-result" class="mt-2">
    <div class="d-flex align-items-start gap-2"><span class="badge bg-soft-<?= $c ?> text-<?= $c ?>" id="source-probe-state"><?= e($res['state']) ?></span><span class="fs-12" id="source-probe-message"><?= e($res['message']) ?></span></div>
    <?php if (!empty($res['facts'])): ?>
    <dl class="row fs-12 mt-2 mb-0" id="source-probe-facts">
        <?php foreach ($res['facts'] as $k => $v): ?><dt class="col-5 col-md-3 text-muted fw-normal"><?= e(str_replace('_', ' ', (string) $k)) ?></dt><dd class="col-7 col-md-9 mb-1 text-break"><?= e(is_array($v) ? implode(', ', array_map('strval', $v)) : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v)) ?></dd><?php endforeach; ?>
    </dl>
    <?php if (!empty($res['facts']['columns']) && ($s['connector'] ?? '') === 'feed'): ?><div class="fs-12 mt-1"><?= hx_link('/sources/' . (int) $s['source_id'] . '/edit#feed-mapping', 'Map these columns', 'fw-semibold', 'id="source-probe-map-link"') ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($res['pull_id'])): ?><div class="fs-11 text-muted mt-1">Recorded as probe #<?= (int) $res['pull_id'] ?>.</div><?php endif; ?>
</div>
