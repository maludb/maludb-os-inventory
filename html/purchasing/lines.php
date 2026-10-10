<?php
declare(strict_types=1);
/** GET /purchasing/{id}/lines — the lines editor region re-rendered (a fragment, not a screen): `#po-lines` and the totals panel out of band. The edit form refreshes itself from it on purchaseOrderChanged. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_right('inventory.read');
$pdo = db();
$o = po_or_404($pdo, request_po_id());
if (wants_json()) { respond_screen(['lines' => array_map('present_po_line', $o['lines']), 'subtotal' => $o['subtotal'], 'shipping_cost' => $o['shipping_cost'], 'total' => $o['total'], 'cost_withheld' => $o['cost_withheld']]); }
echo po_lines_fragment($pdo, (int) $o['purchase_order_id']);
