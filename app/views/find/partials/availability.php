<?php /** The availability partial, full shape (`availability-{id}`). Data: a (inv_availability()), vid, seesCost, tz */
$state = (string) ($a['state'] ?? 'unavailable');
$best = $a['offers'][0] ?? null;
foreach ($a['offers'] ?? [] as $o) { if (empty($o['removed'])) { $best = $o; break; } }
$line = match ($state) {
    'in_stock' => isset($a['components']) ? (int) $a['sets_available'] . ' sets from stock' : 'In stock — ships today',
    'from_supplier' => 'From ' . ($best['supplier'] ?? $best['source'] ?? 'a supplier') . ($best !== null && $best['lead_time_days'] !== null ? ' in ' . (int) $best['lead_time_days'] . ' days' : ''),
    'back_order' => 'Back order',
    default => 'Unavailable',
};
$cls = ['in_stock' => 'success', 'from_supplier' => 'info', 'back_order' => 'warning'][$state] ?? 'secondary';
$offers = $a['offers'] ?? [];
usort($offers, static fn ($x, $y) => [(int) !empty($x['removed']), (int) $x['rank']] <=> [(int) !empty($y['removed']), (int) $y['rank']]);
?>
<div id="availability-<?= $vid ?>" class="fs-12">
    <div class="mb-2" id="availability-<?= $vid ?>-state"><span class="badge bg-soft-<?= $cls ?> text-<?= $cls ?> fs-12"><?= e($line) ?></span><?= ($a['on_order'] ?? 0) > 0 ? ' <span class="text-muted">· On order: ' . (int) $a['on_order'] . '</span>' : '' ?></div>
    <?php if (isset($a['components'])): ?>
        <div class="table-responsive"><table class="table table-sm mb-0" id="availability-<?= $vid ?>-components"><thead class="thead-light"><tr><th>Component</th><th class="text-end">Per set</th><th class="text-end">Available</th><th class="text-end">Sets</th><th class="text-end">Best lead</th></tr></thead><tbody>
            <?php foreach ($a['components'] as $c): ?><tr><td><?= hx_link('/variants/' . (int) $c['variant_id'], e($c['sku'])) ?> <span class="text-muted"><?= e($c['product']) ?></span></td><td class="text-end"><?= (int) $c['qty'] ?></td><td class="text-end"><?= (int) $c['own_available'] ?></td><td class="text-end"><?= (int) $c['sets_from_stock'] ?></td><td class="text-end"><?= $c['best_lead_time_days'] === null ? '—' : (int) $c['best_lead_time_days'] . ' d' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php else: ?>
        <?php $own = $a['own'] ?? []; ?>
        <div class="table-responsive mb-2"><table class="table table-sm mb-0" id="availability-<?= $vid ?>-own"><thead class="thead-light"><tr><th>Location</th><th class="text-end">On hand</th><th class="text-end">Allocated</th><th class="text-end">Floor</th><th class="text-end">Available</th></tr></thead><tbody>
            <?php if ($own === []): ?><tr><td colspan="5" class="text-muted text-center">Nothing on the shelf</td></tr><?php endif; ?>
            <?php $t = [0, 0, 0, 0]; foreach ($own as $o): if (!empty($o['sellable'])) { $t[0] += (int) $o['on_hand']; $t[1] += (int) $o['allocated']; $t[2] += (int) $o['floor_model']; $t[3] += (int) $o['available']; } ?>
                <tr class="<?= empty($o['sellable']) ? 'text-muted' : '' ?>" id="availability-<?= $vid ?>-own-<?= (int) $o['location_id'] ?>"><td><?= e($o['location']) ?><?= empty($o['sellable']) ? ' <span class="badge bg-soft-light text-muted border">not sellable</span>' : '' ?></td><td class="text-end"><?= (int) $o['on_hand'] ?></td><td class="text-end"><?= (int) $o['allocated'] ?></td><td class="text-end"><?= (int) $o['floor_model'] ?></td><td class="text-end"><?= (int) $o['available'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (count($own) > 1): ?><tr class="fw-semibold"><td>Sellable</td><td class="text-end"><?= $t[0] ?></td><td class="text-end"><?= $t[1] ?></td><td class="text-end"><?= $t[2] ?></td><td class="text-end"><?= $t[3] ?></td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="table-responsive mb-2"><table class="table table-sm mb-0" id="availability-<?= $vid ?>-offers"><thead class="thead-light"><tr><th>Source</th><th>Supplier</th><th class="text-end">Price</th><?php if ($seesCost): ?><th class="text-end">Cost</th><?php endif; ?><th>Availability</th><th class="text-end">Qty</th><th class="text-end">Lead</th><th>Ships</th><th>As of</th><th></th></tr></thead><tbody>
            <?php if ($offers === []): ?><tr><td colspan="<?= $seesCost ? 10 : 9 ?>" class="text-muted text-center">No supplier offers it</td></tr><?php endif; ?>
            <?php foreach ($offers as $o): $rm = !empty($o['removed']); ?>
                <tr class="<?= $rm ? 'text-decoration-line-through text-muted' : '' ?>" id="availability-<?= $vid ?>-offer-<?= (int) $o['listing_variant_id'] ?>"><td><?= e($o['source']) ?></td><td><?= e($o['supplier'] ?? '') ?></td><td class="text-end"><?= money($o['price']) ?></td><?php if ($seesCost): ?><td class="text-end"><?= money($o['cost']) ?></td><?php endif; ?>
                    <td><?= availability_chip($o['availability']) ?><?= $rm ? ' <span class="badge bg-soft-dark text-dark">removed</span>' : '' ?></td><td class="text-end"><?= $o['qty'] === null ? '' : (int) $o['qty'] ?></td><td class="text-end"><?= $o['lead_time_days'] === null ? '' : (int) $o['lead_time_days'] . ' d' ?></td>
                    <td><?= e(SHIPS_HOW[$o['ships_how'] ?? ''] ?? (string) ($o['ships_how'] ?? '')) ?></td><td class="text-nowrap"><?= e(as_of_words($o['as_of'], $tz)) ?><?= !empty($o['stale']) ? ' <span class="badge bg-soft-warning text-warning">stale</span>' : '' ?></td>
                    <td><?= !empty($o['url']) ? '<a href="' . e($o['url']) . '" target="_blank" rel="noopener noreferrer" aria-label="Open the listing"><i class="feather-external-link"></i></a>' : '' ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php if (!empty($a['references'])): ?>
        <div class="table-responsive"><table class="table table-sm mb-0" id="availability-<?= $vid ?>-references"><thead class="thead-light"><tr><th>Reference</th><th class="text-end">Price</th><th class="text-end">Compare at</th><th>Availability</th><th>As of</th><th></th></tr></thead><tbody>
            <?php foreach ($a['references'] as $o): $rm = !empty($o['removed']); ?><tr class="<?= $rm ? 'text-decoration-line-through text-muted' : '' ?>" id="availability-<?= $vid ?>-reference-<?= (int) $o['listing_variant_id'] ?>"><td><?= e($o['source']) ?></td><td class="text-end"><?= money($o['price']) ?></td><td class="text-end"><?= money($o['compare_at_price']) ?></td><td><?= availability_chip($o['availability']) ?></td><td class="text-nowrap"><?= e(as_of_words($o['as_of'], $tz)) ?><?= !empty($o['stale']) ? ' <span class="badge bg-soft-warning text-warning">stale</span>' : '' ?></td><td><?= !empty($o['url']) ? '<a href="' . e($o['url']) . '" target="_blank" rel="noopener noreferrer" aria-label="Open the listing"><i class="feather-external-link"></i></a>' : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
