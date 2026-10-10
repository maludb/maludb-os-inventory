<?php /** One Find card (`find-card-{variant_id}`). Data: r (an inv_find() row), location (the best sellable location, or null), may, seesCost, here */
$id = $r['variant_id'];
$sell = sell_url($id, card_fulfilment($r, $location));
?>
<div class="card h-100" id="find-card-<?= $id ?>" data-variant-id="<?= $id ?>">
    <div class="card-body">
        <div class="fw-semibold"><?= hx_link(with_back('/products/' . $r['product_id'], $here), e($r['product_name']), 'text-dark', 'id="find-card-' . $id . '-product"') ?></div>
        <div class="fs-12 text-muted"><?= e(implode(' · ', array_filter([$r['brand'], $r['product_type']]))) ?><?php if ($r['size_name']): ?> <span class="badge bg-soft-secondary text-dark"><?= e($r['size_name']) ?></span><?php endif; ?><?= $r['kind'] === 'bundle' ? ' <span class="badge bg-soft-info text-info">set</span>' : '' ?></div>
        <div class="fs-12 mt-1"><?= hx_link(with_back('/variants/' . $id, $here), '<code>' . e($r['sku']) . '</code>', '', 'id="find-card-' . $id . '-sku"') ?><?= $r['barcode'] ? ' · GTIN ' . e($r['barcode']) : '' ?><?= $r['mpn'] ? ' · MPN ' . e($r['mpn']) : '' ?></div>
        <div class="d-flex justify-content-between align-items-center mt-2">
            <?= find_state_chip($r['state'], 'find-card-' . $id . '-state') ?>
            <div class="fs-12 text-end" id="find-card-<?= $id ?>-prices">Retail <?= money($r['retail_price']) ?><?= $r['map_price'] !== null ? ' · MAP ' . money($r['map_price']) : '' ?><?php if ($seesCost): ?> · Cost <?= money($r['cost_price']) ?><?php endif; ?></div>
        </div>
        <div class="mt-2" id="find-card-<?= $id ?>-availability" hx-get="/find/availability?variant=<?= $id ?>" hx-trigger="intersect once, offerChanged from:body" hx-swap="innerHTML">
            <div class="fs-12" id="find-card-<?= $id ?>-facts"><?= e(find_compact_facts($r)) ?> · <?= hx_link(with_back('/variants/' . $id, $here), 'Details') ?></div>
        </div>
        <div class="d-grid gap-2 mt-3" id="find-card-<?= $id ?>-actions">
            <?php if ($may['sell']): ?><a href="<?= e($sell) ?>" class="btn btn-primary btn-touch" id="find-card-<?= $id ?>-sell">Sell this</a><?php endif; ?>
            <?php if ($may['watch']): ?><a href="/watches/form?variant=<?= $id ?>&amp;return_to=<?= e(rawurlencode($here)) ?>" hx-get="/watches/form?variant=<?= $id ?>&amp;return_to=<?= e(rawurlencode($here)) ?>" hx-target="#find-card-<?= $id ?>-watch-form" hx-swap="innerHTML" class="btn btn-outline-secondary btn-touch" id="find-card-<?= $id ?>-watch"><i class="feather-eye me-1"></i>Watch</a><?php endif; ?>
        </div>
        <div id="find-card-<?= $id ?>-watch-form"></div>
    </div>
</div>
