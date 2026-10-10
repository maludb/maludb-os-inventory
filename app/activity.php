<?php
declare(strict_types=1);

/**
 * The one funnel (memory.md §2). Every state change and every screen entry writes a row; the
 * ingest bridge ships it to the tenant's MaluDB with "application": "inventory".
 * $opts: actor_member_id, source, screen, route, before, after, request_id, agent_run_id, department_id, location_id, kernel_request_id,
 *        source_id, sales_order_id, purchase_order_id, token_id (the audit keys of design §6 — the trails a source, an order, a purchase
 *        order and the feed show). A CREDENTIAL, A CUSTOMER'S ADDRESS OR PHONE AND A SOURCE'S RAW OBJECT ARE NEVER IN A PAYLOAD (design §6):
 *        ids, names, SKUs, counts, states and amounts are; a feed key is named by its LABEL in `after`, never by its value.
 */
function log_activity(PDO $pdo, string $action, ?string $entityType = null, int|string|null $entityId = null, array $opts = []): void
{
    try {
        $eid = null;
        if (is_int($entityId) || (is_string($entityId) && ctype_digit($entityId))) {
            $eid = (int) $entityId;
        }
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO activity_log
                (actor_member_id, source, action, screen, route, entity_type, entity_id,
                 before, after, request_id, session_id, ip_address, agent_run_id, department_id, location_id, kernel_request_id,
                 source_id, sales_order_id, purchase_order_id, token_id)
            VALUES
                (:actor, :source, :action, :screen, :route, :etype, :eid,
                 :before, :after, :rid, :sid, :ip, :run, :dept, :loc, :krid, :src, :so, :po, :tok)
        SQL);
        $stmt->execute([
            'actor'  => array_key_exists('actor_member_id', $opts) ? $opts['actor_member_id'] : ($_SESSION['member_id'] ?? null),
            'source' => $opts['source'] ?? default_activity_source(),
            'action' => $action,
            'screen' => $opts['screen'] ?? null,
            'route'  => $opts['route'] ?? request_route(),
            'etype'  => $entityType,
            'eid'    => $eid,
            'before' => isset($opts['before']) ? json_encode($opts['before'], JSON_THROW_ON_ERROR) : null,
            'after'  => isset($opts['after']) ? json_encode($opts['after'], JSON_THROW_ON_ERROR) : null,
            'rid'    => $opts['request_id'] ?? agent_run_request_id() ?? request_id(),
            'sid'    => PHP_SAPI === 'cli' ? null : (session_id() ?: null),
            'ip'     => PHP_SAPI === 'cli' ? null : client_ip(),
            'run'    => $opts['agent_run_id'] ?? current_agent_run_id(),
            'dept'   => $opts['department_id'] ?? null,
            'loc'    => $opts['location_id'] ?? ($entityType === 'location' ? $eid : null),
            'krid'   => $opts['kernel_request_id'] ?? null,
            'src'    => $opts['source_id'] ?? ($entityType === 'source' ? $eid : null),
            'so'     => $opts['sales_order_id'] ?? ($entityType === 'sales_order' ? $eid : null),
            'po'     => $opts['purchase_order_id'] ?? ($entityType === 'purchase_order' ? $eid : null),
            'tok'    => $opts['token_id'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('activity_log insert failed: ' . $e->getMessage());
    }
}

/** 'web' for the UI; 'agent' under a run token; 'assistant' under a person's action token; 'cron' under CLI; 'portal' and 'feed' when a door says so. */
function default_activity_source(): string
{
    if (isset($GLOBALS['__activity_source'])) { return (string) $GLOBALS['__activity_source']; }     // a door that names its caller (the bridge: 'mcp' or 'agent')
    if (is_action_authed()) {
        return current_agent_run_id() !== null ? 'agent' : 'assistant';
    }
    if (!empty($GLOBALS['__public_door'])) {
        return (string) $GLOBALS['__public_door'];      // 'portal' (the customer's or the supplier's door) or 'feed' (the availability API)
    }
    return PHP_SAPI === 'cli' ? 'cron' : 'web';
}

function log_screen_view(PDO $pdo, string $screen): void
{
    if (wants_json() && ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') !== '1') {
        return;
    }
    log_activity($pdo, 'screen.view', null, null, ['screen' => $screen]);
}

/**
 * Under a run token every row of the run carries the run's own request id (the join to the
 * kernel's prompt ledger). Inventory cannot read the kernel's database: it asks the run-facts call once
 * per request (agents.md, A7 b) — never a header the agent could set.
 */
function agent_run_request_id(): ?string
{
    static $resolved = false, $id = null;
    if (!$resolved) {
        $resolved = true;
        if (current_agent_run_id() !== null) {
            $facts = run_facts((string) ($GLOBALS['__action_token'] ?? ''));
            $id = $facts !== null && !empty($facts['valid']) ? ($facts['request_id'] ?? null) : null;
        }
    }
    return $id;
}

function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $given = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        $id = $given !== '' && preg_match('/^[A-Za-z0-9._:-]{4,80}$/', $given) ? $given : bin2hex(random_bytes(8));
    }
    return $id;
}

function request_route(): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: '';
    return trim($method . ' ' . $path);
}
