<?php
/**
 * Helpers for the slice 4 proofs (docs/build-specs/find.md, "Proof"). Run through tests/phase3/slice4/run.sh on the scratch database inv_dev4,
 * the application on :8601, the fake kernel :8602, the fake MaluDB :8603 and THE FIXTURE SERVER on :8606. Builds on slice 3's library (which
 * builds on slice 1's: the cast, act(), screen(), catalog_world(), sources_world()).
 *
 * The world (find_world()) — the spec's, on the fixtures that exist (the Shopify, feed and WooCommerce fixtures sell the Cloudrest Hybrid, so
 * the spec's "Purple hybrid" is the catalog's SMOKE Cloudrest Hybrid; recorded in "Built and proven"):
 *   locations SMOKE Warehouse (sellable), SMOKE Showroom (sellable, a floor model), SMOKE Returns (not sellable);
 *   suppliers SMOKE Malouf (lead 5 — the Shopify fixture as a SUPPLIER source, has search) and SMOKE Zinus (lead 3 — the feed fixture);
 *   a reference source SMOKE Casper site (the Shopify fixture again), a PAUSED source (the WooCommerce fixture, pulled once then paused) and a
 *   BLOCKED one (the Shopify connector at the router's bot wall);
 *   stock: 4 Queens at the Warehouse (1 allocated), 1 King on the Showroom floor; a sent stock PO for 2 Queens (on order);
 *   the Foundation King is the variant nobody offers and nobody holds (the spec's "Twin nobody offers": the fixtures offer every hybrid size).
 */
require dirname(__DIR__) . '/slice3/lib.php';

