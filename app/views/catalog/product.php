<?php /** One product (screen `product-view`). Data: full (product_full), tab, may (write, delete, prices), deletable (?string), seesCost, vocab, tz, here, trail, notice */
$p = $full['product'];
$id = (int) $p['product_id'];
$variants = $full['variants'];
$tabs = ['variants' => 'Variants', 'identifiers' => 'Identifiers'] + ($p['kind'] === 'bundle' ? ['bundle' => 'Bundle'] : []) + ['images' => 'Images', 'sources' => 'Sources', 'prices' => 'Prices', 'notes' => 'Notes', 'trail' => 'Trail'];
if (!isset($tabs[$tab])) { $tab = 'variants'; }
$primary = null;
foreach ($full['images'] as $im) { if ($im['is_primary']) { $primary = $im; } }
$actions = '';
if ($may['write']) {
    $actions .= hx_link(with_back('/products/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch me-1', 'id="product-view-edit-btn"');
    $actions .= hx_link(with_back('/variants/new?product=' . $id, $here), '<i class="feather-plus me-1"></i>Add variant', 'btn btn-primary btn-touch me-1', 'id="product-view-add-variant-btn"');
    $actions .= hx_link(with_back('/products/' . $id . '/images', $here), '<i class="feather-image me-1"></i>Images', 'btn btn-light btn-touch me-1', 'id="product-view-images-btn"');
    $disc = $p['status'] === 'discontinued';
    $actions .= '<form method="post" action="/products/discontinue.php" hx-post="/products/discontinue.php" hx-target="#flash" class="d-inline" hx-confirm="' . ($disc ? 'Reactivate ' : 'Discontinue ') . e($p['name']) . '?' . ($disc ? '' : ' Its variants stop selling; stock and history stay.') . '">' . csrf_field()
        . '<input type="hidden" name="product" value="' . $id . '"><input type="hidden" name="discontinued" value="' . ($disc ? 'no' : 'yes') . '"><button type="submit" class="btn btn-light btn-touch me-1" id="product-view-discontinue-btn">' . ($disc ? 'Reactivate' : 'Discontinue') . '</button></form>';
}
if ($may['delete'] && $deletable === null) {
    $actions .= '<form method="post" action="/products/delete.php" hx-post="/products/delete.php" hx-target="#flash" class="d-inline" hx-confirm="Delete ' . e($p['name']) . ' and its variants? This cannot be undone.">' . csrf_field()
        . '<input type="hidden" name="product" value="' . $id . '"><button type="submit" class="btn btn-outline-danger btn-touch" id="product-view-delete-btn">Delete</button></form>';
}
$tabUrl = static fn (string $t): string => '/products/' . $id . ($t === 'variants' ? '' : '?tab=' . $t);
?>
<?= view('shared/header.php', ['id' => 'product-view', 'title' => $p['name'], 'crumbs' => [['Home', '/'], ['Products', '/products/'], [$p['name'], null]], 'back' => back_link() ?? ['/products/', 'Products'], 'action' => $actions]) ?>
<div class="main-content" id="product-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="product-view-header"><div class="card-body d-flex gap-3 align-items-start flex-wrap">
        <?php if ($primary !== null): ?><img class="product-header-image" src="/files/<?= $primary['attachment_id'] ?>/thumb" alt="<?= e($primary['alt_text'] ?? $p['name']) ?>" id="product-view-primary-image"><?php endif; ?>
        <div class="min-w-0 flex-grow-1">
            <h4 class="mb-1" id="product-view-name"><?= e($p['name']) ?></h4>
            <div class="chip-row mb-2">
                <?php if ($p['brand']): ?><?= $may['write'] ? hx_link(with_back('/brands/' . (int) $p['brand_id'] . '/edit', $here), e($p['brand']), 'badge bg-soft-secondary text-dark', 'id="product-view-brand"') : '<span class="badge bg-soft-secondary text-dark" id="product-view-brand">' . e($p['brand']) . '</span>' ?><?php endif; ?>
                <span class="badge bg-soft-light text-dark border" id="product-view-type"><?= e($p['product_type']) ?></span>
                <span id="product-view-status"><?= product_status_chip($p['status']) ?></span>
                <?php if ($p['kind'] === 'bundle'): ?><span class="badge bg-soft-info text-info" id="product-view-kind">bundle</span><?php endif; ?>
                <?php foreach ($p['tags'] as $t): ?><span class="badge bg-soft-light text-muted border"><?= e($t) ?></span><?php endforeach; ?>
            </div>
            <?php if ($p['description']): ?><div class="fs-12 text-muted" id="product-view-description"><?= nl2br(e(mb_substr($p['description'], 0, 600))) ?><?= mb_strlen($p['description']) > 600 ? '…' : '' ?></div><?php endif; ?>
            <?php if ($p['attributes'] !== []): ?><div class="fs-12 mt-2 chip-row" id="product-view-attributes"><?php foreach ($p['attributes'] as $k => $v): ?><span class="badge bg-soft-light text-dark border"><?= e($k) ?>: <?= e(is_array($v) ? implode(', ', $v) : (string) $v) ?></span><?php endforeach; ?></div><?php endif; ?>
            <div class="fs-12 text-muted mt-2">Options: <?= e(implode(' · ', $p['options'])) ?><?= $p['ships_how'] ? ' · ships ' . e(SHIPS_HOW[$p['ships_how']] ?? $p['ships_how']) : '' ?><?= $p['reorder_point'] !== null ? ' · reorder point ' . (int) $p['reorder_point'] : '' ?></div>
            <?php if ($deletable !== null && $may['delete']): ?><div class="fs-12 text-muted mt-1" id="product-view-not-deletable"><?= e($deletable) ?></div><?php endif; ?>
        </div>
    </div></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="product-view-tabs">
        <?php foreach ($tabs as $k => $label): ?><?= hx_link($tabUrl($k), e($label), 'btn btn-touch ' . ($tab === $k ? 'btn-primary' : 'btn-light'), 'id="product-view-tab-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($tab === 'variants'): ?>
        <?= view('catalog/partials/variants-table.php', ['variants' => $variants, 'product' => $p, 'seesCost' => $seesCost, 'vocab' => $vocab, 'here' => $here]) ?>
    <?php elseif ($tab === 'identifiers'): ?>
        <div class="card" id="product-identifiers"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12"><thead class="thead-light"><tr><th>Variant</th><th>Kind</th><th>Value</th><th>Source</th></tr></thead><tbody>
            <?php if ($full['identifiers'] === []): ?><tr><td colspan="4" class="text-center text-muted py-4" id="product-identifiers-empty">No identifiers beyond the barcodes yet. Each variant's page adds them.</td></tr><?php endif; ?>
            <?php foreach ($full['identifiers'] as $i): $v = null; foreach ($variants as $vv) { if ($vv['variant_id'] === (int) $i['variant_id']) { $v = $vv; } } ?>
                <tr id="product-identifier-<?= (int) $i['identifier_id'] ?>"><td><?= $v ? hx_link(with_back('/variants/' . $v['variant_id'] . '/identifiers', $here), e($v['sku'] . ($v['size_name'] ? ' · ' . $v['size_name'] : ''))) : '' ?></td><td><?= identifier_kind_chip($i['kind']) ?></td><td><code><?= e($i['value']) ?></code></td><td><?= $i['source_id'] !== null ? 'source #' . (int) $i['source_id'] : '' ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'bundle'): ?>
        <div class="row g-3" id="product-bundle">
        <?php foreach ($variants as $v): $comps = array_values(array_filter($full['bundle_components'], static fn (array $c): bool => (int) $c['bundle_variant_id'] === $v['variant_id'])); ?>
            <div class="col-12 col-lg-6"><div class="card h-100" id="product-bundle-<?= $v['variant_id'] ?>"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0"><?= e($v['sku']) ?><?= $v['size_name'] ? ' · ' . e($v['size_name']) : '' ?></h5><?= $may['write'] ? hx_link(with_back('/variants/' . $v['variant_id'] . '/bundle', $here), 'Edit components', 'btn btn-light btn-sm btn-touch') : '' ?></div>
                <div class="card-body">
                    <?php if ($comps === []): ?><div class="text-muted fs-12">No components yet — an empty bundle (a catalog gap).</div><?php else: ?>
                    <ul class="list-unstyled mb-2"><?php foreach ($comps as $c): ?><li class="fs-12"><?= (int) $c['qty'] ?> × <?= hx_link(with_back('/variants/' . (int) $c['component_variant_id'], $here), e($c['component_sku'] . ' — ' . $c['component_product'])) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <div class="fs-12">Sets available: <strong><?= (int) ($v['sets_available'] ?? 0) ?></strong> <?= state_chip($v['state'] ?? null) ?></div>
                </div></div></div>
        <?php endforeach; ?>
        </div>
    <?php elseif ($tab === 'images'): ?>
        <div class="row g-3" id="product-view-images">
            <?php if ($full['images'] === []): ?><div class="col-12"><div class="card"><div class="card-body text-muted fs-12" id="product-view-images-empty">No images yet.<?= $may['write'] ? ' ' . hx_link(with_back('/products/' . $id . '/images', $here), 'Upload one', 'fw-semibold') . '.' : '' ?></div></div></div><?php endif; ?>
            <?php foreach ($full['images'] as $im): ?><div class="col-6 col-md-3"><div class="card product-image-card" id="product-view-image-<?= $im['image_id'] ?>"><img src="/files/<?= $im['attachment_id'] ?>/thumb" alt="<?= e($im['alt_text'] ?? '') ?>" loading="lazy"><div class="card-body py-2 fs-12"><?= $im['is_primary'] ? '<i class="feather-star text-warning me-1"></i>' : '' ?><?= e($im['alt_text'] ?? $im['filename']) ?><?= $im['variant_sku'] ? '<div class="text-muted">' . e($im['variant_sku']) . '</div>' : '' ?></div></div></div><?php endforeach; ?>
        </div>
    <?php elseif ($tab === 'sources'): ?>
        <div class="card" id="product-sources"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12"><thead class="thead-light"><tr><th>Source</th><th>Title</th><th>Variants matched / live</th><th>Last seen</th></tr></thead><tbody>
            <?php if ($full['listings'] === []): ?><tr><td colspan="4" class="text-center text-muted py-4" id="product-sources-empty">No source lists this product yet — slice 3's pulls and matches fill this.</td></tr><?php endif; ?>
            <?php foreach ($full['listings'] as $l): ?><tr id="product-listing-<?= (int) $l['listing_id'] ?>"><td><?= e($l['source_name']) ?> <span class="badge bg-soft-light text-muted border"><?= e($l['source_role']) ?></span></td><td><?= hx_link(with_back('/listings/' . (int) $l['listing_id'], $here), e($l['title'])) ?></td><td><?= (int) $l['matched_count'] ?> / <?= (int) $l['variant_count'] ?></td><td><?= e(format_ts($l['last_seen_at'], $tz, 'M j, Y')) ?><?= $l['removed_at'] ? ' <span class="badge bg-soft-dark text-dark">removed</span>' : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'prices'): ?>
        <div class="card" id="product-prices"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>When</th><th>Variant</th><th>Change</th></tr></thead><tbody>
            <?php $any = false; foreach ($variants as $v): foreach (price_history(db(), $v['variant_id'], null, null, 20) as $h): $any = true; ?>
                <tr><td class="text-nowrap"><?= e(format_ts($h['changed_at'], $tz, 'M j, Y')) ?></td><td><?= hx_link(with_back('/variants/' . $v['variant_id'], $here), e($v['sku'])) ?></td><td><?= e(PRICE_KINDS[$h['kind']]) ?> <?= money($h['old_price']) ?> → <?= money($h['new_price']) ?><?= $h['changed_by_name'] ? ' by ' . e($h['changed_by_name']) : '' ?><?= $h['reason'] ? ' · ' . e($h['reason']) : '' ?><?= $h['source_kind'] !== 'manual' ? ' <span class="badge bg-soft-light text-muted border">' . e($h['source_kind']) . '</span>' : '' ?></td></tr>
            <?php endforeach; endforeach; if (!$any): ?><tr><td colspan="3" class="text-center text-muted py-4" id="product-prices-empty">No price changes yet.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'notes'): ?>
        <div class="row g-3" id="product-notes">
            <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div><div class="card-body">
                <?php if ($full['notes'] === []): ?><div class="text-muted fs-12" id="product-notes-empty">No notes yet. Notes are added in slice 8.</div><?php endif; ?>
                <?php foreach ($full['notes'] as $n): ?><div class="border-bottom py-2 fs-12" id="product-note-<?= (int) $n['note_id'] ?>"><div class="fw-semibold"><?= e($n['member_name'] ?? 'Someone') ?> <span class="text-muted fw-normal"><?= e(format_ts($n['created_at'], $tz)) ?></span></div><?= nl2br(e($n['body'])) ?></div><?php endforeach; ?>
            </div></div></div>
            <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="card-title mb-0">Attachments</h5></div><div class="card-body">
                <?php if ($full['attachments'] === []): ?><div class="text-muted fs-12" id="product-attachments-empty">Nothing attached. Attachments are added in slice 8; images on the Images page.</div><?php endif; ?>
                <?php foreach ($full['attachments'] as $a): ?><div class="py-1 fs-12"><a href="/files/<?= (int) $a['attachment_id'] ?>" target="_blank" rel="noopener"><i class="feather-paperclip me-1"></i><?= e($a['filename']) ?></a> <span class="text-muted">· <?= e(fmt_bytes((int) $a['byte_size'])) ?></span></div><?php endforeach; ?>
            </div></div></div>
        </div>
    <?php elseif ($tab === 'trail'): ?>
        <div class="card" id="product-trail"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="product-trail-table"><thead class="thead-light"><tr><th>When</th><th>What</th></tr></thead><tbody>
            <?php if ($trail === []): ?><tr><td colspan="2" class="text-center text-muted py-4">Nothing yet.</td></tr><?php endif; ?>
            <?php foreach ($trail as $r): ?><tr id="product-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>
