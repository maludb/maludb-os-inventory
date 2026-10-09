<?php
declare(strict_types=1);
/**
 * Action `count_line_set` (log `stock.count_line`: sku, system_qty, counted_qty, created): an open count's counted quantity — `variant` (or its `barcode`
 * as scanned) and `counted_qty` ≥ 0. A scan that carries no quantity adds one to the running count (the server keeps it, so two fast scans never race);
 * a variant with no line gets one with the system quantity = the balance now. A Pattern C request answers the row. stock.count.
 */
require_once dirname(dirname(__DIR__), 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.count');
$pdo = db();
$c = count_or_404($pdo, request_integer('count') ?? request_integer('count_id'));
require_draft($c, 'count', 'open');
$cid = (int) $c['count_id'];
$errors = [];
$scanned = false;
$variantId = stock_variant_from_request($pdo, $errors, $scanned, 'count');
$counted = null;
$running = false;
if (req_has('counted_qty') && (string) req_val('counted_qty') !== '') {
    $counted = inv_int('counted_qty', null, 0, 1000000, 'The counted quantity', $errors);
} elseif ($scanned) {
    $running = true;
} else {
    $errors['counted_qty'] = 'Give the counted quantity (0 or more).';
}
if ($errors !== []) { inv_refuse_fields($errors); }
$sku = (string) one_value($pdo, 'SELECT sku FROM mcp_product_variants WHERE variant_id = :id', ['id' => $variantId]);
[$lineId, $created, $line] = inv_guard($pdo, static function () use ($pdo, $c, $cid, $variantId, $counted, $running, $sku): array {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT 1 FROM inventory_counts WHERE id = :id FOR UPDATE')->execute(['id' => $cid]);     // serialize the running count
    $prior = find_count_line($pdo, $cid, $variantId);
    $q = $running ? (int) ($prior['counted_qty'] ?? 0) + 1 : $counted;
    $created = false;
    $lineId = set_count_line($pdo, $cid, $variantId, $q, stock_me(), $created);
    $line = find_count_line($pdo, $cid, $variantId);
    stock_log($pdo, 'stock.count_line', 'inventory_count', $cid, (int) $c['location_id'], ['number' => $c['number'], 'line_id' => $lineId, 'sku' => $sku, 'system_qty' => (int) $line['system_qty'],
        'counted_qty' => $q, 'before_counted_qty' => $prior['counted_qty'] ?? null, 'created' => $created, 'scanned' => $running]);
    $pdo->commit();
    return [$lineId, $created, $line];
});
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'count-line-row-' . $lineId) {
    emit_action_status(true, ['did' => 'Counted ' . $sku, 'record_id' => $lineId]);
    hx_trigger('stockChanged');
    foreach (count_lines($pdo, $cid) as $l) {
        if ((int) $l['count_line_id'] === $lineId) { echo view('counts/partials/count-line-row.php', ['l' => $l, 'c' => $c, 'here' => '/counts/' . $cid]); }
    }
    exit;
}
inv_done('Counted ' . (int) $line['counted_qty'] . ' × ' . $sku, $lineId, inv_land('/counts/' . $cid, 'counted', $scanned ? 'count-scan-form' : 'count-line-row-' . $lineId), 'stockChanged',
    ['count_id' => $cid, 'line_id' => $lineId, 'counted_qty' => (int) $line['counted_qty'], 'system_qty' => (int) $line['system_qty'], 'created' => $created]);
