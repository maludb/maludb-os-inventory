<?php /** One grouped reader (`connections-reader-row-{n}`). Data: r [consumer, tool, calls, count, last_at], n, tz */ ?>
<tr id="connections-reader-row-<?= (int) $n ?>">
    <td class="fw-semibold"><?= e($r['consumer']) ?></td><td><code><?= e((string) $r['tool']) ?></code></td>
    <td class="text-end"><?= number_format($r['calls']) ?></td><td class="text-end"><?= number_format($r['count']) ?></td>
    <td class="text-nowrap"><?= share_read_chip($r['last_at'], $tz) ?></td>
</tr>
