<?php
declare(strict_types=1);

/** The price sheets (sources.md "Query functions"): supplier_items from mcp_supplier_items (cost walled by the view). */

function supplier_items(PDO $pdo, array $filters, int $limit = 100, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (!empty($filters['supplier'])) { $w[] = 'si.supplier_id = :s'; $args['s'] = (int) $filters['supplier']; }
    if (!empty($filters['variant'])) { $w[] = 'si.variant_id = :v'; $args['v'] = (int) $filters['variant']; }
    if (!empty($filters['active_only'])) { $w[] = 'si.active'; }
    if (!empty($filters['stale_days'])) { $w[] = '(si.last_seen_at IS NULL OR si.last_seen_at < now() - make_interval(days => :sd))'; $args['sd'] = (int) $filters['stale_days']; }
    if (trim((string) ($filters['q'] ?? '')) !== '') { $w[] = '(si.sku ILIKE :q OR si.supplier_sku ILIKE :q2 OR v.product_name ILIKE :q3)'; $args['q'] = $args['q2'] = $args['q3'] = '%' . trim((string) $filters['q']) . '%'; }
    $st = $pdo->prepare('SELECT si.*, v.product_id, v.product_name, v.size_name, (SELECT name FROM mcp_sources s WHERE s.source_id = si.source_id) AS source_name
                           FROM mcp_supplier_items si JOIN mcp_product_variants v ON v.variant_id = si.variant_id'
        . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w)) . ' ORDER BY v.product_name, v.size_key NULLS LAST, si.sku LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('supplier_item_decode', $st->fetchAll());
}

function supplier_item_decode(array $r): array
{
    foreach (['supplier_item_id', 'supplier_id', 'variant_id', 'moq'] as $k) { $r[$k] = (int) $r[$k]; }
    foreach (['lead_time_days', 'source_id'] as $k) { $r[$k] = $r[$k] === null ? null : (int) $r[$k]; }
    $r['active'] = (bool) $r['active'];
    $r['cost_withheld'] = (bool) $r['cost_withheld'];
    return $r;
}

function find_supplier_item(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT si.*, v.product_id, v.product_name, v.size_name, NULL AS source_name FROM mcp_supplier_items si JOIN mcp_product_variants v ON v.variant_id = si.variant_id WHERE si.supplier_item_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : supplier_item_decode($r);
}

function present_supplier_item(array $r): array
{
    return ['supplier_item_id' => $r['supplier_item_id'], 'supplier_id' => $r['supplier_id'], 'supplier_name' => $r['supplier_name'], 'variant_id' => $r['variant_id'], 'sku' => $r['sku'],
            'product_name' => $r['product_name'], 'size_name' => $r['size_name'], 'supplier_sku' => $r['supplier_sku'], 'cost' => $r['cost'], 'cost_withheld' => $r['cost_withheld'],
            'lead_time_days' => $r['lead_time_days'], 'moq' => $r['moq'], 'active' => $r['active'], 'last_seen_at' => json_ts($r['last_seen_at']), 'source_id' => $r['source_id'], 'source_name' => $r['source_name'] ?? null];
}
