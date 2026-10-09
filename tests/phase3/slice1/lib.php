<?php
/**
 * Helpers for the slice 1 proofs (docs/build-specs/catalog.md, "Proof"). Run through tests/phase3/slice1/run.sh: a fresh SCRATCH database, the
 * application on :8601, a fake kernel, a fake MaluDB. Everything a proof makes is named "SMOKE …". Builds on tests/phase2/lib.php.
 * The cast (bin/dev_directory.json): the owner (1, super-admin), Nora (40, Buyer + Sales + Warehouse), Sam (41, Sales), Wes (42, Warehouse),
 * Vera (43, Viewer), Ann (44, external Viewer), the expert (45, an agent holding `user`), Omar (46, no grant).
 * The world (catalog_world()): the brands "SMOKE Cloudrest" and "SMOKE Zinus"; "SMOKE Cloudrest Hybrid" (Mattress, hybrid, firmness 6) with six
 * sizes and the GTINs of tests/fixtures/sources/shopify/products-page1.json (so slice 3's fixture pulls match them); a foundation with six sizes;
 * a topper with two options (Size, Thickness); a pillow with one variant; the bundle "SMOKE Queen set" (mattress Queen + foundation Queen).
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

const JSONH = ['Accept: application/json'];

/** A signed-on jar for a fixture member (cached per proof). */
function as_member(int $m): string
{
    static $jars = [];
    if (isset($jars[$m])) { return $jars[$m]; }
    [$j, $r] = sign_on($m);
    if ($r['code'] !== 302) { fwrite(STDERR, "sign-on of $m failed: {$r['code']}\n"); }
    return $jars[$m] = $j;
}
/** A write over HTTP as a signed-on person, answered as JSON: [status, decoded body, raw]. The CSRF token is fetched from the shell once per jar. */
function act(string $jar, string $path, array $form, array $extraHeaders = []): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => array_merge(JSONH, $extraHeaders), 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [$r['code'], json_decode($r['body'], true) ?? [], $r];
}
/** A multipart write (a file upload) as a signed-on person, answered as JSON. $files: field => [path, name, type]. */
function act_files(string $jar, string $path, array $form, array $files): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $post = $form + ['csrf_token' => $tok[$jar]];
    foreach ($files as $k => [$p, $n, $t]) { $post[$k] = new CURLFile($p, $t, $n); }
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => JSONH, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode($body, true) ?? [], $body];
}
/** A screen as JSON (data only): [status, data, raw]. */
function screen(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => JSONH]); return [$r['code'], json_decode($r['body'], true)['data'] ?? [], $r]; }
function msg(array $body): string { return (string) ($body['error']['message'] ?? ''); }
function fields(array $body): array { return (array) ($body['error']['fields'] ?? []); }
/** A write under an action token (no session, no CSRF): [status, body]. */
function act_token(string $path, array $form, array $headers): array { $r = req('POST', $path, ['headers' => array_merge(JSONH, $headers), 'form' => $form]); return [(int) $r['code'], json_decode($r['body'], true) ?? [], $r]; }
/** SQL as postgres (the proof's own writes to tables the writer role may not touch: inv_role_rights). */
function psql_exec(string $sql): string { return trim((string) shell_exec('sudo -n -u postgres psql -d ' . escapeshellarg(need('DB_NAME')) . ' -Atc ' . escapeshellarg($sql) . ' 2>&1')); }
function variant_id(string $sku): ?int { $v = one('SELECT id FROM product_variants WHERE lower(sku) = lower(:s)', ['s' => $sku]); return $v === false || $v === null ? null : (int) $v; }
function product_id(string $name): ?int { $v = one('SELECT id FROM products WHERE name = :n ORDER BY id LIMIT 1', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }
function brand_id(string $name): ?int { $v = one('SELECT id FROM brands WHERE lower(name) = lower(:n)', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }
/** The six sizes of the Shopify fixture's CloudRest Hybrid: [size, sku suffix, barcode, price]. */
function fixture_sizes(): array
{
    $d = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tests/fixtures/sources/shopify/products-page1.json'), true);
    $out = [];
    foreach ($d['products'][0]['variants'] as $v) { $out[] = [$v['title'], $v['sku'], $v['barcode'], $v['price']]; }
    return $out;
}
/**
 * The world of the spec, made through the handlers as Nora. Idempotent: finds it when it exists. Returns the ids.
 */
function catalog_world(): array
{
    $nora = as_member(40);
    if (brand_id('SMOKE Cloudrest') === null) {
        act($nora, '/brands/save.php', ['name' => 'SMOKE Cloudrest', 'website' => 'https://cloudrest.example']);
        act($nora, '/brands/save.php', ['name' => 'SMOKE Zinus', 'website' => 'https://zinus.example']);
    }
    $w = ['brand_cloudrest' => brand_id('SMOKE Cloudrest'), 'brand_zinus' => brand_id('SMOKE Zinus')];
    if (product_id('SMOKE Cloudrest Hybrid') === null) {
        [, $b] = act($nora, '/products/save.php', ['name' => 'SMOKE Cloudrest Hybrid', 'type' => 'mattress', 'brand' => $w['brand_cloudrest'], 'description' => 'A hybrid with cooling.', 'attributes' => json_encode(['type' => 'hybrid', 'firmness' => 6]), 'tags' => 'hybrid,cooling', 'status' => 'active']);
        $pid = (int) $b['record_id'];
        foreach (fixture_sizes() as [$size, $sku, $barcode, $price]) {
            act($nora, '/variants/save.php', ['product' => $pid, 'sku' => 'SMOKE-' . $sku, 'option_values' => json_encode(['Size' => $size]), 'barcode' => $barcode, 'retail_price' => $price, 'map_price' => $price, 'cost_price' => number_format((float) $price * 0.5, 2, '.', ''), 'weight' => '85.5', 'length' => '80', 'width' => '60', 'height' => '12']);
        }
        [, $b] = act($nora, '/products/save.php', ['name' => 'SMOKE Cloudrest Foundation', 'type' => 'foundation', 'brand' => $w['brand_cloudrest'], 'status' => 'active']);
        $fid = (int) $b['record_id'];
        foreach (fixture_sizes() as [$size, $sku, $barcode, $price]) {
            act($nora, '/variants/save.php', ['product' => $fid, 'sku' => 'SMOKE-FND-' . substr($sku, 6), 'option_values' => json_encode(['Size' => $size]), 'retail_price' => '299.00', 'cost_price' => '120.00']);
        }
        [, $b] = act($nora, '/products/save.php', ['name' => 'SMOKE Harbor Latex Topper', 'type' => 'topper', 'brand' => $w['brand_zinus'], 'options' => ['Size', 'Thickness'], 'status' => 'active']);
        $tid = (int) $b['record_id'];
        act($nora, '/variants/save.php', ['product' => $tid, 'sku' => 'SMOKE-HP-LT-Q-2', 'option_values' => json_encode(['Size' => 'Queen', 'Thickness' => '2 inch']), 'barcode' => '850123450103', 'retail_price' => '349.00']);
        act($nora, '/variants/save.php', ['product' => $tid, 'sku' => 'SMOKE-HP-LT-Q-3', 'option_values' => json_encode(['Size' => 'Queen', 'Thickness' => '3 inch']), 'barcode' => '850123450110', 'retail_price' => '449.00', 'mpn' => 'HPLT-Q']);
        [, $b] = act($nora, '/products/save.php', ['name' => 'SMOKE Northwind Pillow', 'type' => 'pillow', 'status' => 'active']);
        act($nora, '/variants/save.php', ['product' => (int) $b['record_id'], 'sku' => 'SMOKE-NW-PIL-STD', 'option_values' => json_encode(['Size' => 'Standard']), 'retail_price' => '59.00', 'map_price' => '69.00']);
        [, $b] = act($nora, '/products/save.php', ['name' => 'SMOKE Queen set', 'type' => 'mattress', 'brand' => $w['brand_cloudrest'], 'kind' => 'bundle', 'status' => 'active']);
        $sid = (int) $b['record_id'];
        [, $b] = act($nora, '/variants/save.php', ['product' => $sid, 'sku' => 'SMOKE-SET-Q', 'option_values' => json_encode(['Size' => 'Queen']), 'retail_price' => '1199.00']);
        act($nora, '/variants/bundle.php', ['bundle' => (int) $b['record_id'], 'components' => json_encode([['variant' => 'SMOKE-NW-CR-Q', 'qty' => 1], ['variant' => 'SMOKE-FND-Q', 'qty' => 1]])]);
    }
    $w['mattress'] = product_id('SMOKE Cloudrest Hybrid');
    $w['foundation'] = product_id('SMOKE Cloudrest Foundation');
    $w['topper'] = product_id('SMOKE Harbor Latex Topper');
    $w['pillow'] = product_id('SMOKE Northwind Pillow');
    $w['set'] = product_id('SMOKE Queen set');
    $w['queen'] = variant_id('SMOKE-NW-CR-Q');
    $w['calking'] = variant_id('SMOKE-NW-CR-CK');
    $w['fnd_queen'] = variant_id('SMOKE-FND-Q');
    $w['set_q'] = variant_id('SMOKE-SET-Q');
    $w['pillow_v'] = variant_id('SMOKE-NW-PIL-STD');
    $w['topper_q2'] = variant_id('SMOKE-HP-LT-Q-2');
    return $w;
}
/** A small JPEG and PNG for the image proofs: [jpeg path, png path]. */
function smoke_images(): array
{
    $j = sys_get_temp_dir() . '/inv-smoke-' . getmypid() . '.jpg';
    $p = sys_get_temp_dir() . '/inv-smoke-' . getmypid() . '.png';
    if (!is_file($j)) {
        $im = imagecreatetruecolor(64, 48);
        imagefill($im, 0, 0, 0x3454d1);
        imagejpeg($im, $j, 85);
        imagepng($im, $p);
    }
    return [$j, $p];
}
