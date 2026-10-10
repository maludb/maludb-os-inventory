<?php
declare(strict_types=1);

/** Watches' presenters: the kind chip, the "Now" dot, the JSON shape. */

function watch_kind_chip(string $kind): string
{
    [$w, $c] = WATCH_KINDS[$kind] ?? [$kind, 'secondary'];
    return '<span class="badge bg-soft-' . $c . ' text-' . ($c === 'dark' ? 'dark' : $c) . '">' . e($w) . '</span>';
}

/** "Now": success when the condition holds, secondary when not, a dash before the first evaluation. */
function watch_now_dot(?bool $state, string $id): string
{
    if ($state === null) { return '<span id="' . e($id) . '" class="text-muted">—</span>'; }
    return '<span id="' . e($id) . '" class="d-inline-flex align-items-center gap-1"><span class="rounded-circle d-inline-block bg-' . ($state ? 'success' : 'secondary') . '" style="width:.6rem;height:.6rem"></span>'
        . ($state ? 'holds' : 'not now') . '</span>';
}

function watch_threshold_words(array $w): string
{
    if ($w['threshold'] === null) { return ''; }
    return $w['kind'] === 'lead_time_over' ? (int) $w['threshold'] . ' days' : money((string) $w['threshold']);
}

function present_watch(array $w): array
{
    return ['watch_id' => $w['watch_id'], 'member_id' => $w['member_id'], 'member' => $w['member_name'], 'kind' => $w['kind'], 'variant_id' => $w['variant_id'],
            'listing_variant_id' => $w['listing_variant_id'], 'product_id' => $w['product_id'], 'target' => $w['target_label'], 'threshold' => $w['threshold'], 'text_me' => $w['text_me'],
            'agent_member_id' => $w['agent_member_id'], 'agent' => $w['agent_name'], 'now' => $w['last_state'], 'fired_at' => json_ts($w['fired_at']), 'fire_count' => $w['fire_count'],
            'active' => $w['active'], 'note' => $w['note'], 'created_at' => json_ts($w['created_at'])];
}

const WATCH_NOTICES = ['watched' => ['success', 'Watching. You will be told when it changes.'], 'cleared' => ['success', 'The watch is cleared.']];
