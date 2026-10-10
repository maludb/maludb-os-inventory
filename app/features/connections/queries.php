<?php
declare(strict_types=1);

/**
 * Connections (feed.md "connection-list"): who outside reads us. The five shares this application declares (maludb-os.json `shares[]`), the reads it declares (`reads[]` — none in version 1), and the `share.read`
 * rows the records server leaves through db/017's inv_log_share_read() (source `mcp`, no actor, entity_type `share`, after = {tool, consumer, count, request_id}). Read-only; approving a connection is the
 * super-admin's decision in the OS (bin/app_connection.php) — nothing here creates one.
 */

function manifest_declaration(): array
{
    static $m = null;
    return $m ??= (json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true) ?: []);
}

/** [{tool, description, scoped}] in the manifest's order. */
function declared_shares(): array
{
    return array_values(array_map(static fn (array $s): array => ['tool' => (string) $s['tool'], 'description' => (string) ($s['description'] ?? ''), 'scoped' => (bool) ($s['scoped'] ?? false), 'document' => share_document_name((string) $s['tool'])],
        (array) (manifest_declaration()['shares'] ?? [])));
}

/** What this application reads of a sibling's (none in version 1). */
function declared_reads(): array
{
    return array_values((array) (manifest_declaration()['reads'] ?? []));
}

/** The document a share answers, by its tool name. */
function share_document_name(string $tool): string
{
    return ['sales_closed' => 'os.inventory-sales/1', 'purchases_received' => 'os.inventory-purchases/1', 'stock_valuation' => 'os.inventory-valuation/1', 'availability_index' => 'os.inventory-availability/1',
            'customer_orders' => 'os.inventory-orders/1'][$tool] ?? '';
}

/** The last share.read rows, newest first: activity_id, occurred_at, consumer, tool, count, request_id. */
function share_reads(PDO $pdo, int $limit = 50): array
{
    $st = $pdo->prepare("SELECT id AS activity_id, occurred_at, after->>'consumer' AS consumer, after->>'tool' AS tool, (after->>'count')::int AS count, after->>'request_id' AS request_id
                           FROM activity_log WHERE action = 'share.read' AND entity_type = 'share' ORDER BY id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute();
    return $st->fetchAll();
}

/** share.read rows of the last $days days grouped by consumer and tool: consumer, tool, calls, count (the sum), last_at. */
function share_readers(PDO $pdo, int $days = 30): array
{
    $st = $pdo->prepare("SELECT COALESCE(after->>'consumer', '(unknown)') AS consumer, after->>'tool' AS tool, count(*) AS calls, COALESCE(sum((after->>'count')::int), 0) AS count, max(occurred_at) AS last_at
                           FROM activity_log WHERE action = 'share.read' AND entity_type = 'share' AND occurred_at >= now() - make_interval(days => :d)
                          GROUP BY 1, 2 ORDER BY max(occurred_at) DESC, 1, 2");
    $st->execute(['d' => max(1, $days)]);
    return array_map(static fn (array $r): array => ['consumer' => $r['consumer'], 'tool' => $r['tool'], 'calls' => (int) $r['calls'], 'count' => (int) $r['count'], 'last_at' => $r['last_at']], $st->fetchAll());
}

/** The OS's applications page (os.<domain>/applications) from the launcher's name (app.<domain>), or null when it cannot be told. */
function os_applications_url(): ?string
{
    $u = parse_url((string) env('OS_LAUNCHER_URL', ''));
    $host = (string) ($u['host'] ?? '');
    if ($host === '') { return null; }
    $host = preg_replace('/^app\./', 'os.', $host, 1);
    return ($u['scheme'] ?? 'https') . '://' . $host . (isset($u['port']) ? ':' . $u['port'] : '') . '/applications';
}
