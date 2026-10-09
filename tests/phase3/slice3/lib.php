<?php
/**
 * Helpers for the slice 3 proofs (docs/build-specs/sources.md, "Proof"). Run through tests/phase3/slice3/run.sh on the scratch database inv_dev3,
 * the application on :8601, the fake kernel :8602, the fake MaluDB :8603 and THE FIXTURE SERVER on :8606 (tests/fixtures/sources/router.php).
 * Builds on slice 1's library (the cast, act(), screen(), catalog_world()). The world (sources_world()): slice 1's catalog; the supplier
 * "SMOKE Dealer Co" (drop-ships) whose price sheet names two of the feed fixture's Item Numbers (rule 2 — the Cal King, whose UPC fails its check
 * digit, and the pillow, which has no barcode in our catalog); the King's MPN = the marked-up page's King MPN (rule 3); an ASIN identifier on the
 * Queen = the WooCommerce fixture's Queen variation id (rule 4) — every value READ from the fixture files at seed time, never typed.
 * The six sources are made by sources.php through the handlers; sources_by_name() finds them.
 */
require dirname(__DIR__) . '/slice1/lib.php';

const FIX = 'http://127.0.0.1:8606';

/** The latest activity row of an action (after $since). */
function last_log(string $action, int $since = 0): ?array { $r = q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id DESC LIMIT 1', ['a' => $action, 's' => $since]); return $r[0] ?? null; }
function after_of(?array $row): array { return $row === null ? [] : (json_decode((string) $row['after'], true) ?? []); }
function fix_dir(): string { return dirname(__DIR__, 3) . '/tests/fixtures/sources'; }
function fix_log(): array { $f = getenv('INV_FIX_LOG'); return $f && is_file($f) ? array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f)))) : []; }
function fix_variant(string $v): void { file_put_contents((string) getenv('INV_FIX_TMP') . '/variant', $v); }
function source_id(string $name): ?int { $v = one('SELECT id FROM sources WHERE lower(name) = lower(:n)', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }
function source_row(int $id): array { return q('SELECT * FROM sources WHERE id = :id', ['id' => $id])[0]; }
function last_pull(int $sid, ?string $kind = null): ?array { $r = q('SELECT * FROM source_pulls WHERE source_id = :s' . ($kind ? ' AND kind = :k' : '') . ' ORDER BY id DESC LIMIT 1', ['s' => $sid] + ($kind ? ['k' => $kind] : [])); return $r[0] ?? null; }
function lv_by(int $sid, string $field, string $value): ?array { $r = q("SELECT lv.* FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE l.source_id = :s AND lv.$field = :v ORDER BY lv.id LIMIT 1", ['s' => $sid, 'v' => $value]); return $r[0] ?? null; }
/** Run the worker's pulls pass (CLI, the scratch env): the JSON report. */
function worker_pulls(array $env = []): array
{
    $e = '';
    foreach ($env as $k => $v) { $e .= $k . '=' . escapeshellarg((string) $v) . ' '; }
    $out = (string) shell_exec($e . 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=pulls 2>&1');
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $last = json_decode((string) end($lines), true);
    if (!is_array($last)) { fwrite(STDERR, "worker said: $out\n"); return []; }
    return $last['ran']['pulls'] ?? $last;
}
/** Move a source's clock back (the SQL's now() cannot be moved — the proof ages the rows instead of INV_WORKER_NOW). */
function age_source(int $sid, string $interval): void
{
    psql_exec("UPDATE sources SET last_ok_at = last_ok_at - interval '$interval', backoff_until = backoff_until - interval '$interval' WHERE id = $sid;"
        . " UPDATE listings SET last_seen_at = last_seen_at - interval '$interval', first_seen_at = first_seen_at - interval '$interval' WHERE source_id = $sid;"
        . " UPDATE listing_variants SET last_seen_at = last_seen_at - interval '$interval' WHERE listing_id IN (SELECT id FROM listings WHERE source_id = $sid);"
        . " UPDATE source_pulls SET started_at = started_at - interval '$interval', finished_at = finished_at - interval '$interval' WHERE source_id = $sid");
}
/** The world: the catalog, the dealer, its price sheet from the feed fixture, the King's MPN and the Queen's ASIN from the fixtures. */
function sources_world(): array
{
    $w = catalog_world();
    $w['king'] = variant_id('SMOKE-NW-CR-K');
    $w['ck'] = $w['calking'];
    $w['twin_xl'] = variant_id('SMOKE-NW-CR-TXL');
    $w['full'] = variant_id('SMOKE-NW-CR-F');
    $w['topper_q3'] = variant_id('SMOKE-HP-LT-Q-3');
    if (one("SELECT id FROM suppliers WHERE name = 'SMOKE Dealer Co'") === false) {
        psql_exec("INSERT INTO suppliers (name, kind, dropships) VALUES ('SMOKE Dealer Co', 'vendor', true)");
        $sup = (int) one("SELECT id FROM suppliers WHERE name = 'SMOKE Dealer Co'");
        $csv = array_map('str_getcsv', array_filter(explode("\n", (string) file_get_contents(fix_dir() . '/feed/supplier.csv'))));
        $by = [];
        foreach (array_slice($csv, 1) as $row) { if (($row[0] ?? '') !== '') { $by[$row[0]] = $row; } }
        // rule 2: the Cal King (its UPC fails the check digit) and the pillow (no barcode here) — by the feed's Item Number
        $ckSku = array_values(array_filter(array_keys($by), static fn ($k) => str_ends_with($k, '-CK')))[0];
        $pilSku = array_values(array_filter(array_keys($by), static fn ($k) => str_contains($k, 'PIL')))[0];
        psql_exec("INSERT INTO supplier_items (supplier_id, variant_id, supplier_sku, cost, lead_time_days) VALUES ($sup, {$w['ck']}, '$ckSku', 600.00, 7), ($sup, {$w['pillow_v']}, '$pilSku', 20.00, 2)");
        // rule 3: the marked-up page's King MPN on our King
        preg_match('~"name":"[^"]*King","sku":"[^"]*","gtin12":"[^"]*","mpn":"([^"]+)"~', (string) file_get_contents(fix_dir() . '/jsonld/page-cloudrest-hybrid.html'), $m);
        $mpn = $m[1] ?? null;
        if ($mpn === null) {
            preg_match_all('~"mpn":"([^"]+)"~', (string) file_get_contents(fix_dir() . '/jsonld/page-cloudrest-hybrid.html'), $all);
            $mpn = array_values(array_filter($all[1], static fn ($x) => str_ends_with($x, '-K')))[0];
        }
        psql_exec("UPDATE product_variants SET mpn = '$mpn' WHERE id = {$w['king']}");
        // rule 4: the WooCommerce Queen variation's id as an ASIN on our Queen
        $woo = json_decode((string) file_get_contents(fix_dir() . '/woocommerce/products-page1.json'), true);
        $qv = null;
        foreach ($woo as $p) { foreach ($p['variations'] ?? [] as $v) { foreach ($v['attributes'] as $a) { if (strtolower($a['value']) === 'queen') { $qv = (string) $v['id']; } } } }
        psql_exec("INSERT INTO variant_identifiers (variant_id, kind, value) VALUES ({$w['queen']}, 'asin', '$qv')");
    }
    if (variant_id('SMOKE-HP-LT-K-3') === null) {                    // a King topper with no barcode: the matcher's scoring has a candidate (brand, name words, size)
        act(as_member(40), '/variants/save.php', ['product' => $w['topper'], 'sku' => 'SMOKE-HP-LT-K-3', 'option_values' => json_encode(['Size' => 'King', 'Thickness' => '3 inch']), 'retail_price' => '549.00']);
    }
    $w['topper_k3'] = variant_id('SMOKE-HP-LT-K-3');
    $w['dealer'] = (int) one("SELECT id FROM suppliers WHERE name = 'SMOKE Dealer Co'");
    $w['king_mpn'] = (string) one('SELECT mpn FROM product_variants WHERE id = :v', ['v' => $w['king']]);
    $w['queen_asin'] = (string) one("SELECT value FROM variant_identifiers WHERE variant_id = :v AND kind = 'asin'", ['v' => $w['queen']]);
    foreach (['shopify' => 'SMOKE Shopify store', 'feed' => 'SMOKE Dealer feed', 'jsonld' => 'SMOKE Marked-up site', 'woo' => 'SMOKE Woo store', 'manual' => 'SMOKE Price sheet', 'walled' => 'SMOKE Walled store'] as $k => $n) {
        $w['src_' . $k] = source_id($n);
    }
    return $w;
}
