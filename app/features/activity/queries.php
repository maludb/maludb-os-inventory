<?php
declare(strict_types=1);

/**
 * The activity trail the caller may see (mcp_activity_log, db/015: their own rows, and rows about records anyone here may see;
 * everything for the admin). Phase 2 renders the trail's first shape; slice 9 (reports-admin.md) makes it whole (every sentence,
 * `member=` for another person, the product/variant rows named in `after`).
 */
const ACTIVITY_PAGE = 50;

/**
 * Rows newest first, one page; `more` says whether another page exists. $filters: own (bool, default true when no key is given),
 * action (a prefix), since (days), source / order / purchase_order (the audit keys), product / variant (entity_type + entity_id),
 * member (another actor — the admin or reports.read; an agent's rows any reader).
 */
function find_my_activity(PDO $pdo, int $memberId, int $pageNo, array $filters = []): array
{
    $where = [];
    $args = [];
    $keyed = false;
    foreach (['source' => 'source_id', 'order' => 'sales_order_id', 'purchase_order' => 'purchase_order_id'] as $k => $col) {
        if (($filters[$k] ?? null) !== null) {
            $where[] = "$col = :$k";
            $args[$k] = (int) $filters[$k];
            $keyed = true;
        }
    }
    foreach (['product' => ['product', 'product_id'], 'variant' => ['product_variant', 'variant_id']] as $k => [$etype, $afterKey]) {
        if (($filters[$k] ?? null) !== null) {
            // the record itself, and the rows (a watch, an image, a line) whose payload names it
            $where[] = "((entity_type = '$etype' AND entity_id = :$k) OR (after IS NOT NULL AND after->>'$afterKey' = :{$k}_s))";
            $args[$k] = (int) $filters[$k];
            $args[$k . '_s'] = (string) (int) $filters[$k];
            $keyed = true;
        }
    }
    if (($filters['member'] ?? null) !== null) {
        $where[] = 'actor_member_id = :actor';
        $args['actor'] = (int) $filters['member'];
        $keyed = true;
    }
    if (!$keyed && ($filters['own'] ?? true)) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim((string) $filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, screen, entity_type, entity_id, after, agent_run_id,
                   source_id, sales_order_id, purchase_order_id, location_id, token_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . ((max(1, $pageNo) - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A record's trail by entity type and id (the view decides who sees it). */
function find_record_activity(PDO $pdo, string $type, int $id, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, screen, entity_type, entity_id, after, agent_run_id, source_id, sales_order_id, purchase_order_id, location_id, token_id
                           FROM mcp_activity_log WHERE entity_type = :t AND entity_id = :id ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute(['t' => $type, 'id' => $id]);
    return $st->fetchAll();
}

/** A record's trail by an audit key (source_id / sales_order_id / purchase_order_id): everything that touched it. */
function find_activity_by_key(PDO $pdo, string $key, int $id, int $limit = 50): array
{
    if (!in_array($key, ['source_id', 'sales_order_id', 'purchase_order_id', 'location_id', 'token_id'], true)) {
        throw new InvalidArgumentException('Not an audit key: ' . $key);
    }
    $st = $pdo->prepare("SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, screen, entity_type, entity_id, after, agent_run_id, source_id, sales_order_id, purchase_order_id, location_id, token_id
                           FROM mcp_activity_log WHERE $key = :id ORDER BY occurred_at DESC, activity_id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}
