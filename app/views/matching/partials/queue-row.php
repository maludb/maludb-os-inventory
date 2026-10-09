<?php /** One queue row (`queue-row-{listing_variant}`): the listing variant, its best proposal, and the four buttons (+ Score again). Data: r, here */
$lv = $r['listing_variant_id'];
$bp = $r['best_proposal'];
$ret = $here;
?>
<div class="card mb-2" id="queue-row-<?= $lv ?>"><div class="card-body py-2">
    <div class="row g-2 align-items-start">
        <div class="col-12 col-lg-5">
            <div class="fs-11 text-muted"><?= hx_link(with_back('/sources/' . $r['source_id'], $here), e($r['source_name']), 'text-muted') ?></div>
            <div><?= hx_link(with_back('/listings/' . $r['listing_id'] . '?listing_variant=' . $lv, $here), e($r['title']), 'fw-semibold') ?> <span class="fs-12">· <?= e((string) $r['variant_title']) ?></span></div>
            <div class="fs-12 text-muted"><?= e(implode(' · ', array_filter([$r['vendor'], $r['size_name'], $r['sku'] ? 'SKU ' . $r['sku'] : null, $r['barcode'] ? 'GTIN ' . $r['barcode'] : null, $r['mpn'] ? 'MPN ' . $r['mpn'] : null]))) ?></div>
            <div class="fs-12"><?= $r['price'] !== null ? e(number_format((float) $r['price'], 2)) . ' · ' : '' ?><?= availability_chip($r['availability']) ?></div>
        </div>
        <div class="col-12 col-lg-4" id="queue-row-<?= $lv ?>-best">
            <?php if ($bp === null): ?><span class="fs-12 text-muted">No proposal</span>
            <?php else: ?>
                <div><?= hx_link('/variants/' . (int) $bp['variant_id'], e((string) ($bp['sku'] ?? '')), 'fw-semibold') ?> <span class="fs-12 text-muted"><?= e((string) ($bp['product'] ?? '')) ?></span></div>
                <?= confidence_bar((float) $bp['confidence']) ?>
                <?= view('listings/partials/evidence.php', ['evidence' => (array) ($bp['evidence'] ?? []), 'id' => 'queue-row-' . $lv . '-evidence']) ?>
            <?php endif; ?>
        </div>
        <div class="col-12 col-lg-3">
            <div class="queue-actions">
                <?php if ($bp !== null): ?>
                <form method="post" action="/listings/proposals/accept.php" hx-post="/listings/proposals/accept.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= (int) $bp['proposal_id'] ?>"><input type="hidden" name="return_to" value="<?= e($ret) ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="queue-row-<?= $lv ?>-accept">Accept</button></form>
                <form method="post" action="/listings/proposals/dismiss.php" hx-post="/listings/proposals/dismiss.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= (int) $bp['proposal_id'] ?>"><input type="hidden" name="return_to" value="<?= e($ret) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="queue-row-<?= $lv ?>-dismiss">Dismiss</button></form>
                <?php else: ?>
                <form method="post" action="/listings/propose.php" hx-post="/listings/propose.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="listing_variant" value="<?= $lv ?>"><input type="hidden" name="return_to" value="<?= e($ret) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="queue-row-<?= $lv ?>-score">Score again</button></form>
                <?php endif; ?>
                <form method="post" action="/listings/forget.php" hx-post="/listings/forget.php" hx-target="#flash" hx-confirm="<?= e('Mark ' . $r['title'] . ' not ours? It leaves the queue and is never matched or scored again.') ?>"><?= csrf_field() ?><input type="hidden" name="listing" value="<?= $r['listing_id'] ?>"><input type="hidden" name="return_to" value="<?= e($ret) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="queue-row-<?= $lv ?>-forget">Not ours</button></form>
                <form method="post" action="/listings/match.php" hx-post="/listings/match.php" hx-target="#flash" class="queue-pick" id="queue-row-<?= $lv ?>-pick-form"><?= csrf_field() ?><input type="hidden" name="listing_variant" value="<?= $lv ?>"><input type="hidden" name="return_to" value="<?= e($ret) ?>">
                    <?= picker_field(['id' => 'queue-row-' . $lv . '-pick', 'name' => 'variant', 'source' => 'variant', 'params' => ['single' => '1'], 'placeholder' => 'Pick another…', 'required' => true]) ?>
                    <button type="submit" class="btn btn-light btn-touch" id="queue-row-<?= $lv ?>-pick-btn">Match</button></form>
                <?php if ($bp !== null): ?><form method="post" action="/listings/propose.php" hx-post="/listings/propose.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="listing_variant" value="<?= $lv ?>"><input type="hidden" name="return_to" value="<?= e($ret) ?>"><button type="submit" class="btn btn-link btn-touch w-100 fs-12" id="queue-row-<?= $lv ?>-score">Score again</button></form><?php endif; ?>
            </div>
        </div>
    </div>
</div></div>