function location_id(string $name): ?int { $v = one('SELECT id FROM locations WHERE lower(name) = lower(:n)', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }
function supplier_id(string $name): ?int { $v = one('SELECT id FROM suppliers WHERE name = :n', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }
/** The fragment's HTML as a member (a browser session). */
function frag(string $jar, string $path, array $headers = []): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => array_merge(['HX-Request: true'], $headers)]); return [(int) $r['code'], (string) $r['body'], $r]; }
/** The full page's HTML (no HX-Request). */
function html(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar]); return [(int) $r['code'], (string) $r['body'], $r]; }
function header_of(array $r, string $name): ?string { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', (string) ($r['headers'] ?? ''), $m) ? $m[1] : null; }
/** The worker's passes (CLI, the scratch env): the JSON report's `ran`. */
function worker(string $passes): array
{
    $out = (string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . escapeshellarg($passes) . ' 2>&1');
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $last = json_decode((string) end($lines), true);
    if (!is_array($last)) { fwrite(STDERR, "worker said: $out\n"); return []; }
    return $last['ran'] ?? $last;
}
function lv_of(int $sid, string $sku): ?int { $r = lv_by($sid, 'sku', $sku); return $r === null ? null : (int) $r['id']; }
/** A signed bridge call (find.md "The bridge"): [status, body]. $sign = false sends no header; $ts shifts the clock. */
function bridge(array $body, array $o = []): array
{
    $raw = json_encode($body);
    $ts = (string) (time() + ($o['skew'] ?? 0));
    $key = $o['key'] ?? need('ACTIONS_RELAY_KEY');
    $h = ['Content-Type: application/json'];
    if (($o['sign'] ?? true) !== false) { $h[] = 'X-INV-Bridge: ' . $ts . '.' . hash_hmac('sha256', 'inv-bridge:' . $ts . '.' . hash('sha256', $raw), $key); }
    $r = req($o['method'] ?? 'POST', bridge_base() . '/internal/bridge.php', ['headers' => $h, 'raw' => $raw]);
    return [(int) $r['code'], json_decode((string) $r['body'], true) ?? [], $r];
}
/** The bridge's door: under a real Apache the INTERNAL vhost (:8607 — the public one on :8601 answers 404 under /internal/); php -S plays the internal port. */
function bridge_base(): string { return getenv('INV_APP') === 'apache' ? 'http://127.0.0.1:8607' : BASE; }

/** Counts of what a live ask writes. */
function written_counts(): array
{
    return ['pulls' => (int) one('SELECT count(*) FROM source_pulls'), 'listings' => (int) one('SELECT count(*) FROM listings'), 'lvs' => (int) one('SELECT count(*) FROM listing_variants'), 'snapshots' => (int) one('SELECT count(*) FROM offer_snapshots')];
}

function find_world(): array
{
    $w = sources_world();
    $owner = as_member(1);
    $nora = as_member(40);
    if (location_id('SMOKE Warehouse') === null) {
        act($owner, '/locations/save.php', ['name' => 'SMOKE Warehouse', 'kind' => 'warehouse', 'address' => "1 Dock Road\nSpringfield", 'is_sellable' => 'yes', 'allow_negative' => 'no']);
        act($owner, '/locations/save.php', ['name' => 'SMOKE Showroom', 'kind' => 'showroom', 'is_sellable' => 'yes', 'allow_negative' => 'no']);
        act($owner, '/locations/save.php', ['name' => 'SMOKE Returns', 'kind' => 'returns', 'is_sellable' => 'no']);
    }
    $w['wh'] = location_id('SMOKE Warehouse');
    $w['sr'] = location_id('SMOKE Showroom');
    $w['ret'] = location_id('SMOKE Returns');
    $w['twin'] = variant_id('SMOKE-NW-CR-T');
    $w['fnd_king'] = variant_id('SMOKE-FND-K');
    if (supplier_id('SMOKE Malouf') === null) {
        psql_exec("INSERT INTO suppliers (name, kind, dropships, lead_time_days) VALUES ('SMOKE Malouf', 'vendor', true, 5), ('SMOKE Zinus', 'vendor', true, 3)");
        // the firmness chip's attribute, and the stock: 4 Queens at the Warehouse (1 allocated), a King on the Showroom floor, 2 returned Twins (not sellable)
        psql_exec("UPDATE products SET attributes = attributes || '{\"firmness_word\": \"medium\"}' WHERE id = {$w['mattress']}");
        psql_exec("SELECT inv_post_txn('receipt', {$w['queen']}, {$w['wh']}, 4, 450.00, 'opening', 1, 'smoke-s4-q4', 'none', NULL, NULL, NULL, 1);"
            . " SELECT inv_allocate({$w['queen']}, {$w['wh']}, 1);"
            . " SELECT inv_post_txn('receipt', {$w['king']}, {$w['sr']}, 1, 600.00, 'opening', 2, 'smoke-s4-k1', 'none', NULL, NULL, NULL, 1);"
            . " SELECT inv_post_txn('floor_model_in', {$w['king']}, {$w['sr']}, 1, NULL, 'adjustment', 3, 'smoke-s4-kfm', 'none', NULL, NULL, NULL, 1);"
            . " SELECT inv_post_txn('receipt', {$w['twin']}, {$w['ret']}, 2, 300.00, 'opening', 4, 'smoke-s4-t2', 'none', NULL, NULL, NULL, 1)");
        // fifty more variants (the 50 cap): a bulk product in fifty sizes of words
        $tp = (int) one("SELECT id FROM product_types WHERE key = 'pillow'");
        psql_exec("INSERT INTO products (product_type_id, name, status) VALUES ($tp, 'SMOKE Bulkline Pillow', 'active')");
        $bp = (int) one("SELECT id FROM products WHERE name = 'SMOKE Bulkline Pillow'");
        $vals = [];
        for ($i = 1; $i <= 55; $i++) { $vals[] = sprintf("(%d, 'SMOKE-BULK-%02d', '{\"Size\": \"Style %02d\"}', 39.00)", $bp, $i, $i); }
        psql_exec('INSERT INTO product_variants (product_id, sku, option_values, retail_price) VALUES ' . implode(', ', $vals));
    }
    $w['malouf'] = supplier_id('SMOKE Malouf');
    $w['zinus'] = supplier_id('SMOKE Zinus');
    $mapping = ['supplier_sku' => 'Item Number', 'gtin' => 'UPC', 'name' => 'Description', 'size' => 'Size', 'cost' => 'Dealer Cost', 'map_price' => 'MAP', 'qty' => 'Qty Available', 'lead_time_days' => 'Lead Time (days)', 'brand' => 'Brand', 'product' => 'Model'];
    if (source_id('SMOKE Malouf store') === null) {
        act($nora, '/sources/save.php', ['connector' => 'shopify', 'name' => 'SMOKE Malouf store', 'role' => 'supplier', 'supplier' => $w['malouf'], 'base_url' => FIX, 'schedule_minutes' => '60']);
        act($nora, '/sources/save.php', ['connector' => 'feed', 'name' => 'SMOKE Zinus feed', 'role' => 'supplier', 'supplier' => $w['zinus'], 'schedule_minutes' => '60',
            'settings_feed' => ['_present' => '1', 'transport' => 'https', 'url' => FIX . '/feed.csv', 'format' => 'auto', 'delimiter' => 'auto', 'has_header' => 'yes', 'currency' => 'USD', 'mapping' => $mapping]]);
        act($nora, '/sources/save.php', ['connector' => 'shopify', 'name' => 'SMOKE Casper site', 'role' => 'reference', 'base_url' => FIX, 'schedule_minutes' => '360']);
        act($nora, '/sources/save.php', ['connector' => 'woocommerce', 'name' => 'SMOKE Paused store', 'role' => 'reference', 'base_url' => FIX, 'schedule_minutes' => '360']);
        act($nora, '/sources/save.php', ['connector' => 'shopify', 'name' => 'SMOKE Blocked store', 'role' => 'reference', 'base_url' => FIX . '/wall', 'schedule_minutes' => '360']);
        worker('pulls');
        act($nora, '/sources/pause.php', ['source' => source_id('SMOKE Paused store'), 'reason' => 'SMOKE paused for the proof']);
        // a sent stock PO for 2 Queens (on order)
        psql_exec("INSERT INTO purchase_orders (number, supplier_id, kind, location_id) VALUES ('', {$w['zinus']}, 'stock', {$w['wh']})");
        $po = (int) one('SELECT id FROM purchase_orders WHERE supplier_id = :s ORDER BY id DESC LIMIT 1', ['s' => $w['zinus']]);
        psql_exec("INSERT INTO purchase_order_lines (purchase_order_id, line_no, variant_id, qty_ordered, unit_cost) VALUES ($po, 1, {$w['queen']}, 2, 495.00); SELECT inv_po_send($po, 1, 'email')");
    }
    foreach (['malouf' => 'SMOKE Malouf store', 'zinus_feed' => 'SMOKE Zinus feed', 'casper' => 'SMOKE Casper site', 'paused' => 'SMOKE Paused store', 'blocked' => 'SMOKE Blocked store'] as $k => $n) {
        $w['src_' . $k] = source_id($n);
    }
    return $w;
}
/** The proof's own connection acts as a member (the views and the read functions ask who). */
function as_db(int $member): void { pdo()->exec("SELECT set_config('app.member_id', '$member', false)"); }
