<?php /** One template card (`template-card-{id}`). Data: t, mayWrite */ $id = (int) $t['template_id']; ?>
<div class="card h-100" id="template-card-<?= $id ?>">
    <div class="card-body">
        <div class="fw-semibold"><?= e($t['name']) ?></div>
        <div class="chip-row mt-1"><?= connector_badge($t['connector']) ?> <?= role_chip($t['role']) ?> <span id="template-card-<?= $id ?>-survey"><?= survey_chip($t['survey_result']) ?></span></div>
        <?php if ($t['base_url']): ?><div class="fs-12 mt-2 text-truncate"><a href="<?= e($t['base_url']) ?>" rel="noopener nofollow" target="_blank"><?= e(preg_replace('#^https?://#', '', (string) $t['base_url'])) ?></a></div><?php endif; ?>
        <?php if ($t['brand_hint']): ?><div class="fs-12 text-muted">Brand: <?= e($t['brand_hint']) ?></div><?php endif; ?>
        <?php if ($t['surveyed_at']): ?><div class="fs-12 text-muted">Surveyed <?= e(date('M j, Y', (int) strtotime((string) $t['surveyed_at']))) ?></div><?php endif; ?>
        <?php if ($t['notes']): ?><div class="fs-12 mt-2"><?= e($t['notes']) ?></div><?php endif; ?>
    </div>
    <?php if ($mayWrite): ?><div class="card-footer"><?= hx_link('/sources/new?template=' . rawurlencode((string) $t['key']), 'Add from this template', 'btn btn-light btn-touch w-100', 'id="template-card-' . $id . '-add"') ?></div><?php endif; ?>
</div>
