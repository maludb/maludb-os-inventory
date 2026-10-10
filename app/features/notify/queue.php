<?php
declare(strict_types=1);

/**
 * The one queueing function (returns-worker.md "Notifications"): notify() is inv_notify() (db/013) — which decides the channels from the person's preferences, the dedupe key
 * and the address at send time — plus the eval guard: an evaluation run writes nothing. Every slice queues through it from slice 8 on; slices 3–6 call inv_notify() directly
 * (equivalent: notify() adds only the guard). The Buyer is the settings' Buyer, else the lowest-id active human super-admin (DECISION 5).
 */

/** An evaluation run (the kernel's run facts say so) queues nothing, sends nothing, writes nothing. */
function notify_is_eval(): bool
{
    if (!empty($GLOBALS['__run_facts']['is_eval'])) { return true; }
    if (function_exists('current_agent_run_id') && current_agent_run_id() !== null && ($GLOBALS['__action_token'] ?? '') !== '') {
        return !empty((run_facts((string) $GLOBALS['__action_token']) ?? [])['is_eval']);
    }
    return false;
}

/** One notification to one member, queued to the channels their preferences allow. The notification's id, or null (a second queue of the same dedupe key; an eval run). */
function notify(PDO $pdo, int $memberId, string $kind, ?string $recordType, ?int $recordId, string $title, ?string $body = null, ?string $dedupe = null, bool $forceText = false): ?int
{
    if (notify_is_eval()) { return null; }
    $st = $pdo->prepare('SELECT inv_notify(:m, :k, :rt, :rid, :t, :b, :d, :f)');
    $st->bindValue(':m', $memberId, PDO::PARAM_INT);
    $st->bindValue(':k', $kind);
    $st->bindValue(':rt', $recordType);
    $st->bindValue(':rid', $recordId, $recordId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(':t', mb_substr($title, 0, 300));
    $st->bindValue(':b', $body, $body === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':d', $dedupe, $dedupe === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':f', $forceText, PDO::PARAM_BOOL);
    $st->execute();
    $id = $st->fetchColumn();
    return $id === false || $id === null ? null : (int) $id;
}

/** The Buyer: inv_settings.buyer_member_id, else the lowest-id active human super-admin of the mirror (the settings' "first super-admin until set"); null when there is none. */
function buyer_member(PDO $pdo): ?int
{
    $v = one_value($pdo, 'SELECT buyer_member_id FROM inv_settings WHERE id = 1');
    if ($v !== null) {
        $ok = one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND status = 'active'", ['m' => (int) $v]);
        if ($ok !== null) { return (int) $v; }
    }
    $v = one_value($pdo, "SELECT id FROM members WHERE member_kind = 'human' AND business_role = 'super_admin' AND status = 'active' ORDER BY id LIMIT 1");
    return $v === null ? null : (int) $v;
}

/** The same, for the callers of slices 5–6 (kept by its old name). */
function buyer_member_id(PDO $pdo): ?int
{
    return buyer_member($pdo);
}

/** Tell the Buyer. The notification id, or null when nobody is the Buyer (a line in the error log), or the dedupe key was queued before. */
function notify_buyer(PDO $pdo, string $kind, ?string $recordType, ?int $recordId, string $title, ?string $body = null, ?string $dedupe = null): ?int
{
    $buyer = buyer_member($pdo);
    if ($buyer === null) {
        error_log('notify_buyer: no Buyer is set and no active super-admin — "' . $kind . '" was not queued');
        return null;
    }
    return notify($pdo, $buyer, $kind, $recordType, $recordId, $title, $body, $dedupe);
}
