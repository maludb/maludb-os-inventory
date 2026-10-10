<?php
declare(strict_types=1);
/**
 * GET /find/availability?variant=&compact=&qty=&field= — the availability partial (find.md): the full shape (state, own stock, offers ranked,
 * references; a bundle's components) for a Find card and the variant page; the compact shape (the order form's picker per line: radios valued
 * stock:{location} / pickup:{location} / dropship:{listing_variant} / backorder, named by the field prefix, the promise line beneath). A fragment.
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
$locations = sellable_locations($pdo);
$choices = isset($a['components']) ? [] : picker_choices($a, $qty, $locations);
echo view('find/partials/availability-compact.php', ['a' => $a, 'vid' => $vid, 'qty' => $qty, 'field' => $field, 'slug' => field_slug($field), 'choices' => $choices,
    'checked' => isset($a['components']) ? '' : recommended_value(recommended_fulfilment($a, $qty), $choices), 'locations' => $locations,
    'store' => request_integer('store'), 'atp' => isset($a['components']) ? null : variant_atp($pdo, $vid, $qty), 'seesCost' => sees_cost()]);
