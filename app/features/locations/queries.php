<?php
declare(strict_types=1);

/**
 * Locations (stock.md "Query functions"): where the business keeps stock — a record, never a wall. Read from mcp_locations; the units on
 * hand summed from mcp_inventory_balances. The resolver `find_locations` of Phase 4 calls find_locations().
 */

const LOCATION_KINDS = ['warehouse' => 'Warehouse', 'showroom' => 'Showroom', 'store' => 'Store', 'in_transit' => 'In transit', 'returns' => 'Returns', 'offsite' => 'Offsite'];
const LOCATION_COLUMNS = 'l.location_id, l.name, l.kind, l.address, l.department_id, l.kernel_location_id, l.is_sellable, l.allow_negative, l.active, l.created_at, l.updated_at';

function location_row_decode(array $l): array
{
    $l['location_id'] = (int) $l['location_id'];
    $l['department_id'] = $l['department_id'] === null ? null : (int) $l['department_id'];
    foreach (['is_sellable', 'allow_negative', 'active'] as $k) { $l[$k] = (bool) $l[$k]; }
    if (array_key_exists('units_on_hand', $l)) { $l['units_on_hand'] = (int) $l['units_on_hand']; }
    return $l;
}

/** The locations with their units on hand and the department's name (the cards; the resolver). */
function find_locations(PDO $pdo, ?string $q = null, ?string $kind = null, bool $sellableOnly = false, bool $includeInactive = false, int $limit = 100): array
{
    $sql = 'SELECT ' . LOCATION_COLUMNS . ', d.name AS department_name,
                   COALESCE((SELECT sum(b.qty_on_hand) FROM mcp_inventory_balances b WHERE b.location_id = l.location_id), 0) AS units_on_hand
              FROM mcp_locations l LEFT JOIN mcp_departments d ON d.department_id = l.department_id WHERE true';
    $args = [];
    if (!$includeInactive) { $sql .= ' AND l.active'; }
    if ($sellableOnly) { $sql .= ' AND l.is_sellable'; }
    if ($kind !== null && isset(LOCATION_KINDS[$kind])) { $sql .= ' AND l.kind = :kind'; $args['kind'] = $kind; }
    if ($q !== null && trim($q) !== '') {
        if (ctype_digit(trim($q))) { $sql .= ' AND l.location_id = :qid'; $args['qid'] = (int) $q; }
        else { $sql .= ' AND l.name ILIKE :q'; $args['q'] = '%' . trim($q) . '%'; }
    }
    $st = $pdo->prepare($sql . ' ORDER BY l.active DESC, l.name LIMIT ' . max(1, min(500, $limit)));
    $st->execute($args);
    return array_map('location_row_decode', $st->fetchAll());
}

function find_location(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . LOCATION_COLUMNS . ', d.name AS department_name,
                                COALESCE((SELECT sum(b.qty_on_hand) FROM mcp_inventory_balances b WHERE b.location_id = l.location_id), 0) AS units_on_hand
                           FROM mcp_locations l LEFT JOIN mcp_departments d ON d.department_id = l.department_id WHERE l.location_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : location_row_decode($r);
}

/** Units on hand + allocated + on the floor at a location — the archive check (nothing may be held there). */
function location_holds(PDO $pdo, int $id): int
{
    $st = $pdo->prepare('SELECT COALESCE(sum(abs(qty_on_hand) + qty_allocated + qty_floor_model), 0) FROM mcp_inventory_balances WHERE location_id = :id');
    $st->execute(['id' => $id]);
    return (int) $st->fetchColumn();
}

/** The active locations for a select: [{location_id, name, kind}]. */
function locations_for_pick(PDO $pdo, bool $includeInactive = false): array
{
    return $pdo->query('SELECT location_id, name, kind, is_sellable FROM mcp_locations' . ($includeInactive ? '' : ' WHERE active') . ' ORDER BY name')->fetchAll();
}
