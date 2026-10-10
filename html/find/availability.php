<?php
declare(strict_types=1);
/**
 * GET /find/availability?variant=&compact=&qty=&field= — the availability partial (find.md): the full shape (state, own stock, offers ranked,
 * references; a bundle's components) for a Find card and the variant page; the compact shape (the order form's picker per line: radios valued
 * stock:{location} / pickup:{location} / dropship:{listing_variant} / backorder, named by the field prefix, the promise line beneath; `choose` keeps a value checked when it is offered — slice 5). A fragment.
 */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/find/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/find/present.php';
require_right('inventory.read');
$pdo = db();
$vid = request_integer('variant') ?? 0;
require_visible($pdo, 'mcp_product_variants', 'variant_id', $vid, 'Variant');
$a = variant_availability($pdo, $vid) ?: refuse(404, 'Variant not found.');
$compact = ($_GET['compact'] ?? '') === '1';
$qty = max(1, min(999, request_integer('qty') ?? 1));
if (wants_json()) { respond_screen(['availability' => $a]); }
if (!$compact) {
    echo view('find/partials/availability.php', ['a' => $a, 'vid' => $vid, 'seesCost' => sees_cost(), 'tz' => member_timezone()]);
    return;
}
$field = (string) ($_GET['field'] ?? 'line');
if (!preg_match('/^[a-z_][a-z0-9_]*(\[[a-z0-9_]*\])*$/i', $field)) { refuse(422, 'That field name is not one a form uses.'); }
$choose = trim((string) ($_GET['choose'] ?? ''));
echo compact_picker_html($pdo, $vid, $qty, $field, $choose === '' ? null : $choose, request_integer('store'));
