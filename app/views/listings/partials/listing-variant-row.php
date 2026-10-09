<?php /** One listing variant (`listing-variant-row-{id}`): the offer and the match; Unmatch / Match… / Propose for listings.match. Data: v, l, sel, mayMatch, seesCost, here */
$id = $v['listing_variant_id'];
$selUrl = '/listings/' . $l['listing_id'] . '?listing_variant=' . $id . '#listing-offer-chart';
?>
<tr id="listing-variant-row-<?= $id ?>" class="<?= $id === $sel ? 'table-active' : '' ?><?= $v['removed_at'] !== null ? ' text-muted' : '' ?>">
    <td><?= hx_link($selUrl, e($v['title']), 'fw-semibold', 'id="listing-variant-row-' . $id . '-select"') ?><?= $v['removed_at'] !== null ? ' <span class="badge bg-soft-dark text-dark">removed</span>' : '' ?><div class="fs-11 text-muted d-md-none"><?= e((string) ($v['size_name'] ?? '')) ?> <?= e((string) ($v['sku'] ?? '')) ?></div></td>
    <td class="d-none d-md-table-cell"><?= e((string) ($v['size_name'] ?? '')) ?></td>
    <td class="d-none d-md-table-cell"><code><?= e((string) ($v['sku'] ?? '')) ?></code></td>
    <td class="d-none d-lg-table-cell"><?= e((string) ($v['barcode'] ?? '')) ?><?= !$v['barcode_valid'] && $v['barcode'] !== null ? ' <span class="badge bg-soft-warning text-warning">not a GTIN</span>' : '' ?></td>
    <td class="d-none d-lg-table-cell"><?= e((string) ($v['mpn'] ?? '')) ?></td>
    <td class="text-end"><?= $v['price'] !== null ? e(number_format((float) $v['price'], 2)) : '—' ?><?= $v['compare_at_price'] !== null ? '<div class="fs-11 text-muted text-decoration-line-through">' . e(number_format((float) $v['compare_at_price'], 2)) . '</div>' : '' ?></td>
    <td class="text-end d-none d-md-table-cell" id="listing-variant-row-<?= $id ?>-cost"><?= cost_cell_src($v['cost_price'], $seesCost) ?></td>
    <td><?= availability_chip($v['availability']) ?><?= $v['qty'] !== null ? ' <span class="fs-11">' . (int) $v['qty'] . '</span>' : '' ?><?= $v['lead_time_days'] !== null ? '<div class="fs-11 text-muted">' . (int) $v['lead_time_days'] . ' days</div>' : '' ?></td>
    <td class="d-none d-lg-table-cell"><?= e((string) ($v['ships_how'] ?? '')) ?></td>
    <td id="listing-variant-row-<?= $id ?>-matchcell">
        <?php if ($v['variant_id'] !== null): ?>
            <?= hx_link(with_back('/variants/' . $v['variant_id'], $here), e((string) $v['our_sku']), 'fw-semibold') ?> <?= match_kind_chip($v['match_kind']) ?>
            <div class="fs-11 text-muted"><?= $v['match_confidence'] !== null ? number_format((float) $v['match_confidence'], 3) . ' · ' : '' ?><?= e($v['matched_by'] === null ? 'the matcher' : (string) ($v['matched_by_name'] ?? '')) ?> · <?= e(ago($v['matched_at'])) ?></div>
        <?php else: ?><?= match_kind_chip(null) ?><?php endif; ?>
    </td>
    <td class="text-end text-nowrap">
        <?php if ($mayMatch && $l['forgotten_at'] === null): ?>
            <?php if ($v['variant_id'] !== null): ?>
                <form method="post" action="/listings/unmatch.php" hx-post="/listings/unmatch.php" hx-target="#flash" class="d-inline" hx-confirm="Unmatch <?= e($v['title']) ?> from <?= e((string) $v['our_sku']) ?>?"><?= csrf_field() ?><input type="hidden" name="listing_variant" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="listing-variant-row-<?= $id ?>-unmatch">Unmatch</button></form>
            <?php else: ?>
                <form method="post" action="/listings/match.php" hx-post="/listings/match.php" hx-target="#flash" class="d-inline-flex gap-1 align-items-center" id="listing-variant-row-<?= $id ?>-match-form"><?= csrf_field() ?><input type="hidden" name="listing_variant" value="<?= $id ?>">
                    <?= picker_field(['id' => 'listing-variant-row-' . $id . '-match', 'name' => 'variant', 'source' => 'variant', 'params' => ['single' => '1'], 'placeholder' => 'Match…', 'required' => true]) ?>
                    <button type="submit" class="btn btn-light btn-touch" id="listing-variant-row-<?= $id ?>-match-btn">Match</button></form>
                <form method="post" action="/listings/propose.php" hx-post="/listings/propose.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="listing_variant" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="listing-variant-row-<?= $id ?>-propose" title="A fresh scoring by the matcher">Propose</button></form>
            <?php endif; ?>
        <?php endif; ?>
    </td>
</tr>
