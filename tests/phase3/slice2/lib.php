<?php
/**
 * Helpers for the slice 2 proofs (docs/build-specs/stock.md, "Proof"). Run through tests/phase3/slice2/run.sh on the scratch database inv_dev2b.
 * Builds on slice 1's library (the cast, act(), screen(), catalog_world()). The world (stock_world()): slice 1's catalog; the owner makes
 * "SMOKE Warehouse" (warehouse, negative off), "SMOKE Showroom" (showroom, sellable), "SMOKE Returns" (returns, not sellable); the supplier
 * "SMOKE Dreamland" with a stock purchase order sent to it (King × 10 at 180.00, Full × 5 at 150.00) and "SMOKE Other Supplier" with one of its own
 * — made by SQL as postgres, because the purchase order's screens are slice 6's.
 */
require dirname(__DIR__) . '/slice1/lib.php';

function location_id(string $name): ?int { $v = one('SELECT id FROM locations WHERE lower(name) = lower(:n)', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }
function balance(int $variant, int $location): array
{
    $r = q('SELECT qty_on_hand, qty_allocated, qty_floor_model FROM inventory_balances WHERE variant_id = :v AND location_id = :l', ['v' => $variant, 'l' => $location]);
    return $r === [] ? ['qty_on_hand' => 0, 'qty_allocated' => 0, 'qty_floor_model' => 0] : array_map('intval', $r[0]);
}
function on_hand(int $variant, int $location): int { return balance($variant, $location)['qty_on_hand']; }
function txns(string $kind, int $id): array { return q('SELECT * FROM inventory_transactions WHERE reference_kind = :k AND reference_id = :i ORDER BY id', ['k' => $kind, 'i' => $id]); }
/** The latest activity row of an action (after $since). */
function last_log(string $action, int $since = 0): ?array { $r = q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id DESC LIMIT 1', ['a' => $action, 's' => $since]); return $r[0] ?? null; }
function after_of(?array $row): array { return $row === null ? [] : (json_decode((string) $row['after'], true) ?? []); }

function stock_world(): array
{
    $w = catalog_world();
    $owner = as_member(1);
    if (location_id('SMOKE Warehouse') === null) {
        act($owner, '/locations/save.php', ['name' => 'SMOKE Warehouse', 'kind' => 'warehouse', 'address' => "1 Dock Road\nSpringfield", 'is_sellable' => 'yes', 'allow_negative' => 'no']);
        act($owner, '/locations/save.php', ['name' => 'SMOKE Showroom', 'kind' => 'showroom', 'is_sellable' => 'yes', 'allow_negative' => 'no']);
        act($owner, '/locations/save.php', ['name' => 'SMOKE Returns', 'kind' => 'returns', 'is_sellable' => 'no']);
    }
    $w['wh'] = location_id('SMOKE Warehouse');
    $w['sr'] = location_id('SMOKE Showroom');
    $w['ret'] = location_id('SMOKE Returns');
    $w['king'] = variant_id('SMOKE-NW-CR-K');
    $w['full'] = variant_id('SMOKE-NW-CR-F');
    $w['twin'] = variant_id('SMOKE-NW-CR-T');
    if (one("SELECT id FROM suppliers WHERE name = 'SMOKE Dreamland'") === false) {
        psql_exec("INSERT INTO suppliers (name, kind) VALUES ('SMOKE Dreamland', 'vendor'), ('SMOKE Other Supplier', 'vendor')");
        $sup = (int) one("SELECT id FROM suppliers WHERE name = 'SMOKE Dreamland'");
        $oth = (int) one("SELECT id FROM suppliers WHERE name = 'SMOKE Other Supplier'");
        psql_exec("INSERT INTO purchase_orders (number, supplier_id, kind, location_id) VALUES ('', $sup, 'stock', {$w['wh']}), ('', $oth, 'stock', {$w['wh']})");
        $po = (int) one('SELECT id FROM purchase_orders WHERE supplier_id = :s', ['s' => $sup]);
        $po2 = (int) one('SELECT id FROM purchase_orders WHERE supplier_id = :s', ['s' => $oth]);
        psql_exec("INSERT INTO purchase_order_lines (purchase_order_id, line_no, variant_id, qty_ordered, unit_cost) VALUES ($po, 1, {$w['king']}, 10, 180.00), ($po, 2, {$w['full']}, 5, 150.00), ($po2, 1, {$w['twin']}, 3, 100.00)");
        psql_exec("SELECT inv_po_send($po, 1, 'email'); SELECT inv_po_send($po2, 1, 'email')");
    }
    $w['supplier'] = (int) one("SELECT id FROM suppliers WHERE name = 'SMOKE Dreamland'");
    $w['other_supplier'] = (int) one("SELECT id FROM suppliers WHERE name = 'SMOKE Other Supplier'");
    $w['po'] = (int) one('SELECT id FROM purchase_orders WHERE supplier_id = :s ORDER BY id LIMIT 1', ['s' => $w['supplier']]);
    $w['po_other'] = (int) one('SELECT id FROM purchase_orders WHERE supplier_id = :s ORDER BY id LIMIT 1', ['s' => $w['other_supplier']]);
    return $w;
}
