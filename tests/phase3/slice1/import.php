<?php
/** Import: a CSV of nine rows previewed, mapped by header and by letter, run with update_existing; a second run without; the JSON one-POST path; the log. */
require __DIR__ . '/lib.php';
$w = catalog_world();
$nora = as_member(40); $vera = as_member(43);
echo "The import\n";
$since = last_activity_id();
$csv = sys_get_temp_dir() . '/inv-import-' . getmypid() . '.csv';
file_put_contents($csv, implode("\n", [
    'Product,Brand,Type,Size,SKU,GTIN,MPN,Retail,Cost,Ships',
    'SMOKE Zinus Green Tea,SMOKE Zinus,Mattress,Twin,SMOKE-ZN-GT-T,850123450202,ZGT-T,299,120,parcel',
    'SMOKE Zinus Green Tea,SMOKE Zinus,Mattress,Queen,SMOKE-ZN-GT-Q,850123450219,ZGT-Q,399,160,parcel',
    'SMOKE Zinus Green Tea,SMOKE Zinus,Mattress,King,SMOKE-ZN-GT-K,850123450226,ZGT-K,499,200,parcel',
    'SMOKE Harbor Protector,SMOKE Harbor,Protector,Queen,SMOKE-HB-PR-Q,850123450301,,49,18,parcel',
    'SMOKE Harbor Protector,SMOKE Harbor,Protector,King,SMOKE-HB-PR-K,850123450318,,59,22,parcel',
    'SMOKE Harbor Protector,SMOKE Harbor,Protector,Cal King,SMOKE-HB-PR-CK,850123450325,,59,22,parcel',
    'SMOKE Cloudrest Hybrid,SMOKE Cloudrest,Mattress,Queen,SMOKE-NW-CR-Q,850123450035,,979,,ltl',
    'SMOKE Bad GTIN,SMOKE Zinus,Mattress,Twin,SMOKE-BAD-1,012345678906,,100,,',
    'SMOKE No SKU,SMOKE Zinus,Mattress,Twin,,,,100,,',
]) . "\n");
// step 1 → 2 in the browser: preview=1 keeps the upload and lands on step 2
$tok = csrf_of(page($nora, '/')['body']);
$ch = curl_init(BASE . '/products/import.php');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['csrf_token' => $tok, 'preview' => '1', 'file' => new CURLFile($csv, 'text/csv', 'catalog.csv')], CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $nora, CURLOPT_COOKIEJAR => $nora]);
$raw = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
preg_match('/^Location: (.+)$/mi', $raw, $m);
$loc = trim($m[1] ?? '');
ok($code === 302 && preg_match('#^/catalog/import\?upload=[a-f0-9]{32}$#', $loc) === 1, 'preview=1: 302 to step 2 with the upload token');
$upload = substr($loc, strrpos($loc, '=') + 1);
$h = page($nora, $loc)['body'];
ok(str_contains($h, 'id="import-preview-table"') && str_contains($h, 'SMOKE Zinus Green Tea') && substr_count($h, '<tr>') >= 5 && str_contains($h, 'id="import-mapping-sku"') && str_contains($h, 'id="import-mapping-gtin"'), 'step 2: the headers, five rows, a select per mapping field');
ok(preg_match('/id="import-mapping-sku"[^>]*>.*?<option value="4" selected>/s', $h) === 1 && preg_match('/id="import-mapping-gtin"[^>]*>.*?<option value="5" selected>/s', $h) === 1, 'the mapping guessed from the headers (SKU → E, GTIN → F)');
ok(is_file(dirname(__DIR__, 3) . '/storage/imports/' . $upload . '.csv'), 'the upload is kept under storage/imports');
[$c, $b] = act($nora, '/products/import.php', ['upload' => $upload, 'mapping' => ['name' => '0', 'brand' => 'B', 'type' => '2', 'size' => '3', 'sku' => '4', 'gtin' => 'F', 'mpn' => '6', 'retail_price' => '7', 'cost_price' => '8', 'ships_how' => '9'], 'update_existing' => 'yes']);
ok($c === 200 && $b['ok'] === true && $b['rows'] === 9 && $b['products_created'] === 2 && $b['variants_created'] === 6 && $b['variants_updated'] === 1 && count($b['skipped']) === 2, 'the run (mapped by index and by letter): 2 products, 6 variants created, 1 updated, 2 skipped');
ok(str_contains($b['skipped'][0]['reason'], 'not a GTIN') && $b['skipped'][1]['reason'] === 'no SKU', 'the skipped rows carry their reasons (a bad GTIN, no SKU)');
ok(str_starts_with((string) $b['location'], '/catalog/import?result='), 'the location is step 3');
$h = page($nora, $b['location'])['body'];
ok(str_contains($h, 'id="import-result"') && str_contains($h, 'id="import-result-created">6<') && str_contains($h, 'id="import-result-skipped">2<') && str_contains($h, 'id="import-result-skipped-table"') && str_contains($h, 'id="import-result-products-btn"'), 'step 3: the counts, the skipped table, Back to products');
clearstatcache();
ok(!is_file(dirname(__DIR__, 3) . '/storage/imports/' . $upload . '.csv'), 'the upload is removed after the run (' . implode(',', array_map('basename', glob(dirname(__DIR__, 3) . '/storage/imports/*') ?: [])) . ')');
ok(brand_id('SMOKE Harbor') !== null && product_id('SMOKE Harbor Protector') !== null && product_id('SMOKE Zinus Green Tea') !== null, 'a new brand and two products were made');
$v = q('SELECT sku, size_key, barcode, mpn, retail_price, cost_price, ships_how, product_id FROM product_variants WHERE sku = :s', ['s' => 'SMOKE-ZN-GT-Q'])[0];
ok($v['size_key'] === 'queen' && $v['barcode'] === '00850123450219' && $v['mpn'] === 'ZGT-Q' && $v['retail_price'] === '399.00' && $v['cost_price'] === '160.00' && $v['ships_how'] === 'parcel', 'a created variant carries size, GTIN-14, MPN, prices, how it ships');
ok((int) one('SELECT count(*) FROM product_variants WHERE product_id = :p', ['p' => $v['product_id']]) === 3 && one('SELECT status FROM products WHERE id = :p', ['p' => $v['product_id']]) === 'active', 'the three Green Tea rows became one product with three variants, active');
ok((int) one('SELECT count(*) FROM product_variants WHERE sku = \'SMOKE-HB-PR-CK\' AND size_key = \'california_king\'') === 1, '"Cal King" in the file → california_king');
ok(one('SELECT retail_price FROM product_variants WHERE id = :v', ['v' => $w['queen']]) === '979.00', 'the existing Queen was updated (retail 979)');
$ph = q("SELECT reason, source_kind FROM price_history WHERE variant_id = :v ORDER BY id DESC LIMIT 1", ['v' => $w['queen']])[0];
ok($ph['reason'] === 'import' && $ph['source_kind'] === 'import', 'its history row says import / import');
ok(one('SELECT ships_how FROM product_variants WHERE id = :v', ['v' => $w['queen']]) === 'ltl', 'and how it ships was updated');
// a second run without update_existing: the existing rows skipped
[$c, $b] = act_files($nora, '/products/import.php', ['mapping' => json_encode(['name' => 0, 'brand' => 1, 'type' => 2, 'size' => 3, 'sku' => 4, 'gtin' => 5, 'retail_price' => 7])], ['file' => [$csv, 'catalog.csv', 'text/csv']]);
ok($c === 200 && $b['variants_created'] === 0 && $b['variants_updated'] === 0 && $b['products_created'] === 0 && count($b['skipped']) === 9, 'the JSON one-POST path, without update_existing: every existing SKU skipped, nothing created (' . count($b['skipped']) . ' skipped)');
ok(count(array_filter($b['skipped'], static fn ($s) => str_contains($s['reason'], 'exists'))) === 7, 'seven say "exists (update existing is off)"');
ok((int) one("SELECT count(*) FROM products WHERE name = 'SMOKE Bad GTIN'") === 0, 'a product made in a rolled-back row is not left behind');
[$c, $b] = act_files($nora, '/products/import.php', ['mapping' => json_encode(['name' => 0])], ['file' => [$csv, 'catalog.csv', 'text/csv']]);
ok($c === 422 && str_contains(msg($b), 'SKU at least'), 'a mapping without the SKU: 422');
[$c, $b] = act_files($nora, '/products/import.php', ['mapping' => json_encode(['name' => 0, 'sku' => 4, 'colour' => 2])], ['file' => [$csv, 'catalog.csv', 'text/csv']]);
ok($c === 422 && str_contains(msg($b), 'Unknown mapping field'), 'an unknown field: 422');
ok(act($nora, '/products/import.php', ['mapping' => '{}'])[0] === 422, 'no file and no upload: 422');
ok(act_files($vera, '/products/import.php', ['mapping' => json_encode(['name' => 0, 'sku' => 4])], ['file' => [$csv, 'c.csv', 'text/csv']])[0] === 403, 'Vera may not import');
$log = activity('product.import', $since);
ok(count($log) === 2 && str_contains((string) $log[0]['after'], '"variants_created": 6') && !str_contains((string) $log[0]['after'], 'ZGT-Q') && str_contains((string) $log[0]['after'], '"filename": "catalog.csv"'), 'product.import logged with the counts and the filename, never a row');
ok(page($nora, '/catalog/import')['code'] === 200 && str_contains(page($nora, '/catalog/import')['body'], 'id="import-form"') && page($vera, '/catalog/import')['code'] === 403, 'the screen: step 1 for Nora, 403 for Vera');
unlink($csv);
finish();
