<?php /** One share.read row (`share-read-row-{activity_id}`). Data: r [activity_id, occurred_at, consumer, tool, count, request_id], tz */ ?>
<tr id="share-read-row-<?= (int) $r['activity_id'] ?>">
    <td class="text-nowrap"><?= share_read_chip($r['occurred_at'], $tz) ?></td>
    <td class="fw-semibold"><?= e((string) $r['consumer']) ?></td><td><code><?= e((string) $r['tool']) ?></code></td>
    <td class="text-end"><?= $r['count'] === null ? '—' : number_format((int) $r['count']) ?></td>
    <td class="d-none d-md-table-cell text-muted text-break"><?= e((string) $r['request_id']) ?></td>
</tr>
