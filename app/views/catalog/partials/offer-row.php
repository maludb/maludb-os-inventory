<?php /** One offer row (`offer-row-{listing_variant_id}`) of inv_availability()'s offers ∪ references. Data: o, seesCost, tz */ ?>
<tr id="offer-row-<?= (int) $o['listing_variant_id'] ?>">
    <td><?= e($o['supplier'] ?? $o['source']) ?><?= isset($o['supplier']) && $o['supplier'] !== null && $o['supplier'] !== $o['source'] ? ' <span class="text-muted">· ' . e($o['source']) . '</span>' : '' ?><?= !array_key_exists('supplier', $o) ? ' <span class="badge bg-soft-light text-muted border">reference</span>' : '' ?><?= !empty($o['removed']) ? ' <span class="badge bg-soft-dark text-dark">removed</span>' : '' ?></td>
    <td><?= availability_chip($o['availability'] ?? null) ?><?= !empty($o['stale']) ? ' <span class="badge bg-soft-warning text-warning">stale</span>' : '' ?></td>
    <td class="text-end"><?= array_key_exists('cost', $o) ? ($seesCost ? money($o['cost']) : '<span title="cost withheld">—</span>') : '' ?></td>
    <td class="text-end"><?= money($o['price'] ?? null) ?></td>
    <td><?= isset($o['lead_time_days']) && $o['lead_time_days'] !== null ? (int) $o['lead_time_days'] . ' d' : '' ?><?= !empty($o['ships_how']) ? ' · ' . e(SHIPS_HOW[$o['ships_how']] ?? $o['ships_how']) : '' ?></td>
    <td class="text-nowrap"><?= e(format_ts($o['as_of'] ?? null, $tz, 'M j')) ?></td>
</tr>
