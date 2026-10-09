<?php
declare(strict_types=1);

/** The trail's sentences, links and JSON (sso-shell.md). Slice 9 extends the words to every event the slices log. */

/** A row's JSON: the actor, the sentence, the keys. */
function present_activity_row(array $r): array
{
    $link = activity_record_link($r);
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor' => ['member_id' => $r['actor_member_id'] === null ? null : (int) $r['actor_member_id'], 'display_name' => $r['actor_name'], 'is_agent' => (bool) $r['actor_is_agent']],
            'source' => $r['source'], 'action' => $r['action'], 'sentence' => activity_sentence($r), 'entity_type' => $r['entity_type'],
            'entity_id' => $r['entity_id'] === null ? null : (int) $r['entity_id'],
            'source_id' => $r['source_id'] === null ? null : (int) $r['source_id'], 'sales_order_id' => $r['sales_order_id'] === null ? null : (int) $r['sales_order_id'],
            'purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id'], 'location_id' => $r['location_id'] === null ? null : (int) $r['location_id'],
            'agent_run_id' => $r['agent_run_id'] === null ? null : (int) $r['agent_run_id'], 'url' => $link[0] ?? null];
}

/** The record a row concerns, as a link: [url, label] or null — the audit keys first, then the entity (record_url(), nav.php). */
function activity_record_link(array $r): ?array
{
    if (($r['sales_order_id'] ?? null) !== null) { return ['/orders/' . (int) $r['sales_order_id'], 'order']; }
    if (($r['purchase_order_id'] ?? null) !== null) { return ['/purchasing/' . (int) $r['purchase_order_id'], 'purchase order']; }
    if (($r['source_id'] ?? null) !== null) { return ['/sources/' . (int) $r['source_id'], 'source']; }
    $u = record_url($r['entity_type'] ?? null, $r['entity_id'] ?? null);
    return $u === null ? null : [$u, str_replace('_', ' ', (string) $r['entity_type'])];
}

/** Who acted, in words: a person's name, an agent chipped "(agent)", the worker, the feed by its key's label, a door. */
function activity_actor(array $r): string
{
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    if (($r['source'] ?? '') === 'cron' && ($r['actor_name'] ?? null) === null) { return 'the worker'; }
    if (($r['source'] ?? '') === 'feed') { return 'the feed' . (isset($after['label']) ? ' (' . mb_substr((string) $after['label'], 0, 40) . ')' : ''); }
    if (($r['source'] ?? '') === 'portal') { return str_starts_with((string) ($r['action'] ?? ''), 'po.') || str_starts_with((string) ($r['action'] ?? ''), 'supplier') ? 'the supplier' : 'the customer'; }
    $who = (string) ($r['actor_name'] ?? app_name());
    return !empty($r['actor_is_agent']) ? $who . ' (agent)' : $who;
}

/** One line for a row, in words: "Nora signed on". Falls back to the event name. Slice 9 adds every slice's events. */
function activity_sentence(array $r): string
{
    $who = activity_actor($r);
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    $name = isset($after['name']) ? ': ' . mb_substr((string) $after['name'], 0, 80) : (isset($after['title']) ? ': ' . mb_substr((string) $after['title'], 0, 80) : (isset($after['label']) ? ': ' . mb_substr((string) $after['label'], 0, 80) : ''));
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_on.refused' => 'was refused at sign-on', 'member.sign_out' => 'signed out', 'member.refused' => 'was refused',
        'member.sign_out.refused' => 'sent a sign-out notice that was refused', 'directory.sync' => 'refreshed the directory',
        'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'prefs.save' => 'changed how they are told',
        'notification.read' => 'read their notifications', 'assistant.ask' => 'asked the expert', 'worker.pass' => 'ran a pass', 'share.read' => 'read a sibling application\'s data',
        'attachment.add' => 'attached a file', 'attachment.delete' => 'removed an attachment',
    ];
    if (($r['action'] ?? '') === 'screen.view') {
        return $who . ' opened ' . (($r['screen'] ?? '') !== '' ? str_replace('-', ' ', (string) $r['screen']) : 'a screen');
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], ' ', (string) $r['action'])) . $name;
}
