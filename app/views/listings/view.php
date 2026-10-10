<?php /** One listing (screen `listing-view`). Data: l, full, sel, sinceDays, history, chart, summary, mayMatch, seesCost, trail, tz, here, notice */
$id = $l['listing_id'];
$selRow = null;
foreach ($full['variants'] as $v) { if ($v['listing_variant_id'] === $sel) { $selRow = $v; } }
$actions = '';
if ($mayMatch && $l['forgotten_at'] === null) {
    $actions = '<form method="post" action="/listings/forget.php" hx-post="/listings/forget.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Mark ' . $l['title'] . ' not ours? Every variant is unmatched, it leaves the queue and is never matched or scored again; its offers stay.') . '">' . csrf_field() . '<input type="hidden" name="listing" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="listing-forget">Not ours</button></form>';
}
$props = array_values(array_filter($full['proposals'], static fn ($p) => $p['status'] === 'proposed'));
?>
<?= view('shared/header.php', ['id' => 'listing-view', 'title' => $l['title'] !== '' ? $l['title'] : $l['external_id'], 'crumbs' => [['Home', '/'], ['Sources', '/sources/'], [$l['source_name'], '/sources/' . $l['source_id']], ['Listing', null]],
    'back' => back_link() ?? ['/sources/' . $l['source_id'] . '/listings', $l['source_name']], 'action' => $actions]) ?>
