<?php /** A pull's policy facts as a definition list (`pull-row-{id}-policy`). Data: p */ $facts = policy_facts($p); ?>
<details id="pull-row-<?= (int) $p['pull_id'] ?>-policy" class="fs-12"><summary class="btn-touch d-inline-flex align-items-center">Policy</summary>
    <dl class="row mb-0 mt-1"><?php if ($facts === []): ?><dd class="col-12 text-muted">No facts recorded.</dd><?php endif; ?>
    <?php foreach ($facts as $k => $v): ?><dt class="col-5 col-md-3 text-muted fw-normal"><?= e($k) ?></dt><dd class="col-7 col-md-9 mb-1 text-break"><?= e($v) ?></dd><?php endforeach; ?></dl>
</details>
