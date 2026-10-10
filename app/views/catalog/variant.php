<?php /** One variant (screen `variant-view`). Data: full (variant_full), product, may (write, delete, prices, cost), deletable, seesCost, vocab, tz, here, trail, notice, history, series */
$v = $full['variant'];
$vid = $v['variant_id'];
$a = $full['availability'];
$actions = '';
if ($may['write']) { $actions .= hx_link(with_back('/variants/' . $vid . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch me-1', 'id="variant-view-edit-btn"'); }
if ($may['delete'] && $deletable === null) {
    $actions .= '<form method="post" action="/variants/delete.php" hx-post="/variants/delete.php" hx-target="#flash" class="d-inline" hx-confirm="Delete ' . e($v['sku']) . '? This cannot be undone.">' . csrf_field()
        . '<input type="hidden" name="variant" value="' . $vid . '"><button type="submit" class="btn btn-outline-danger btn-touch" id="variant-view-delete-btn">Delete</button></form>';
}
$own = $a['own'] ?? [];
$offers = array_merge($a['offers'] ?? [], $a['references'] ?? []);
?>
<?= view('shared/header.php', ['id' => 'variant-view', 'title' => $v['sku'], 'crumbs' => [['Home', '/'], ['Products', '/products/'], [$product['name'], '/products/' . $v['product_id']], [$v['sku'], null]], 'back' => back_link() ?? ['/products/' . $v['product_id'], $product['name']], 'action' => $actions]) ?>
<div class="main-content" id="variant-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="variant-view-header"><div class="card-body">
        <h4 class="mb-1"><?= hx_link(with_back('/products/' . $v['product_id'], $here), e($product['name']), 'text-dark', 'id="variant-view-product-link"') ?> <span class="text-muted">·</span> <code id="variant-view-sku"><?= e($v['sku']) ?></code></h4>
        <div class="chip-row">
            <?php if ($v['size_name']): ?><span class="badge bg-soft-secondary text-dark" id="variant-view-size"><?= e($v['size_name']) ?></span><?php endif; ?>
            <?php foreach ($v['option_values'] as $k => $val): if (strcasecmp((string) $k, 'Size') === 0) { continue; } ?><span class="badge bg-soft-light text-dark border"><?= e($k) ?>: <?= e((string) $val) ?></span><?php endforeach; ?>
            <?= product_status_chip($product['status']) ?>
            <?= $v['active'] ? '<span class="badge bg-soft-success text-success" id="variant-view-active">active</span>' : '<span class="badge bg-soft-dark text-dark" id="variant-view-active">inactive</span>' ?>
            <?= state_chip($a['state'] ?? null) ?>
        </div>
        <div class="fs-12 text-muted mt-2"><?= $v['barcode'] ? 'GTIN ' . e($v['barcode']) . ' · ' : '' ?><?= $v['mpn'] ? 'MPN ' . e($v['mpn']) . ' · ' : '' ?><?= weight_display($v['weight_g'], $vocab['units']) ?><?= $v['length_mm'] !== null ? ' · ' . length_display($v['length_mm'], $vocab['units']) . ' × ' . length_display($v['width_mm'], $vocab['units']) . ' × ' . length_display($v['height_mm'], $vocab['units']) : '' ?><?= $v['ships_how'] ? ' · ships ' . e(SHIPS_HOW[$v['ships_how']] ?? $v['ships_how']) : '' ?></div>
        <?php if ($deletable !== null && $may['delete']): ?><div class="fs-12 text-muted mt-1" id="variant-view-not-deletable"><?= e($deletable) ?></div><?php endif; ?>
    </div></div>
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card mb-3" id="variant-own-stock"><div class="card-header"><h5 class="card-title mb-0">Own stock</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Location</th><th class="text-end">On hand</th><th class="text-end">Allocated</th><th class="text-end">Floor</th><th class="text-end">Available</th></tr></thead><tbody>
                <?php if ($product['kind'] === 'bundle'): ?><tr><td colspan="5" class="text-muted py-3 text-center" id="variant-own-stock-bundle">A bundle holds no stock — its components do. Sets available: <strong><?= (int) ($a['sets_available'] ?? 0) ?></strong></td></tr>
                <?php elseif ($own === []): ?><tr><td colspan="5" class="text-muted py-3 text-center" id="variant-own-stock-empty">Nothing on hand anywhere.</td></tr><?php endif; ?>
                <?php foreach ($own as $o): ?><tr><td><?= e($o['location']) ?><?= empty($o['sellable']) ? ' <span class="badge bg-soft-light text-muted border">not sellable</span>' : '' ?></td><td class="text-end"><?= (int) $o['on_hand'] ?></td><td class="text-end"><?= (int) $o['allocated'] ?></td><td class="text-end"><?= (int) $o['floor_model'] ?></td><td class="text-end"><?= (int) $o['available'] ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
            <div class="card mb-3" id="variant-offers"><div class="card-header"><h5 class="card-title mb-0">Offers</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Source</th><th>Availability</th><th class="text-end">Cost</th><th class="text-end">Price</th><th>Lead</th><th>As of</th></tr></thead><tbody>
                <?php if ($offers === []): ?><tr><td colspan="6" class="text-muted py-3 text-center" id="variant-offers-empty">No source offers this yet — slice 3's pulls fill this.</td></tr><?php endif; ?>
                <?php foreach ($offers as $o): ?><?= view('catalog/partials/offer-row.php', ['o' => $o, 'seesCost' => $seesCost, 'tz' => $tz]) ?><?php endforeach; ?>
            </tbody></table></div></div></div>
            <div class="card mb-3" id="variant-identifiers"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Identifiers</h5><?= $may['write'] ? hx_link(with_back('/variants/' . $vid . '/identifiers', $here), 'Manage', 'btn btn-light btn-sm btn-touch', 'id="variant-view-identifiers-btn"') : '' ?></div><div class="card-body">
                <?php if ($v['barcode']): ?><div class="fs-12 mb-1"><?= identifier_kind_chip('gtin') ?> <code><?= e($v['barcode']) ?></code> <span class="text-muted">(the barcode)</span></div><?php endif; ?>
                <?php if ($v['mpn']): ?><div class="fs-12 mb-1"><?= identifier_kind_chip('mpn') ?> <code><?= e($v['mpn']) ?></code></div><?php endif; ?>
                <?php foreach ($full['identifiers'] as $i): ?><div class="fs-12 mb-1" id="variant-identifier-<?= (int) $i['identifier_id'] ?>"><?= identifier_kind_chip($i['kind']) ?> <code><?= e($i['value']) ?></code><?= $i['source_name'] ? ' <span class="text-muted">· ' . e($i['source_name']) . '</span>' : '' ?></div><?php endforeach; ?>
                <?php if ($full['identifiers'] === [] && !$v['barcode'] && !$v['mpn']): ?><div class="text-muted fs-12" id="variant-identifiers-empty">No identifiers yet.</div><?php endif; ?>
            </div></div>
            <?php if ($product['kind'] === 'bundle' || $full['bundles_containing'] !== []): ?>
            <div class="card mb-3" id="variant-bundle"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Bundle</h5><?= $product['kind'] === 'bundle' && $may['write'] ? hx_link(with_back('/variants/' . $vid . '/bundle', $here), 'Edit components', 'btn btn-light btn-sm btn-touch', 'id="variant-view-bundle-btn"') : '' ?></div><div class="card-body fs-12">
                <?php if ($product['kind'] === 'bundle'): ?>
                    <?php $comps = $a['components'] ?? []; if ($comps === []): ?><div class="text-muted">No components yet.</div><?php endif; ?>
                    <?php foreach ($comps as $c): ?><div><?= (int) $c['qty'] ?> × <?= hx_link(with_back('/variants/' . (int) $c['variant_id'], $here), e($c['sku'] . ' — ' . $c['product'])) ?> <span class="text-muted">· <?= (int) $c['own_available'] ?> available → <?= (int) $c['sets_from_stock'] ?> sets</span></div><?php endforeach; ?>
                    <div class="mt-2">Sets available: <strong><?= (int) ($a['sets_available'] ?? 0) ?></strong><?= ($a['best_lead_time_days'] ?? null) !== null ? ' · best lead time ' . (int) $a['best_lead_time_days'] . ' days' : '' ?></div>
                <?php else: ?>
                    <div class="text-muted mb-1">Part of:</div>
                    <?php foreach ($full['bundles_containing'] as $b): ?><div><?= hx_link(with_back('/variants/' . (int) $b['bundle_variant_id'], $here), e($b['sku'] . ' — ' . $b['product_name'])) ?> <span class="text-muted">× <?= (int) $b['qty'] ?></span></div><?php endforeach; ?>
                <?php endif; ?>
            </div></div>
            <?php endif; ?>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card mb-3" id="variant-prices"><div class="card-header"><h5 class="card-title mb-0">Prices (<?= e($vocab['currency']) ?>)</h5></div><div class="card-body">
                <div class="row g-2 mb-3 fs-12">
                    <div class="col-4"><div class="text-muted">Retail</div><div class="fs-5 fw-semibold" id="variant-price-retail"><?= money($v['retail_price']) ?></div></div>
                    <div class="col-4"><div class="text-muted">MAP</div><div class="fs-5 fw-semibold" id="variant-price-map"><?= money($v['map_price']) ?></div></div>
                    <div class="col-4"><div class="text-muted">Cost<?= $seesCost && ($a['prices']['margin_pct'] ?? null) !== null ? ' · margin ' . e((string) $a['prices']['margin_pct']) . '%' : '' ?></div><div class="fs-5 fw-semibold" id="variant-price-cost"<?= $v['cost_withheld'] ? ' title="cost withheld"' : '' ?>><?= $v['cost_withheld'] ? '—' : money($v['cost_price']) ?></div></div>
                </div>
                <?php if ($may['prices']): ?>
                    <?= view('catalog/partials/price-form.php', ['kind' => 'retail', 'vid' => $vid, 'current' => $v['retail_price']]) ?>
                    <?= view('catalog/partials/price-form.php', ['kind' => 'map', 'vid' => $vid, 'current' => $v['map_price']]) ?>
                    <?php if ($may['cost']): ?><?= view('catalog/partials/price-form.php', ['kind' => 'cost', 'vid' => $vid, 'current' => $v['cost_price']]) ?><?php endif; ?>
                <?php endif; ?>
            </div></div>
            <div class="card mb-3" id="variant-price-history"><div class="card-header"><h5 class="card-title mb-0">Price history</h5></div><div class="card-body">
                <?= view('shared/series-chart.php', ['id' => 'variant-price-chart', 'series' => $series, 'bands' => [], 'unit' => $vocab['currency'], 'table_id' => 'variant-price-history-table', 'empty' => 'No price changes yet.', 'title' => 'Price history', 'tz' => $tz]) ?>
                <div class="table-responsive mt-2"><table class="table mb-0 fs-12" id="variant-price-history-table"><thead class="thead-light"><tr><th>When</th><th>Kind</th><th>Change</th><th class="d-none d-md-table-cell">By</th><th>Reason</th></tr></thead><tbody>
                    <?php if ($history === []): ?><tr><td colspan="5" class="text-center text-muted py-3">No price changes yet.</td></tr><?php endif; ?>
                    <?php foreach ($history as $h): ?><tr><td class="text-nowrap"><?= e(format_ts($h['changed_at'], $tz, 'M j, Y')) ?></td><td><?= e(PRICE_KINDS[$h['kind']]) ?></td><td><?= money($h['old_price']) ?> → <?= money($h['new_price']) ?></td><td class="d-none d-md-table-cell"><?= e($h['changed_by_name'] ?? '') ?></td><td><?= e($h['reason'] ?? '') ?><?= $h['source_kind'] !== 'manual' ? ' <span class="badge bg-soft-light text-muted border">' . e($h['source_kind']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div></div>
            <div class="card mb-3" id="variant-open-lines"><div class="card-header"><h5 class="card-title mb-0">Open lines</h5></div><div class="card-body fs-12">
                <?php if ($full['open_lines'] === []): ?><div class="text-muted" id="variant-open-lines-empty">No open order or purchase lines — slices 5 and 6 write them.</div><?php endif; ?>
                <?php foreach ($full['open_lines'] as $l): ?><div><?= $l['side'] === 'sale' ? 'Order' : 'Purchase order' ?> <?= hx_link(with_back(($l['side'] === 'sale' ? '/orders/' : '/purchasing/') . (int) $l['record_id'], $here), e($l['number'])) ?> · <?= (int) $l['qty'] ?> · <?= e($l['status']) ?></div><?php endforeach; ?>
            </div></div>
            <div class="card mb-3" id="variant-watches"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Watches</h5><?php if (has_right('watches.own')): $wf = '/watches/form?variant=' . $vid . '&return_to=' . rawurlencode('/variants/' . $vid); ?><a href="<?= e($wf) ?>" hx-get="<?= e($wf) ?>" hx-target="#variant-watch-form" hx-swap="innerHTML" class="btn btn-light btn-sm btn-touch" id="variant-view-watch-btn"><i class="feather-eye me-1"></i>Watch</a><?php endif; ?></div><div class="card-body fs-12">
                <div id="variant-watch-form"></div>
                <?php if ($full['watches'] === []): ?><div class="text-muted" id="variant-watches-empty">Nobody is watching this variant.</div><?php endif; ?>
                <?php foreach ($full['watches'] as $w): ?><div id="variant-watch-<?= (int) $w['watch_id'] ?>"><?= e($w['member_name'] ?? '') ?> · <?= e(str_replace('_', ' ', $w['kind'])) ?><?= $w['threshold'] !== null ? ' ' . e((string) $w['threshold']) : '' ?><?= $w['active'] ? '' : ' <span class="badge bg-soft-dark text-dark">off</span>' ?></div><?php endforeach; ?>
            </div></div>
            <div class="card mb-3" id="variant-trail"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Trail</h5><?= hx_link(with_back('/trail?variant=' . $vid, $here), 'All', 'btn btn-light btn-sm btn-touch') ?></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
                <?php if ($trail === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
                <?php foreach (array_slice($trail, 0, 10) as $r): ?><tr id="variant-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
        </div>
    </div>
    <?= view('shared/notes.php', ['recordType' => 'product_variant', 'recordId' => (int) $vid, 'tz' => $tz]) ?>
    <?= view('shared/attachments.php', ['recordType' => 'product_variant', 'recordId' => (int) $vid, 'tz' => $tz]) ?>
</div>
