<?php
declare(strict_types=1);

/** The price sheet's writes: an upsert by (supplier, variant) of the fields given (a person's row keeps source_id and last_seen_at), and a delete. */

function save_supplier_item(PDO $pdo, int $supplierId, int $variantId, array $f): int
{
    $cols = ['supplier_sku', 'cost', 'lead_time_days', 'moq', 'active'];
    $given = array_intersect_key($f, array_flip($cols));
    $ins = ['supplier_id' => $supplierId, 'variant_id' => $variantId] + $given;
    $names = array_keys($ins);
    $vals = array_map(static fn ($k) => $k === 'active' ? 'CAST(:active AS boolean)' : ($k === 'cost' ? 'CAST(:cost AS numeric)' : ':' . $k), $names);
    $set = array_map(static fn ($k) => $k . ' = EXCLUDED.' . $k, array_keys($given));
    $sql = 'INSERT INTO supplier_items (' . implode(', ', $names) . ') VALUES (' . implode(', ', $vals) . ') ON CONFLICT (supplier_id, variant_id) DO '
        . ($set === [] ? 'UPDATE SET supplier_id = EXCLUDED.supplier_id' : 'UPDATE SET ' . implode(', ', $set)) . ' RETURNING id';
    if (array_key_exists('active', $ins)) { $ins['active'] = $ins['active'] ? 1 : 0; }
    $st = $pdo->prepare($sql);
    $st->execute($ins);
    return (int) $st->fetchColumn();
}

function remove_supplier_item(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('DELETE FROM supplier_items si USING product_variants v WHERE si.id = :id AND v.id = si.variant_id RETURNING si.id, si.supplier_id, si.variant_id, v.sku, si.supplier_sku');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}
