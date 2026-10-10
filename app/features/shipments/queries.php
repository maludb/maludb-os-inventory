<?php
declare(strict_types=1);

/** Shipments (orders.md "Query functions"): mcp_shipments joined to the order for its number and customer. A shipment is made by the verbs (inv_order_ship, inv_po_tracking) — no write function here. */

function shipments_where(array $f, array &$args): string
{
    $sql = '';
    $kind = (string) ($f['kind'] ?? '');
    if ($kind !== '' && isset(SHIPMENT_KINDS[$kind])) { $sql .= ' AND sh.kind = :kind'; $args['kind'] = $kind; }
    if (!empty($f['undelivered'])) { $sql .= ' AND sh.delivered_at IS NULL'; }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) {
            $sql .= $op === '>=' ? " AND sh.shipped_at >= CAST(:$k AS date)" : " AND sh.shipped_at < CAST(:$k AS date) + 1";
            $args[$k] = $f[$k];
        }
    }
    if (!empty($f['order'])) { $sql .= ' AND sh.sales_order_id = :ord'; $args['ord'] = (int) $f['order']; }
    return $sql;
}

const SHIPMENT_SELECT = 'SELECT sh.shipment_id, sh.sales_order_id, o.number AS order_number, o.customer_id, o.customer_name, sh.kind, sh.carrier, sh.tracking_number, sh.tracking_url, sh.shipped_at, sh.delivered_at, sh.shipped_by, sh.note
    FROM mcp_shipments sh JOIN mcp_sales_orders o ON o.sales_order_id = sh.sales_order_id';

/** filters kind, from, to (on shipped_at), undelivered, order. Newest first. */
function find_shipments(PDO $pdo, array $filters, int $limit = 100, int $offset = 0): array
{
    $args = [];
    $where = shipments_where($filters, $args);
    $st = $pdo->prepare(SHIPMENT_SELECT . ' WHERE true' . $where . ' ORDER BY sh.shipped_at DESC, sh.shipment_id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map(static function (array $r): array { $r['shipment_id'] = (int) $r['shipment_id']; $r['sales_order_id'] = (int) $r['sales_order_id']; return $r; }, $st->fetchAll());
}

function count_shipments(PDO $pdo, array $filters): int
{
    $args = [];
    $where = shipments_where($filters, $args);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_shipments sh WHERE true' . $where);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** One shipment with its lines. */
function find_shipment(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(SHIPMENT_SELECT . ' WHERE sh.shipment_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r['shipment_id'] = (int) $r['shipment_id'];
    $r['sales_order_id'] = (int) $r['sales_order_id'];
    $r['lines'] = shipment_lines($pdo, $id);
    return $r;
}
