<?php
declare(strict_types=1);

/** The dispatch list (screen `dispatch-list`): mcp_agent_dispatches (the admin and the Buyer see every one; the asker and the agent their own) with the asker's name. */

const DISPATCH_STATUSES = ['sent' => 'Pending', 'answered' => 'Answered', 'awaiting_approval' => 'Awaiting approval', 'refused' => 'Refused', 'failed' => 'Failed'];
const DISPATCH_PAGE = 50;

function dispatches_where(array $f, array &$args): string
{
    $sql = '';
    $status = (string) ($f['status'] ?? '');
    if ($status !== '' && isset(DISPATCH_STATUSES[$status])) { $sql .= ' AND d.status = :status'; $args['status'] = $status; }
    if (!empty($f['agent'])) { $sql .= ' AND d.agent_member_id = :agent'; $args['agent'] = (int) $f['agent']; }
    return $sql;
}

/** [rows, total, pages] of the dispatches, newest first. */
function find_dispatches(PDO $pdo, array $f, int $page = 1): array
{
    $args = [];
    $where = dispatches_where($f, $args);
    $t = $pdo->prepare('SELECT count(*) FROM mcp_agent_dispatches d WHERE true' . $where);
    $t->execute($args);
    $total = (int) $t->fetchColumn();
    $st = $pdo->prepare('SELECT d.dispatch_id, d.record_type, d.record_id, d.agent_member_id, d.agent_name, d.kind, d.via, d.acting_member_id, a.display_name AS asker_name, d.run_id, d.status, d.reply_excerpt, d.detail,
                                d.attempts, d.created_at, d.answered_at, d.watch_id, d.listing_variant_id
                           FROM mcp_agent_dispatches d LEFT JOIN mcp_members a ON a.member_id = d.acting_member_id WHERE true' . $where
        . ' ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT ' . DISPATCH_PAGE . ' OFFSET ' . (max(1, $page) - 1) * DISPATCH_PAGE);
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => max(1, $page), 'pages' => max(1, (int) ceil($total / DISPATCH_PAGE))];
}

function find_dispatch(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, 'SELECT d.dispatch_id, d.agent_member_id, d.agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.status, d.reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at, d.watch_id
                          FROM mcp_agent_dispatches d WHERE d.dispatch_id = :id', ['id' => $id]);
    return $r;
}

/** The agents that have been handed something (the filter). [{member_id, display_name}] */
function dispatch_agents(PDO $pdo): array
{
    return $pdo->query('SELECT DISTINCT d.agent_member_id AS member_id, d.agent_name AS display_name FROM mcp_agent_dispatches d ORDER BY 2')->fetchAll();
}

/** A failed or refused dispatch goes back to sent with no run: due at once (the backoff clock reads created_at + 2^attempts and the retry forgives it by setting attempts to 1 — the failure history stays in the log). */
function retry_dispatch(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'sent', run_id = NULL, detail = NULL, answered_at = NULL, attempts = 1 WHERE id = :id AND status IN ('failed', 'refused')");
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) { throw new DomainException('Only a failed dispatch is retried.'); }
}