<div class="main-content" id="listing-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="listing-view-summary"><div class="card-body fs-12">
        <div class="d-flex flex-wrap gap-2 align-items-center">from <?= hx_link(with_back('/sources/' . $l['source_id'], $here), e($l['source_name']), 'fw-semibold') ?>
            <?php if ($l['vendor']): ?><span class="badge bg-soft-secondary text-dark"><?= e($l['vendor']) ?></span><?php endif; ?><?php if ($l['product_type']): ?><span class="badge bg-soft-light text-dark border"><?= e($l['product_type']) ?></span><?php endif; ?>
            <?php if ($l['removed_at'] !== null): ?><span class="badge bg-soft-dark text-dark" id="listing-view-removed">removed</span><?php endif; ?>
            <?php if ($l['forgotten_at'] !== null): ?><span class="badge bg-soft-dark text-dark" id="listing-view-forgotten">not ours</span><?php endif; ?></div>
        <?php if ($l['tags'] !== []): ?><div class="text-muted mt-1"><?= e(implode(', ', $l['tags'])) ?></div><?php endif; ?>
        <?php if ($l['url']): ?><div class="mt-1 text-break"><a href="<?= e($l['url']) ?>" rel="noopener nofollow" target="_blank" id="listing-view-url"><?= e($l['url']) ?></a></div><?php endif; ?>
        <div class="text-muted mt-1">First seen <?= e(format_ts($l['first_seen_at'], $tz, 'M j, Y')) ?> · last seen <?= e(ago($l['last_seen_at'])) ?> · id <?= e($l['external_id']) ?></div>
    </div></div>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="listing-variants-table">
        <thead class="thead-light"><tr><th>Variant</th><th class="d-none d-md-table-cell">Size</th><th class="d-none d-md-table-cell">SKU</th><th class="d-none d-lg-table-cell">Barcode</th><th class="d-none d-lg-table-cell">MPN</th><th class="text-end">Price</th><th class="text-end d-none d-md-table-cell">Cost</th><th>Availability</th><th class="d-none d-lg-table-cell">Ships</th><th>Match</th><th></th></tr></thead><tbody>
        <?php foreach ($full['variants'] as $v): ?><?= view('listings/partials/listing-variant-row.php', ['v' => $v, 'l' => $l, 'sel' => $sel, 'mayMatch' => $mayMatch, 'seesCost' => $seesCost, 'here' => $here]) ?><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($selRow !== null): ?>
    <div class="card mb-3" id="listing-offer-card"><div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0 me-auto">Offers of <?= e($selRow['title']) ?></h5>
        <div class="d-flex gap-1" id="listing-offer-since"><?php foreach ([30, 90, 180, 365] as $d): ?><?= hx_link('/listings/' . $id . '?listing_variant=' . $sel . '&since=' . $d, $d . ' days', 'btn btn-sm btn-touch ' . ($sinceDays === $d ? 'btn-primary' : 'btn-light'), 'id="listing-offer-since-' . $d . '"') ?><?php endforeach; ?></div></div>
        <div class="card-body">
            <div class="fs-12 text-muted mb-2" id="listing-offer-summary"><?= (int) $summary['points'] ?> points · <?= (int) $summary['changes'] ?> changes<?= $summary['price_min'] !== null ? ' · ' . number_format($summary['price_min'], 2) . '–' . number_format($summary['price_max'], 2) : '' ?><?= $summary['days_out_of_stock'] > 0 ? ' · ' . $summary['days_out_of_stock'] . ' days out of stock' : '' ?></div>
            <?= view('shared/series-chart.php', ['id' => 'listing-offer-chart', 'series' => $chart['series'], 'bands' => $chart['bands'], 'unit' => (string) ($selRow['currency'] ?? 'USD'), 'table_id' => 'listing-offer-table', 'empty' => 'No offers recorded in this window.', 'title' => 'Price', 'tz' => $tz]) ?>
            <div class="table-responsive mt-2"><table class="table mb-0 fs-12" id="listing-offer-table"><thead class="thead-light"><tr><th>Observed</th><th class="text-end">Price</th><th class="text-end">Compare-at</th><th class="text-end">Cost</th><th>Availability</th><th class="text-end">Qty</th><th class="text-end">Lead</th><th>Pull</th></tr></thead><tbody>
                <?php if ($history === []): ?><tr><td colspan="8" class="text-center text-muted py-3">None.</td></tr><?php endif; ?>
                <?php foreach (array_reverse($history) as $r): ?><tr class="<?= $r['is_heartbeat'] ? 'text-muted' : '' ?>"><td class="text-nowrap"><?= e(format_ts($r['observed_at'], $tz, 'M j, g:i A')) ?><?= $r['is_heartbeat'] ? ' <span class="badge bg-soft-light text-muted border" title="a daily snapshot whatever changed">heartbeat</span>' : '' ?></td>
                    <td class="text-end"><?= $r['price'] !== null ? e(number_format((float) $r['price'], 2)) : '—' ?></td><td class="text-end"><?= $r['compare_at_price'] !== null ? e(number_format((float) $r['compare_at_price'], 2)) : '' ?></td>
                    <td class="text-end"><?= cost_cell_src($r['cost_price'], $seesCost) ?></td><td><?= availability_chip($r['availability']) ?></td><td class="text-end"><?= $r['qty'] ?? '' ?></td><td class="text-end"><?= $r['lead_time_days'] ?? '' ?></td>
                    <td><?= $r['pull_id'] !== null ? hx_link('/sources/' . $l['source_id'] . '/pulls#pull-row-' . (int) $r['pull_id'], '#' . (int) $r['pull_id']) : '' ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div></div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card mb-3" id="listing-proposals"><div class="card-header"><h5 class="card-title mb-0">Proposals</h5></div><div class="card-body">
                <?php if ($props === []): ?><div class="fs-12 text-muted" id="listing-proposals-empty">No pending proposals.</div><?php endif; ?>
                <?php foreach ($props as $p): ?><?= view('listings/partials/proposal-row.php', ['p' => $p, 'mayMatch' => $mayMatch]) ?><?php endforeach; ?>
            </div></div>
            <?php if ($mayMatch && $full['raw'] !== null): ?><?= view('listings/partials/raw-fields.php', ['raw' => $full['raw']]) ?><?php endif; ?>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card mb-3" id="listing-sold"><div class="card-header"><h5 class="card-title mb-0">Sold against</h5></div><div class="card-body fs-12">
                <?php if ($full['sold_lines'] === []): ?><div class="text-muted">Nothing sold against these offers yet.</div><?php endif; ?>
                <?php foreach ($full['sold_lines'] as $sl): ?><div><?= hx_link('/orders/' . (int) ($sl['sales_order_id'] ?? 0), e((string) ($sl['order_number'] ?? ('order #' . (int) ($sl['sales_order_id'] ?? 0))))) ?> · <?= e((string) ($sl['sku'] ?? '')) ?> × <?= (int) ($sl['qty'] ?? 0) ?></div><?php endforeach; ?>
            </div></div>
            <div class="card mb-3" id="listing-trail"><div class="card-header"><h5 class="card-title mb-0">Trail</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
                <?php if ($trail === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
                <?php foreach ($trail as $r): ?><tr><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
        </div>
    </div>
    <?= view('shared/notes.php', ['recordType' => 'listing', 'recordId' => (int) $id, 'tz' => $tz]) ?>
    <?= view('shared/attachments.php', ['recordType' => 'listing', 'recordId' => (int) $id, 'tz' => $tz]) ?>
</div>
