<?php
declare(strict_types=1);
/** Action `identifier_remove` (log `variant.identifier_remove`: kind, value, source_id; confirm): catalog.write. Location /variants/{variant}/identifiers. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
catalog_write_begin('catalog.write');
$pdo = db();
$id = request_integer('identifier');
$st = $pdo->prepare('SELECT identifier_id, variant_id FROM mcp_variant_identifiers WHERE identifier_id = :id');
$st->execute(['id' => $id ?? 0]);
$row = $st->fetch();
if ($row === false) { refuse(404, 'Identifier not found.'); }
$v = catalog_variant_or_404($pdo, (int) $row['variant_id']);
$removed = inv_guard($pdo, static function () use ($pdo, $id, $v): array {
    $pdo->beginTransaction();
    $r = remove_identifier($pdo, (int) $id);
    catalog_log($pdo, 'variant.identifier_remove', 'product_variant', $v['variant_id'], ['before' => ['kind' => $r['kind'], 'value' => $r['value'], 'source_id' => $r['source_id']], 'after' => ['product_id' => $v['product_id'], 'identifier_id' => (int) $id]]);
    $pdo->commit();
    return $r;
});
inv_done('Removed the ' . (IDENTIFIER_KINDS[$removed['kind']] ?? $removed['kind']), (int) $id, inv_land('/variants/' . $v['variant_id'] . '/identifiers', 'removed'), 'productChanged', ['variant_id' => $v['variant_id']]);
