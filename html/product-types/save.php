<?php
declare(strict_types=1);
/** Action `product_type_save` (log `product_type.save`): catalog.write; `product_type` to change one; the key from the name when absent. A Pattern C request (hx-target="closest tr") answers the row partial. Location /product-types/#product-type-row-{id}. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
catalog_write_begin('catalog.write');
$pdo = db();
$id = request_integer('product_type') ?? request_integer('product_type_id');
$cur = null;
if ($id !== null) {
    foreach (product_types($pdo, false) as $t) { if ($t['product_type_id'] === $id) { $cur = $t; } }
    if ($cur === null) { refuse(404, 'Product type not found.'); }
}
$errors = [];
$f = product_type_from_request($cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$newId = inv_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    try {
        $pdo->beginTransaction();
        $newId = save_product_type($pdo, $id, $f);
        if ($cur === null) {
            catalog_log($pdo, 'product_type.save', 'product_type', $newId, ['after' => $f]);
        } else {
            $d = inv_diff(['key' => $cur['key'], 'name' => $cur['name'], 'sort_order' => $cur['sort_order'], 'active' => $cur['active']], $f);
            if ($d['after'] !== []) { catalog_log($pdo, 'product_type.save', 'product_type', $newId, ['before' => $d['before'], 'after' => $d['after']]); }
        }
        $pdo->commit();
        return $newId;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23505') { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw new DomainException('That key is already taken.'); }
        throw $e;
    }
});
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'product-type-row-' . $newId) {
    emit_action_status(true, ['did' => 'Saved ' . $f['name'], 'record_id' => $newId, 'refresh' => 'settingsChanged']);
    hx_trigger('settingsChanged');
    $row = null;
    foreach (product_types($pdo, false) as $t) { if ($t['product_type_id'] === $newId) { $row = $t; } }
    echo view('catalog/partials/product-type-row.php', ['t' => $row, 'mayWrite' => true]);
    exit;
}
inv_done(($id === null ? 'Added the type ' : 'Saved ') . $f['name'], $newId, inv_land('/product-types/', $id === null ? 'created' : 'saved', 'product-type-row-' . $newId), 'settingsChanged', ['product_type_id' => $newId, 'key' => $f['key']]);
