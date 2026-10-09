<?php
declare(strict_types=1);
/** Action `brand_save` (log `brand.save`: name, website, supplier_id, active; inv_diff() on a change): catalog.write; `brand` to change one. A duplicate name (23505) reads "That name is already taken." Location /brands/#brand-row-{id}; refresh brandChanged. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
catalog_write_begin('catalog.write');
$pdo = db();
$id = request_integer('brand') ?? request_integer('brand_id');
$cur = null;
if ($id !== null) { $cur = find_brand($pdo, $id) ?? refuse(404, 'Brand not found.'); }
$errors = [];
$f = brand_from_request($pdo, $cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$loggable = static fn (array $b): array => ['name' => $b['name'], 'website' => $b['website'], 'supplier_id' => $b['supplier_id'] === null ? null : (int) $b['supplier_id'], 'active' => (bool) $b['active']];
$newId = inv_guard($pdo, static function () use ($pdo, $id, $cur, $f, $loggable): int {
    $pdo->beginTransaction();
    $newId = save_brand($pdo, $id, $f);
    $after = $loggable(find_brand($pdo, $newId));
    if ($cur === null) {
        catalog_log($pdo, 'brand.save', 'brand', $newId, ['after' => $after]);
    } else {
        $d = inv_diff($loggable($cur), $after);
        if ($d['after'] !== []) { catalog_log($pdo, 'brand.save', 'brand', $newId, ['before' => $d['before'], 'after' => $d['after']]); }
    }
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Made the brand ' : 'Saved ') . $f['name'], $newId, inv_land(return_path('/brands/'), $id === null ? 'created' : 'saved', 'brand-row-' . $newId), 'brandChanged', ['brand_id' => $newId]);
