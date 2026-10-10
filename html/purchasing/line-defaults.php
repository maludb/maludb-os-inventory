<?php
declare(strict_types=1);
/**
 * GET /purchasing/line-defaults?supplier=&variant=&n= — what a purchase-order line starts from (a fragment, JSON to a caller that asks): the supplier's SKU, cost and MOQ from the price sheet, the cost by default (the sheet's,
 * else the in-stock offer's, else the variant's, else 0) and a select of this supplier's offers for the variant. purchasing.write.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_right('purchasing.write');
$pdo = db();
$sup = request_integer('supplier');
$var = request_integer('variant');
if ($sup === null || $var === null) { refuse(422, 'Name a supplier and a variant.'); }
$d = po_line_defaults($pdo, $sup, $var);
$d['cost_visible'] = sees_cost();
if (wants_json()) { respond_screen(['defaults' => $d]); }
echo view('purchasing/partials/line-defaults.php', ['d' => $d, 'n' => preg_replace('/[^a-z0-9_]/i', '', (string) ($_GET['n'] ?? '0')), 'field' => (string) ($_GET['field'] ?? '')]);
