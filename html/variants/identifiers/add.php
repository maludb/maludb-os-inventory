<?php
declare(strict_types=1);
/** Action `identifier_add` (log `variant.identifier_add`: kind, value, source_id, product_id): catalog.write; the trigger normalizes a GTIN and refuses a supplier SKU without a source. Location /variants/{variant}/identifiers#identifier-row-{id}. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
catalog_write_begin('catalog.write');
$pdo = db();
$me = (int) current_member_id();
$v = catalog_variant_or_404($pdo, request_integer('variant'));
$errors = [];
$kind = (string) req_val('kind');
if (!isset(IDENTIFIER_KINDS[$kind])) { $errors['kind'] = 'The kind is one of ' . implode(', ', array_keys(IDENTIFIER_KINDS)) . '.'; }
$value = trim((string) req_val('value'));
if ($value === '' || mb_strlen($value) > 120) { $errors['value'] = 'A value of up to 120 characters is required.'; }
$sourceId = inv_ref($pdo, 'source', null, "SELECT 1 FROM mcp_sources WHERE source_id = :id AND role = 'supplier'", 'the supplier source', $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$id = inv_guard($pdo, static function () use ($pdo, $v, $kind, $value, $sourceId, $me): int {
    try {
        $pdo->beginTransaction();
        $id = add_identifier($pdo, $v['variant_id'], $kind, $value, $sourceId, $me);
        $st = $pdo->prepare('SELECT value FROM variant_identifiers WHERE id = :id');
        $st->execute(['id' => $id]);
        catalog_log($pdo, 'variant.identifier_add', 'product_variant', $v['variant_id'], ['after' => ['product_id' => $v['product_id'], 'identifier_id' => $id, 'kind' => $kind, 'value' => (string) $st->fetchColumn(), 'source_id' => $sourceId]]);
        $pdo->commit();
        return $id;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23505') { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw new DomainException('That ' . IDENTIFIER_KINDS[$kind] . ' is already on a variant.'); }
        throw $e;
    }
});
inv_done('Added the ' . IDENTIFIER_KINDS[$kind], $id, inv_land('/variants/' . $v['variant_id'] . '/identifiers', 'added', 'identifier-row-' . $id), 'productChanged', ['identifier_id' => $id, 'variant_id' => $v['variant_id']]);
