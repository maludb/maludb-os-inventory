<?php /** The live search's answer (`search-result`): a card per source asked (`search-result-{source}`) with its status and its listings. Data: answer */ ?>
<div id="search-result">
    <?php foreach ($answer['asked'] as $a): ?>
    <div class="card mb-2" id="search-result-<?= (int) $a['source_id'] ?>"><div class="card-body fs-12">
        <div class="d-flex justify-content-between"><span class="fw-semibold"><?= e($a['source']) ?></span><span><?= $a['status'] === 'ok' ? src_chip('asked · ' . $a['listings_seen'] . ' found · ' . $a['ms'] . ' ms', 'success') : src_chip((string) $a['status'], 'warning') ?></span></div>
        <?php if ($a['error']): ?><div class="text-muted"><?= e((string) $a['error']) ?></div><?php endif; ?>
    </div></div>
    <?php endforeach; ?>
    <?php foreach ($answer['rows'] as $r): if ($r['kind'] !== 'unmatched') { continue; } $lv = $r['listing_variant']; ?>
        <div class="fs-12 border-bottom py-1"><?= hx_link('/listings/' . (int) $lv['listing_id'] . '?listing_variant=' . (int) $lv['listing_variant_id'], e($lv['title'])) ?> · <?= $lv['price'] !== null ? e(number_format((float) $lv['price'], 2)) : '' ?> · <?= availability_chip($lv['availability']) ?></div>
    <?php endforeach; ?>
</div>
