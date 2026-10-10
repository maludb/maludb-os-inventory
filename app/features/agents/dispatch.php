<?php
declare(strict_types=1);

/**
 * The dispatch loop — the worker's `dispatches` pass (returns-worker.md "The dispatch loop"): a watch that named an agent (inv_fire_watches() inserts the agent_dispatches row) becomes ONE
 * chat turn of that agent through the kernel's chat endpoint (ask_assistant — the application never calls a model), the watch's member the acting person. At most five calls a pass.
 *
 *   answered           the agent replied: the member is told (kind agent_drafted, the reply), `agent.reply` logged with the length, never the words
 *   running            the kernel took the run (202) and it is not finished: run_id kept, polled by the next passes, failed after ten minutes
 *   awaiting_approval  the agent's own write paused in the kernel's queue — the kernel's, not ours
 *   refused            the kernel said no (400, 403, 404, 409, 422): its sentence is kept, no retry; Retry on the dispatch list puts it back
 *   retried / failed   unreachable, a 5xx or no reply: attempts + 1, due again at created_at + 2^attempts minutes; the third failed call (attempts = 3) is final and the member is told once
 * `attempts` starts at 1 (the table's default) and names the number of the NEXT call: the first failure makes it 2 (due 4 minutes after creation), the second 3 (8 minutes).
 */

const DISPATCH_PER_PASS = 5;
const DISPATCH_RUN_MINUTES = 10;
const DISPATCH_MAX_CALLS = 3;

/** The pass: ['called', 'answered', 'running', 'awaiting_approval', 'refused', 'retried', 'failed', 'polled']. */
function dispatches_pass(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $out = ['called' => 0, 'answered' => 0, 'running' => 0, 'awaiting_approval' => 0, 'refused' => 0, 'retried' => 0, 'failed' => 0, 'polled' => 0];
    foreach (poll_running_dispatches($pdo, $now) as $k => $n) { $out[$k] += $n; }
    foreach (dispatches_due($pdo, min($limit, DISPATCH_PER_PASS), $now) as $d) {
        $out['called']++;
        $o = dispatch_agent($pdo, $d, $now);
        $out[$o] = ($out[$o] ?? 0) + 1;
    }
    return $out;
}

