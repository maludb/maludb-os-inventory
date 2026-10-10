<?php
declare(strict_types=1);
/** GET /find/atp?variant=&qty=&by= — the promise line (find.md): inv_atp(variant, qty, by); qty 1–999, by a date or empty. A qty under 1 is the function's sentence (422). A fragment. */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/find/queries.php';
require_right('inventory.read');
$pdo = db();
$vid = request_integer('variant') ?? 0;
require_visible($pdo, 'mcp_product_variants', 'variant_id', $vid, 'Variant');
$qty = request_integer('qty') ?? 1;
if ($qty > 999) { refuse(422, 'A promise is for at most 999.'); }
$by = request_date('by');
if ($by === false) { refuse(422, 'That is not a date.'); }
$atp = inv_guard($pdo, static fn () => variant_atp($pdo, $vid, $qty, $by ?: null));
if (wants_json()) { respond_screen(['atp' => $atp]); }
echo view('find/partials/atp.php', ['atp' => $atp, 'vid' => $vid, 'seesCost' => sees_cost()]);
