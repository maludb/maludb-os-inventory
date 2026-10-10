<?php
declare(strict_types=1);
/**
 * GET /purchasing/{id}/receive — screen `purchase-order-receive` (stock.receive): the open lines of a stock order and the receiving location; Draft the receipt.
 * POST — action `purchase_order_receive` (log `purchase_order.receive`: number, goods_receipt_id, lines, units; undo receipt_cancel): `location` (the order's ship-to by default); inv_receive_against() drafts a goods receipt with a line per open
 * line at the order's cost — a drop-ship is received when the customer's line is delivered (the SQL's sentence). The warehouse corrects quantities and posts it on slice 2's receipt screen. Location /receipts/{id}/edit; refresh purchaseOrderChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    purchasing_write_begin('stock.receive');
    $o = po_or_404($pdo, request_po_id());
    $errors = [];
    $loc = inv_ref($pdo, 'location', null, 'SELECT 1 FROM mcp_locations WHERE location_id = :id AND active', 'the location', $errors, true);
    if ($errors !== []) { inv_refuse_fields($errors); }
    $r = inv_guard($pdo, static function () use ($pdo, $o, $loc): array {
        $pdo->beginTransaction();
        $r = receive_against($pdo, (int) $o['purchase_order_id'], $loc, (int) current_member_id());
        po_log($pdo, 'purchase_order.receive', $o, ['number' => $o['number'], 'goods_receipt_id' => $r['goods_receipt_id'], 'receipt_number' => $r['number'], 'lines' => $r['lines'], 'units' => $r['units'], 'short' => []]);
        $pdo->commit();
        return $r;
    });
    inv_done('Drafted ' . $r['number'] . ' against ' . $o['number'], (int) $o['purchase_order_id'], inv_land('/receipts/' . $r['goods_receipt_id'] . '/edit', 'received'), 'purchaseOrderChanged',
        ['purchase_order_id' => (int) $o['purchase_order_id'], 'goods_receipt_id' => $r['goods_receipt_id'], 'lines' => $r['lines'], 'units' => $r['units']]);
}
require_right('stock.receive');
$o = po_or_404($pdo, request_po_id());
log_screen_view($pdo, 'purchase-order-receive');
$open = po_receivable_lines($pdo, (int) $o['purchase_order_id']);
$refusal = $o['kind'] !== 'stock' ? 'A drop-ship order is received when the customer\'s line is delivered.' : (!in_array($o['status'], PO_SENT_STATUSES, true) ? $o['number'] . ' is ' . str_replace('_', ' ', $o['status']) . ' — a sent order is received.' : ($open === [] ? 'Nothing is open on ' . $o['number'] . '.' : null));
if (wants_json()) { respond_screen(['purchase_order' => present_po_row($o), 'open_lines' => array_map('present_po_line', $open), 'refusal' => $refusal, 'locations' => po_locations($pdo), 'default_location' => $o['location_id']]); }
render_screen('Receive ' . $o['number'], view('purchasing/receive.php', ['o' => $o, 'open' => $open, 'refusal' => $refusal, 'locations' => po_locations($pdo), 'seesCost' => sees_receipt_cost(), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, PO_NOTICES)]), ['activeNav' => 'purchase-order-list', 'screen' => 'purchase-order-receive', 'entity' => 'purchase_order', 'recordId' => (string) $o['purchase_order_id']]);
