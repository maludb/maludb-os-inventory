<?php
declare(strict_types=1);
/** /suppliers/{id}?tab= — one supplier (screen `supplier-view`): the facts (the account number for purchasing.write); tabs Items (the price sheet), Open orders, Lead times, Sources, Notes, Attachments, Trail. */
require_once dirname(__DIR__, 2) . '/app/features/suppliers/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$s = supplier_or_404($pdo, request_integer('id') ?? request_integer('supplier'));
$sid = $s['supplier_id'];
$tabs = ['items' => 'Items', 'open' => 'Open orders', 'lead' => 'Lead times', 'sources' => 'Sources', 'notes' => 'Notes', 'attachments' => 'Attachments', 'trail' => 'Trail'];
$tab = request_string('tab', 'items');
if (!isset($tabs[$tab])) { $tab = 'items'; }
$d = ['items' => [], 'open' => [], 'lead' => null, 'shipped' => [], 'sources' => [], 'notes' => [], 'attachments' => [], 'trail' => []];
if ($tab === 'items' || wants_json()) { $d['items'] = supplier_items_for($pdo, $sid); }
if ($tab === 'open' || wants_json()) { $d['open'] = supplier_open_orders($pdo, $sid); }
if ($tab === 'lead' || wants_json()) { $d['lead'] = supplier_lead_times($pdo, $sid); $d['shipped'] = supplier_shipped_lines($pdo, $sid); }
if ($tab === 'sources' || wants_json()) { $d['sources'] = supplier_sources_of($pdo, $sid); }
if ($tab === 'notes' || wants_json()) { $d['notes'] = record_notes($pdo, 'supplier', $sid); }
if ($tab === 'attachments' || wants_json()) { $d['attachments'] = record_attachments($pdo, 'supplier', $sid); }
if ($tab === 'trail') { $d['trail'] = find_record_activity($pdo, 'supplier', $sid, 50); }
log_activity($pdo, 'screen.view', 'supplier', $sid, ['screen' => 'supplier-view', 'after' => ['tab' => $tab, 'name' => $s['name']]]);
$may = ['write' => has_right('suppliers.write'), 'po' => has_right('purchasing.write'), 'reports' => has_right('reports.read'), 'sheets' => has_right('suppliers.write')];
if (wants_json()) {
    respond_screen(['supplier' => present_supplier($s), 'items' => array_map(static fn (array $i): array => ['sku' => $i['sku'], 'supplier_sku' => $i['supplier_sku'], 'cost' => $i['cost'], 'cost_withheld' => (bool) $i['cost_withheld'],
            'lead_time_days' => $i['lead_time_days'], 'moq' => (int) $i['moq'], 'active' => (bool) $i['active'], 'last_seen_at' => json_ts($i['last_seen_at'])], $d['items']),
        'open_orders' => array_map(static fn (array $o): array => ['purchase_order_id' => (int) $o['purchase_order_id'], 'number' => $o['number'], 'kind' => $o['kind'], 'status' => $o['status'], 'expected_on' => $o['expected_on'],
            'total' => $o['total'], 'awaiting_ack' => (bool) $o['awaiting_ack'], 'overdue' => (bool) $o['overdue']], $d['open']),
        'lead_times' => $d['lead'], 'shipped_lines' => $d['shipped'], 'sources' => array_map(static fn (array $r): array => ['source_id' => (int) $r['source_id'], 'name' => $r['name'], 'connector' => $r['connector'], 'active' => (bool) $r['active']], $d['sources']),
        'notes' => $d['notes'], 'attachments' => $d['attachments'], 'may' => $may]);
}
render_screen($s['name'], view('suppliers/view.php', ['s' => $s, 'tab' => $tab, 'tabs' => $tabs, 'tabdata' => $d, 'may' => $may, 'tz' => member_timezone(), 'here' => here_url(), 'seesCost' => sees_cost(),
    'notice' => inv_notice($_GET['notice'] ?? null, SUPPLIER_NOTICES)]), ['activeNav' => 'supplier-list', 'screen' => 'supplier-view', 'entity' => 'supplier', 'recordId' => (string) $sid]);
