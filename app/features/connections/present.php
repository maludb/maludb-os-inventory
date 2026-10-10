<?php
declare(strict_types=1);

/** Connections' JSON shapes and chips: a read within 24 hours is success, older secondary (feed.md "Status vocabulary"). */

function share_read_fresh(?string $at): bool
{
    return $at !== null && strtotime($at) !== false && strtotime($at) >= time() - 86400;
}

function share_read_chip(?string $at, string $tz): string
{
    $fresh = share_read_fresh($at);
    return '<span class="badge bg-soft-' . ($fresh ? 'success' : 'secondary') . ' text-' . ($fresh ? 'success' : 'secondary') . '">' . e(format_ts($at, $tz, 'M j, g:i A')) . '</span>';
}

function present_share_read(array $r): array
{
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']), 'consumer' => $r['consumer'], 'tool' => $r['tool'], 'count' => $r['count'] === null ? null : (int) $r['count'], 'request_id' => $r['request_id']];
}

function present_share_reader(array $r): array
{
    return ['consumer' => $r['consumer'], 'tool' => $r['tool'], 'calls' => (int) $r['calls'], 'count' => (int) $r['count'], 'last_at' => json_ts($r['last_at'])];
}
