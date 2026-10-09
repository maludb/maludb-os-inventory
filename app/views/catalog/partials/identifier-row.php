<?php /** One identifier row (`identifier-row-{id}`). Data: i, vid, mayWrite, tz */ $iid = (int) $i['identifier_id']; ?>
<tr id="identifier-row-<?= $iid ?>">
    <td><?= identifier_kind_chip($i['kind']) ?></td>
    <td><code><?= e($i['value']) ?></code></td>
    <td><?= e($i['source_name'] ?? ($i['source_id'] !== null ? 'source #' . (int) $i['source_id'] : '')) ?></td>
    <td class="d-none d-md-table-cell"><?= e($i['created_by_name'] ?? '') ?></td>
    <td class="d-none d-md-table-cell"><?= e(format_ts($i['created_at'], $tz, 'M j, Y')) ?></td>
    <td class="text-end"><?php if ($mayWrite): ?><form method="post" action="/variants/identifiers/remove.php" hx-post="/variants/identifiers/remove.php" hx-target="#flash" hx-confirm="Remove the <?= e(IDENTIFIER_KINDS[$i['kind']] ?? $i['kind']) ?> <?= e($i['value']) ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="identifier" value="<?= $iid ?>"><button type="submit" class="btn btn-light btn-touch" id="identifier-row-<?= $iid ?>-remove-btn" aria-label="Remove"><i class="feather-x"></i></button></form><?php endif; ?></td>
</tr>
