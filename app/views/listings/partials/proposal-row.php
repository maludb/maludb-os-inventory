<?php /** One proposal (`proposal-row-{id}`): the candidate, the confidence bar, the evidence, who proposed it; Accept, Dismiss for listings.match. Data: p, mayMatch, returnTo? */ $id = $p['proposal_id']; ?>
<div class="border-bottom py-2" id="proposal-row-<?= $id ?>">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <?= hx_link('/variants/' . $p['variant_id'], e($p['sku']), 'fw-semibold') ?><span class="fs-12 text-muted"><?= e((string) $p['product_name']) ?><?= !empty($p['size_name']) ? ' · ' . e($p['size_name']) : '' ?></span>
        <span class="ms-auto" style="min-width:8rem"><?= confidence_bar($p['confidence'], 'proposal-row-' . $id . '-confidence') ?></span>
    </div>
    <div><?= view('listings/partials/evidence.php', ['evidence' => $p['evidence']]) ?> <span class="fs-11 text-muted">· proposed by <?= e($p['proposed_by'] === null ? 'the matcher' : (string) ($p['proposed_by_name'] ?? 'someone')) ?></span></div>
    <?php if ($mayMatch && $p['status'] === 'proposed'): ?>
    <div class="d-flex gap-2 mt-1">
        <form method="post" action="/listings/proposals/accept.php" hx-post="/listings/proposals/accept.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>"><?php if (!empty($returnTo)): ?><input type="hidden" name="return_to" value="<?= e($returnTo) ?>"><?php endif; ?><button type="submit" class="btn btn-primary btn-touch" id="proposal-row-<?= $id ?>-accept">Accept</button></form>
        <form method="post" action="/listings/proposals/dismiss.php" hx-post="/listings/proposals/dismiss.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>"><?php if (!empty($returnTo)): ?><input type="hidden" name="return_to" value="<?= e($returnTo) ?>"><?php endif; ?><button type="submit" class="btn btn-light btn-touch" id="proposal-row-<?= $id ?>-dismiss">Dismiss</button></form>
    </div>
    <?php endif; ?>
</div>
