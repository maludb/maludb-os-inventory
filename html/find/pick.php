<?php
declare(strict_types=1);
/** GET /find/pick?q=&size=&limit= — a variant pick list for a form's line (find.md): inv_find(q, size, {}, ≤ 20); under 2 characters the empty list and its hint. A fragment. */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/find/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/find/present.php';
require_right('inventory.read');
$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) > 120) { refuse(422, 'Keep it under 120 characters.'); }
$size = trim((string) ($_GET['size'] ?? '')) ?: null;
$rows = mb_strlen($q) < 2 ? [] : find_variants($pdo, $q, $size, [], max(1, min(20, request_integer('limit') ?? 20)));
if (wants_json()) {
    respond_screen(['results' => array_map(static fn ($r) => ['variant_id' => $r['variant_id'], 'sku' => $r['sku'], 'label' => $r['product_name'] . ($r['size_name'] ? ', ' . $r['size_name'] : ''),
        'retail_price' => $r['retail_price'], 'state' => $r['state']], $rows)]);
}
echo view('find/partials/pick.php', ['rows' => $rows, 'short' => mb_strlen($q) < 2]);
