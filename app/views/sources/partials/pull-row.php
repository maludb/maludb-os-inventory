<?php /** One pull (`pull-row-{id}`). Data: p, tz, full (bool: the pulls screen's columns) */ $id = (int) $p['pull_id']; ?>
<tr id="pull-row-<?= $id ?>">
    <td class="text-nowrap"><?= e(format_ts($p['started_at'], $tz, 'M j, g:i A')) ?></td>
    <td><?= pull_kind_chip($p['kind']) ?></td>
    <td id="pull-row-<?= $id ?>-status"><?= pull_status_chip($p) ?><?= $p['status'] === 'running' && !$p['queued'] && !$p['stale'] ? ' <span class="fs-11 text-muted">since ' . e(format_ts($p['started_at'], $tz, 'g:i A')) . '</span>' : '' ?></td>
    <td class="text-nowrap" id="pull-row-<?= $id ?>-counts"><?= $p['listings_seen'] ?> seen · <?= $p['listings_new'] ?> new · <?= $p['listings_changed'] ?> changed<?= !empty($full) ? ' · ' . $p['variants_changed'] . ' variants' : '' ?> · <?= $p['listings_removed'] ?> removed</td>
    <td class="d-none d-md-table-cell text-nowrap"><?= $p['http_requests'] ?><?= !empty($p['policy']['cached']) ? ' (' . (int) $p['policy']['cached'] . ' cached)' : '' ?></td>
    <?php if (!empty($full)): ?><td class="d-none d-lg-table-cell"><?= e(fmt_bytes($p['bytes'])) ?></td><td class="d-none d-lg-table-cell"><?= $p['seconds'] !== null ? $p['seconds'] . ' s' : '' ?></td><td class="d-none d-lg-table-cell"><?= e((string) ($p['started_by_name'] ?? '')) ?></td><?php endif; ?>
    <td class="text-break"><?= e((string) ($p['error'] ?? '')) ?><?= !empty($full) ? view('sources/partials/policy-facts.php', ['p' => $p]) : '' ?></td>
</tr>
