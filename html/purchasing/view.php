<?php
declare(strict_types=1);
/** /purchasing/{id}?tab= — one purchase order (screen `purchase-order-view`): the header, the lines with their state, the supplier's events, the receipts, the supplier's link, the ship-to, notes and attachments, the trail. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$o = po_or_404($pdo, request_po_id());
$poid = (int) $o['purchase_order_id'];
$tab = request_string('tab', 'po');
$tabs = ['po' => 'Purchase order', 'notes' => 'Notes', 'attachments' => 'Attachments', 'trail' => 'Trail'];
if (!isset($tabs[$tab])) { $tab = 'po'; }
$extra = ['notes' => [], 'attachments' => [], 'trail' => []];
if ($tab === 'notes' || wants_json()) { $extra['notes'] = record_notes($pdo, 'purchase_order', $poid); }
if ($tab === 'attachments' || wants_json()) { $extra['attachments'] = record_attachments($pdo, 'purchase_order', $poid); }
if ($tab === 'trail') { $extra['trail'] = find_activity_by_key($pdo, 'purchase_order_id', $poid, 100); }
log_activity($pdo, 'screen.view', 'purchase_order', $poid, ['purchase_order_id' => $poid, 'sales_order_id' => $o['sales_order_id'], 'location_id' => $o['kind'] === 'stock' ? $o['location_id'] : null, 'screen' => 'purchase-order-view',
    'after' => ['number' => $o['number'], 'tab' => $tab]]);
$may = ['write' => has_right('purchasing.write'), 'receive' => has_right('stock.receive'), 'cost' => sees_cost(), 'reports' => has_right('reports.read')];
if (wants_json()) {
    respond_screen(['purchase_order' => present_purchase_order($o), 'notes' => $extra['notes'], 'attachments' => $extra['attachments'], 'may' => $may]);
}
render_screen($o['number'], view('purchasing/view.php', ['o' => $o, 'tab' => $tab, 'tabs' => $tabs, 'extra' => $extra, 'may' => $may, 'tz' => member_timezone(), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, PO_NOTICES)]), ['activeNav' => 'purchase-order-list', 'screen' => 'purchase-order-view', 'entity' => 'purchase_order', 'recordId' => (string) $poid]);
