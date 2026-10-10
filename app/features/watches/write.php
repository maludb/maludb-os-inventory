<?php
declare(strict_types=1);

/** Watches' writes (find.md): a watch is created and cleared, never updated or deleted — its notifications keep their record. */

/** INSERT the watch for $memberId, then evaluate it at once (last_state), so a condition already true does not fire on the next pass. */
function save_watch(PDO $pdo, array $fields, int $memberId): int
{
    $st = $pdo->prepare('INSERT INTO watches (member_id, agent_member_id, kind, variant_id, listing_variant_id, product_id, threshold, text_me, note)
                         VALUES (:m, :a, :k, :v, :lv, :p, CAST(:th AS numeric), :tx, :n) RETURNING id');
    $st->execute(['m' => $memberId, 'a' => $fields['agent_member_id'], 'k' => $fields['kind'], 'v' => $fields['variant_id'], 'lv' => $fields['listing_variant_id'], 'p' => $fields['product_id'],
                  'th' => $fields['threshold'], 'tx' => $fields['text_me'] ? 'true' : 'false', 'n' => $fields['note']]);
    $id = (int) $st->fetchColumn();
    $pdo->prepare('UPDATE watches SET last_state = inv_watch_state(id) WHERE id = :id')->execute(['id' => $id]);
    return $id;
}

/** Cleared, never deleted. */
function clear_watch(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE watches SET active = false WHERE id = :id AND active')->execute(['id' => $id]);
}
