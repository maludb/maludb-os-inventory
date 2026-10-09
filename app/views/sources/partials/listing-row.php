<?php /** One listing (`listing-row-{id}`) with its variants under a <details> (`listing-row-{id}-variants`). Data: l, seesCost, here */
$id = $l['listing_id'];
$live = array_values(array_filter($l['variants'], static fn ($v) => $v['removed_at'] === null));
$matched = count(array_filter($live, static fn ($v) => $v['variant_id'] !== null));
?>
<tr id="listing-row-<?= $id ?>" class="<?= $l['removed_at'] !== null ? 'text-muted' : '' ?>">
    <td><?= hx_link(with_back('/listings/' . $id, $here), e($l['title'] !== '' ? $l['title'] : $l['external_id']), 'fw-semibold', 'id="listing-row-' . $id . '-title"') ?><?= $l['removed_at'] !== null ? ' <span class="badge bg-soft-dark text-dark">removed</span>' : '' ?><?= $l['forgotten_at'] !== null ? ' <span class="badge bg-soft-dark text-dark">not ours</span>' : '' ?>
        <details id="listing-row-<?= $id ?>-variants" class="mt-1"><summary class="fs-11 text-muted">Variants</summary>
            <div class="table-responsive"><table class="table table-sm mb-0 fs-11"><tbody>
            <?php foreach ($l['variants'] as $v): ?><tr id="listing-row-<?= $id ?>-variant-<?= $v['listing_variant_id'] ?>"><td><?= e($v['title']) ?></td><td><?= e((string) ($v['size_name'] ?? '')) ?></td><td><code><?= e((string) ($v['sku'] ?? '')) ?></code></td><td><?= e((string) ($v['barcode'] ?? '')) ?></td>
                <td class="text-end"><?= $v['price'] !== null ? e(number_format((float) $v['price'], 2)) : '' ?></td><td><?= availability_chip($v['availability']) ?></td><td><?= $v['variant_id'] !== null ? match_kind_chip($v['match_kind']) . ' ' . e((string) ($v['our_sku'] ?? '')) : match_kind_chip(null) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></details></td>
    <td class="d-none d-md-table-cell"><?= e((string) ($l['vendor'] ?? '')) ?></td>
    <td class="d-none d-lg-table-cell"><?= e((string) ($l['product_type'] ?? '')) ?></td>
    <td class="text-end"><?= count($live) ?></td>
    <td><?= $matched > 0 ? src_chip($matched . ' matched', 'success') : '' ?> <?= count($live) - $matched > 0 ? src_chip((count($live) - $matched) . ' unmatched', 'warning') : '' ?></td>
    <td class="text-end text-nowrap"><?= $l['price_min'] === null ? '' : e(number_format($l['price_min'], 2)) . ($l['price_max'] !== $l['price_min'] ? '–' . e(number_format($l['price_max'], 2)) : '') ?></td>
    <td><?= availability_chip($l['best_availability']) ?></td>
    <td class="d-none d-md-table-cell text-nowrap"><?= e(ago($l['last_seen_at'])) ?></td>
</tr>