/** The dispatches to call now: sent, by chat, no run yet, and either on their first call or past their backoff. */
function dispatches_due(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $st = $pdo->prepare("SELECT d.*, a.display_name AS agent_name FROM agent_dispatches d JOIN members a ON a.id = d.agent_member_id
                          WHERE d.status = 'sent' AND d.via = 'chat' AND d.run_id IS NULL
                            AND (d.attempts <= 1 OR d.created_at + make_interval(mins => power(2, d.attempts)::integer) <= CAST(:now AS timestamptz))
                          ORDER BY d.created_at, d.id LIMIT " . max(1, min(50, $limit)));
    $st->execute(['now' => $now->format('Y-m-d H:i:s.uP')]);
    return $st->fetchAll();
}

/** The watch's title: the SQL's sentence (the dispatch's own detail is overwritten by a failure's). */
function dispatch_title(PDO $pdo, array $d): string
{
    $t = $d['watch_id'] === null ? null : one_value($pdo, 'SELECT inv_watch_title(:w)', ['w' => (int) $d['watch_id']]);
    return (string) ($t ?? $d['detail'] ?? 'A watch fired');
}

/** The words the agent is asked in. Never a customer, an address, a cost: the order numbers of the sold lines that wait on the thing. */
function dispatch_utterance(PDO $pdo, array $d): string
{
    $title = dispatch_title($pdo, $d);
    $who = (string) (one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => (int) $d['acting_member_id']]) ?? 'a colleague');
    if ($d['kind'] !== 'watch') { return $title . '. Asked by ' . $who . '. Say in two lines what you found. Propose; never send, place, price or delete.'; }
    $variants = [];
    $w = $d['watch_id'] === null ? null : one_row($pdo, 'SELECT variant_id, listing_variant_id, product_id FROM watches WHERE id = :w', ['w' => (int) $d['watch_id']]);
    if ($w !== null && $w['variant_id'] !== null) { $variants[] = (int) $w['variant_id']; }
    $lvId = $d['listing_variant_id'] ?? ($w['listing_variant_id'] ?? null);
    if ($lvId !== null) {
        $v = one_value($pdo, 'SELECT variant_id FROM listing_variants WHERE id = :l', ['l' => (int) $lvId]);
        if ($v !== null) { $variants[] = (int) $v; }
    }
    if ($w !== null && $w['product_id'] !== null) {
        $st = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = :p');
        $st->execute(['p' => (int) $w['product_id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) { $variants[] = (int) $v; }
    }
    $numbers = [];
    if ($variants !== []) {
        $st = $pdo->prepare("SELECT DISTINCT o.number FROM sales_order_lines l JOIN sales_orders o ON o.id = l.sales_order_id
                              WHERE l.variant_id = ANY (CAST(:v AS bigint[])) AND l.status IN ('open', 'allocated', 'ordered') AND o.status IN ('confirmed', 'in_fulfilment') ORDER BY o.number LIMIT 10");
        $st->execute(['v' => pg_array_literal(array_values(array_unique($variants)))]);
        $numbers = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    $waiting = $numbers === [] ? 'No sold line waits on it.' : count($numbers) . ' sold line' . (count($numbers) === 1 ? '' : 's') . ' wait on this variant: ' . implode(', ', $numbers) . '.';
    return $title . '. Set by ' . $who . '. ' . $waiting . ' Look at it (availability, get_listing) and draft what should be drafted — a purchase order, a match proposal, a watch — then say in two lines what you found. Propose; never send, place, price or delete.';
}

/** One call of one dispatch, and what became of it: answered | running | awaiting_approval | refused | retried | failed. */
function dispatch_agent(PDO $pdo, array $d, DateTimeImmutable $now): string
{
    $id = (int) $d['id'];
    log_activity($pdo, 'agent.dispatch', 'agent_dispatch', $id, ['source' => 'cron', 'actor_member_id' => null, 'after' => dispatch_facts($d) + ['call' => (int) $d['attempts']]]);
    if ($d['acting_member_id'] === null) {
        return dispatch_settle($pdo, $d, ['error' => 'The person who set the watch is no longer here.', 'http' => 404], $now);
    }
    $r = ask_assistant($pdo, (int) $d['acting_member_id'], dispatch_utterance($pdo, $d), ['screen' => 'watch', 'entity' => 'watch', 'record_id' => $d['watch_id'] === null ? null : (int) $d['watch_id']], (string) $d['agent_member_id'], 0);
    return dispatch_settle($pdo, $d, $r, $now);
}

/** Apply the kernel's answer (ask_assistant's shape) to the row. */
function dispatch_settle(PDO $pdo, array $d, array $r, DateTimeImmutable $now): string
{
    $id = (int) $d['id'];
    $run = isset($r['run_id']) && $r['run_id'] !== null ? (int) $r['run_id'] : null;
    $error = $r['error'] ?? null;
    if ($error !== null) {
        if (in_array((int) ($r['http'] ?? 0), [400, 403, 404, 409, 422], true)) {
            record_dispatch_outcome($pdo, $id, 'refused', ['detail' => mb_substr((string) $error, 0, 300), 'run_id' => $run]);
            dispatch_log_fail($pdo, $d, 'refused', (string) $error);
            return 'refused';
        }
        return dispatch_transient($pdo, $d, (string) $error, $now);
    }
    if (($r['approval'] ?? null) !== null) {
        record_dispatch_outcome($pdo, $id, 'awaiting_approval', ['detail' => 'approval #' . (int) $r['approval'] . ' awaits a person', 'run_id' => $run, 'request_id' => $r['request_id'] ?? null]);
        return 'awaiting_approval';
    }
    if (!empty($r['finished'])) {
        $reply = trim((string) ($r['reply'] ?? ''));
        if ($reply === '') { return dispatch_transient($pdo, $d, 'The agent ended without a reply (' . ((string) ($r['status'] ?? 'no status')) . ').', $now); }
        record_dispatch_outcome($pdo, $id, 'answered', ['run_id' => $run, 'request_id' => $r['request_id'] ?? null, 'reply_excerpt' => mb_substr($reply, 0, 200), 'answered_at' => $now->format('Y-m-d H:i:s.uP'), 'detail' => null]);
        $title = dispatch_title($pdo, $d);
        notify($pdo, (int) $d['acting_member_id'], 'agent_drafted', 'agent_dispatch', $id, mb_substr(($d['agent_name'] ?? 'The agent') . ': ' . $title, 0, 300), mb_substr($reply, 0, 2000), 'dispatch:' . $id . ':reply');
        log_activity($pdo, 'agent.reply', 'agent_dispatch', $id, ['source' => 'cron', 'actor_member_id' => null, 'after' => dispatch_facts($d) + ['run_id' => $run, 'request_id' => $r['request_id'] ?? null, 'status' => 'answered',
            'reply_length' => mb_strlen($reply), 'cost' => $r['cost'] ?? null, 'currency' => $r['currency'] ?? null]]);
        return 'answered';
    }
    if ($run !== null) {
        record_dispatch_outcome($pdo, $id, 'sent', ['run_id' => $run, 'request_id' => $r['request_id'] ?? null, 'detail' => null]);
        return 'running';
    }
    return dispatch_transient($pdo, $d, 'The kernel did not say whether the agent is working.', $now);
}

/** The loggable facts of a dispatch: ids and words about the dispatch, never what was asked or answered. */
function dispatch_facts(array $d): array
{
    return ['dispatch_id' => (int) $d['id'], 'agent_member_id' => (int) $d['agent_member_id'], 'kind' => $d['kind'], 'via' => $d['via'], 'watch_id' => $d['watch_id'] === null ? null : (int) $d['watch_id'],
            'listing_variant_id' => $d['listing_variant_id'] === null ? null : (int) $d['listing_variant_id']];
}

function dispatch_log_fail(PDO $pdo, array $d, string $status, string $detail): void
{
    log_activity($pdo, 'agent.fail', 'agent_dispatch', (int) $d['id'], ['source' => 'cron', 'actor_member_id' => null, 'after' => dispatch_facts($d) + ['status' => $status, 'attempts' => (int) $d['attempts'], 'detail' => mb_substr($detail, 0, 200)]]);
}

/** A failure that may pass (unreachable, a 5xx, no reply): the next call at created_at + 2^attempts minutes; the third failed call is final and the member is told once. */
function dispatch_transient(PDO $pdo, array $d, string $detail, DateTimeImmutable $now): string
{
    $id = (int) $d['id'];
    $attempts = (int) $d['attempts'];
    if ($attempts >= DISPATCH_MAX_CALLS) {
        record_dispatch_outcome($pdo, $id, 'failed', ['detail' => mb_substr($detail, 0, 300)]);
        dispatch_log_fail($pdo, $d, 'failed', $detail);
        if ($d['acting_member_id'] !== null) {
            notify($pdo, (int) $d['acting_member_id'], 'agent_drafted', 'agent_dispatch', $id, mb_substr(($d['agent_name'] ?? 'The agent') . ' could not answer: ' . dispatch_title($pdo, $d), 0, 300), mb_substr($detail, 0, 500), 'dispatch:' . $id . ':failed');
        }
        return 'failed';
    }
    $pdo->prepare("UPDATE agent_dispatches SET attempts = attempts + 1, detail = :d WHERE id = :id")->execute(['d' => mb_substr($detail, 0, 300), 'id' => $id]);
    dispatch_log_fail($pdo, $d, 'retry', $detail);
    return 'retried';
}

/** The running ones (202 not finished): ask the kernel for the run. ['polled' => n, 'answered' => …]. Unreachable → left for the next pass; ten minutes without a finish → failed. */
function poll_running_dispatches(PDO $pdo, DateTimeImmutable $now): array
{
    $st = $pdo->query("SELECT d.*, a.display_name AS agent_name FROM agent_dispatches d JOIN members a ON a.id = d.agent_member_id WHERE d.status = 'sent' AND d.run_id IS NOT NULL ORDER BY d.created_at, d.id LIMIT 20");
    $out = ['polled' => 0];
    foreach ($st->fetchAll() as $d) {
        $out['polled']++;
        $a = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $d['run_id'], null, [], 20);
        $expired = strtotime((string) $d['created_at']) + DISPATCH_RUN_MINUTES * 60 < $now->getTimestamp();
        if ($a === null || $a['status'] >= 500 || $a['status'] === 401) {
            if ($expired) { $out = dispatch_count($out, dispatch_expire($pdo, $d)); }
            continue;
        }
        $b = $a['body'] ?? [];
        if ($a['status'] >= 400) {
            record_dispatch_outcome($pdo, (int) $d['id'], 'failed', ['detail' => mb_substr((string) ($b['error']['message'] ?? 'The kernel has no such run.'), 0, 300)]);
            dispatch_log_fail($pdo, $d, 'failed', (string) ($b['error']['message'] ?? 'no such run'));
            $out = dispatch_count($out, 'failed');
            continue;
        }
        $r = ['error' => null, 'reply' => (string) ($b['reply'] ?? ''), 'run_id' => (int) $d['run_id'], 'request_id' => is_string($b['request_id'] ?? null) ? $b['request_id'] : null, 'status' => $b['status'] ?? null,
              'finished' => !empty($b['finished']), 'approval' => $b['approval_request_id'] ?? null, 'cost' => $b['cost'] ?? null, 'currency' => $b['currency'] ?? null, 'http' => $a['status']];
        if (!$r['finished'] && $r['approval'] === null) {
            if ($expired) { $out = dispatch_count($out, dispatch_expire($pdo, $d)); }
            continue;
        }
        $out = dispatch_count($out, dispatch_settle($pdo, $d, $r, $now));
    }
    return $out;
}

function dispatch_count(array $out, string $outcome): array
{
    $out[$outcome] = ($out[$outcome] ?? 0) + 1;
    return $out;
}

/** The run did not finish in ten minutes: failed, the member told once. */
function dispatch_expire(PDO $pdo, array $d): string
{
    record_dispatch_outcome($pdo, (int) $d['id'], 'failed', ['detail' => 'The run did not finish.']);
    dispatch_log_fail($pdo, $d, 'failed', 'The run did not finish.');
    if ($d['acting_member_id'] !== null) {
        notify($pdo, (int) $d['acting_member_id'], 'agent_drafted', 'agent_dispatch', (int) $d['id'], mb_substr(($d['agent_name'] ?? 'The agent') . ' could not answer: ' . dispatch_title($pdo, $d), 0, 300), 'The run did not finish.', 'dispatch:' . (int) $d['id'] . ':failed');
    }
    return 'failed';
}

/** Write an outcome on the row. $facts: detail, run_id, request_id, reply_excerpt, answered_at (only the keys given are written). */
function record_dispatch_outcome(PDO $pdo, int $id, string $status, array $facts): void
{
    $set = ['status = :s'];
    $args = ['s' => $status, 'id' => $id];
    foreach (['detail', 'request_id', 'reply_excerpt'] as $k) {
        if (array_key_exists($k, $facts)) { $set[] = "$k = :$k"; $args[$k] = $facts[$k]; }
    }
    if (array_key_exists('run_id', $facts)) { $set[] = 'run_id = :run_id'; $args['run_id'] = $facts['run_id']; }
    if (array_key_exists('answered_at', $facts)) { $set[] = 'answered_at = CAST(:answered_at AS timestamptz)'; $args['answered_at'] = $facts['answered_at']; }
    $pdo->prepare('UPDATE agent_dispatches SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
}
