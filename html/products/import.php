<?php
declare(strict_types=1);
/**
 * Action `catalog_import` (log `product.import`: filename, rows, the counts, update_existing — never a row; confirm): catalog.write. Two POSTs to one
 * file from the browser — `preview=1` keeps the upload under storage/imports/<random>.csv and answers step 2 with its `upload` token; the run posts
 * `upload` + `mapping[field]` + `update_existing`. In JSON mode (an agent) one POST carries `file` (multipart) and `mapping` (JSON) and runs at once.
 * ≤ 10 MB, ≤ 10,000 rows. Location /catalog/import?result=<upload> (the result kept in the session for one render).
 */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_once APP_ROOT . '/app/sources/registry.php';           // the connectors' loader: the interface, the HTTP client, then feed.php and inv_feed_rows()
catalog_write_begin('catalog.write');
$pdo = db();
$me = (int) current_member_id();
$dir = APP_ROOT . '/storage/imports';
if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
    refuse(500, 'The import store is not writable.');
}
$keepUpload = static function (array $file) use ($dir): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        refuse(422, in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The file is too large (10 MB at most).' : 'The upload did not arrive.');
    }
    if ((int) $file['size'] > 10485760) { refuse(422, 'The file is too large (10 MB at most).'); }
    $token = bin2hex(random_bytes(16));
    $path = $dir . '/' . $token . '.csv';
    if (!(is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $path) : rename($file['tmp_name'], $path))) { refuse(500, 'The file could not be kept.'); }
    return $token;
};
$upload = preg_replace('/[^a-f0-9]/', '', (string) req_val('upload'));
$filename = '';
if (isset($_FILES['file'])) {
    $filename = basename((string) ($_FILES['file']['name'] ?? 'import.csv'));
    $upload = $keepUpload($_FILES['file']);
    $_SESSION['import_filenames'][$upload] = $filename;
} elseif ($upload === '' || !is_file($dir . '/' . $upload . '.csv')) {
    refuse(422, 'No file: upload a CSV first.');
} else {
    $filename = (string) ($_SESSION['import_filenames'][$upload] ?? 'import.csv');
}
$path = $dir . '/' . $upload . '.csv';
if (inv_yes('preview', false) && !wants_json()) {
    $parsed = inv_feed_rows((string) file_get_contents($path), ['format' => 'csv']);
    if ($parsed['columns'] === []) { @unlink($path); refuse(422, 'The file has no rows.'); }
    $_SESSION['import_preview'] = ['upload' => $upload, 'columns' => $parsed['columns'], 'rows' => array_slice($parsed['rows'], 0, 5), 'count' => count($parsed['rows'])];
    emit_action_status(true, ['did' => 'Read ' . count($parsed['rows']) . ' rows', 'refresh' => '']);
    saved_go('/catalog/import?upload=' . $upload);
}
$mapping = $_POST['mapping'] ?? null;
if (is_string($mapping)) { $mapping = json_decode($mapping, true); }
if (!is_array($mapping)) { refuse(422, 'The mapping is a JSON object of field → column index.'); }
$allowed = ['name', 'sku', 'brand', 'type', 'size', 'gtin', 'mpn', 'retail_price', 'map_price', 'cost_price', 'description', 'tags', 'ships_how'];
$clean = [];
foreach ($mapping as $k => $v) {
    if (!in_array((string) $k, $allowed, true)) { refuse(422, 'Unknown mapping field: ' . mb_substr((string) $k, 0, 30) . '.'); }
    if ($v === '' || $v === null) { continue; }
    if (is_string($v) && !ctype_digit($v) && preg_match('/^[A-Za-z]$/', $v)) { $v = ord(strtoupper($v)) - 65; }   // a column letter
    if (!is_numeric($v) || (int) $v < 0) { refuse(422, 'The mapping names columns by index (0, 1, 2…) or letter (A, B, C…).'); }
    $clean[(string) $k] = (int) $v;
}
if (!isset($clean['name']) || !isset($clean['sku'])) { refuse(422, 'Map the product name and the SKU at least.'); }
$updateExisting = inv_yes('update_existing', false);
$result = inv_guard($pdo, static fn (): array => import_catalog($pdo, $path, $clean, $updateExisting, $me, sees_cost()));
@unlink($path);
unset($_SESSION['import_preview'], $_SESSION['import_filenames'][$upload]);
catalog_log($pdo, 'product.import', 'catalog_import', 0, ['after' => ['filename' => $filename, 'rows' => $result['rows'], 'products_created' => $result['products_created'], 'variants_created' => $result['variants_created'],
    'variants_updated' => $result['variants_updated'], 'skipped' => count($result['skipped']), 'update_existing' => $updateExisting]]);
$_SESSION['import_result'][$upload] = $result;
inv_done('Imported ' . $result['variants_created'] . ' new and ' . $result['variants_updated'] . ' updated variants, ' . count($result['skipped']) . ' skipped', null, '/catalog/import?result=' . $upload, 'productChanged', $result);
