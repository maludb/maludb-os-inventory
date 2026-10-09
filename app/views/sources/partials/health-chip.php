<?php /** The health chip with its sentence (`source-health`). Data: s, id? */ ?>
<span class="d-inline-flex align-items-center gap-1" id="<?= e($id ?? 'source-health') ?>"><?= health_chip($s['health']) ?><span class="fs-12 text-muted"><?= e(health_sentence($s)) ?></span></span>
