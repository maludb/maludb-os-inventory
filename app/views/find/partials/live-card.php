<?php /** One source's live answer (`find-live-{source_id}`). Data: s (the source), res (live_search_source()), q, seesCost, mayMatch */
$sid = (int) $s['source_id'];
$st = $res['status'];
$matched = count(array_filter($res['rows'], static fn ($r) => $r['variant_id'] !== null));
[$words, $cls] = match (true) {
    $st === 'ok' => [count($res['rows']) . ' listings · ' . $matched . ' matched to ours', 'success'],
    $st === 'blocked' => ['blocked — the source is backing off', 'danger'],
    $st === 'timeout' => ['no answer in time', 'warning'],
    in_array($st, ['no live search', 'paused', 'backing off', 'a pull is running'], true) => [$st, 'secondary'],
    default => [$st, 'danger'],
};
$showCost = $seesCost && $s['role'] === 'supplier';
?>
<div class="card" id="find-live-<?= $sid ?>">
    <div class="card-header d-flex align-items-center gap-2 flex-wrap"><span class="fw-semibold"><?= e($s['name']) ?></span> <?= connector_badge($s['connector']) ?> <?= role_chip($s['role']) ?>
        <span class="badge bg-soft-<?= $cls ?> text-<?= $cls ?> ms-auto" id="find-live-<?= $sid ?>-status"><?= e($words) ?></span></div>
    <div class="card-body p-0">
        <?php if ($st !== 'ok' && $res['rows'] !== []): ?><div class="fs-12 text-muted px-3 pt-2">from the last pull:</div><?php endif; ?>
        <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><tbody>
            <?php if ($res['rows'] === []): ?><tr><td class="text-muted text-center py-3">Nothing found.</td></tr><?php endif; ?>
            <?php foreach ($res['rows'] as $r): $lvid = (int) $r['listing_variant_id']; ?>
                <tr id="find-live-<?= $sid ?>-row-<?= $lvid ?>">
                    <td><?= e($r['listing_title']) ?><?= $r['title'] && $r['title'] !== $r['listing_title'] ? ' <span class="text-muted">— ' . e($r['title']) . '</span>' : '' ?></td>
                    <td class="text-nowrap"><?= e((string) ($r['size_name'] ?? '')) ?></td>
                    <td class="text-end"><?= money($r['price']) ?></td>
                    <?php if ($showCost): ?><td class="text-end"><?= $r['cost_price'] === null ? '' : 'cost ' . money($r['cost_price']) ?></td><?php endif; ?>
                    <td><?= availability_chip($r['availability']) ?></td>
                    <td class="text-nowrap"><?= $r['lead_time_days'] === null ? '' : (int) $r['lead_time_days'] . ' d' ?></td>
                    <td class="text-nowrap"><?php if ($r['variant_id'] !== null): ?><?= hx_link('/variants/' . (int) $r['variant_id'], '<code>' . e((string) $r['our_sku']) . '</code>', '', 'id="find-live-' . $sid . '-row-' . $lvid . '-sku"') ?>
                        <?php else: ?><span class="text-muted">not in our catalog</span><?php if ($mayMatch): ?> · <?= hx_link('/matching/?' . http_build_query(['source' => $sid, 'q' => $r['listing_title']]), 'match', '', 'id="find-live-' . $sid . '-row-' . $lvid . '-match"') ?><?php endif; ?><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>
