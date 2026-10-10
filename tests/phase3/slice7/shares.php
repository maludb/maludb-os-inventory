<?php
/** The five shares' readers (feed.md "Proof" ≈ 20): db/016's functions called from PHP — each document's schema and top-level keys against the tool surface's shapes, what each leaves out, the 92-day and limit refusals, the declared shares. */
require __DIR__ . '/lib.php';
$w = feed_world();
$owner = $w['owner'];
$sam = $w['sam'];
$wes = $w['wes'];
$nora = $w['nora'];
$run = substr(md5((string) microtime(true)), 0, 6);
require_once dirname(__DIR__, 3) . '/app/db.php';
require_once dirname(__DIR__, 3) . '/app/features/shares/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/connections/queries.php';
$pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);

echo "The world: two orders closed, a receipt posted, a drop-ship delivered\n";
$email = "maria-$run@example.invalid";
act($nora, '/customers/save.php', ['name' => "SMOKE Maria $run", 'email' => $email, 'phone' => '312-555-0177', 'billing_address' => '9 Secret Lane', 'shipping_address' => '9 Secret Lane', 'source' => 'phone']);
$maria = customer_id_of("SMOKE Maria $run");
// closed order A: a stock Queen, picked up, paid, closed
[, $b] = make_quote($sam, $maria, [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => "S7-A-$run", 'delivery_method' => 'pickup']);
$A = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $A]);
$aline = order_lines_of($A)[0];
act($wes, '/orders/ship.php', ['order' => $A, 'kind' => 'pickup', 'lines' => json_encode([['line_id' => $aline['id'], 'qty' => 1]])]);
$total = (float) order_row($A)['total'];
act($sam, '/orders/payments/save.php', ['order' => $A, 'kind' => 'deposit', 'amount' => '100', 'method' => 'card', 'reference' => '1111']);
act($sam, '/orders/payments/save.php', ['order' => $A, 'kind' => 'balance', 'amount' => number_format($total - 100, 2, '.', ''), 'method' => 'cash']);
[$c, $b] = act($sam, '/orders/close.php', ['order' => $A]);
ok($c === 200 && order_row($A)['status'] === 'closed', 'order A (a Queen picked up, paid in two payments) is closed');
// closed order B: a King drop-shipped by Zinus, delivered, paid, closed
[, $b] = make_quote($sam, $maria, [['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]], ['customer_reference' => "S7-B-$run", 'delivery_method' => 'parcel']);
$B = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $B]);
$po = po_for_order($B);
act($nora, '/purchasing/send.php', ['purchase_order' => $po, 'via' => 'phone']);
$pol = (int) one('SELECT id FROM purchase_order_lines WHERE purchase_order_id = :p ORDER BY line_no LIMIT 1', ['p' => $po]);
[$c, $b] = act($nora, '/purchasing/tracking.php', ['line' => $pol, 'carrier' => 'UPS', 'tracking' => '1Z999AA10123456784']);
$ship = (int) ($b['shipment_id'] ?? 0);
[$c2, $b2] = act($wes, '/orders/deliver.php', ['shipment' => $ship]);
$totalB = (float) order_row($B)['total'];
act($sam, '/orders/payments/save.php', ['order' => $B, 'kind' => 'balance', 'amount' => number_format($totalB, 2, '.', ''), 'method' => 'check', 'reference' => '2222']);
[$c3, $b3] = act($sam, '/orders/close.php', ['order' => $B]);
ok($c === 200 && $ship > 0 && $c2 === 200 && $c3 === 200 && order_row($B)['status'] === 'closed', 'order B (a King drop-shipped by Zinus, delivered, paid, closed) is closed: ' . ($b3['error']['message'] ?? $b2['error']['message'] ?? ''));
// a goods receipt POSTED: 2 Twins from Zinus
[, $b] = act($nora, '/purchasing/save.php', ['supplier' => $w['zinus'], 'lines' => json_encode([['variant' => $w['twin'], 'qty' => 2, 'unit_cost' => '300']]), 'notes' => "S7-RCV-$run"]);
$po2 = (int) $b['record_id'];
act($nora, '/purchasing/send.php', ['purchase_order' => $po2, 'via' => 'phone']);
[, $b] = act($wes, '/purchasing/receive.php', ['purchase_order' => $po2]);
$grn = (int) ($b['goods_receipt_id'] ?? 0);
[$c, $b] = act($wes, '/receipts/post.php', ['receipt' => $grn]);
ok($grn > 0 && $c === 200, 'a goods receipt of 2 Twins from Zinus is posted: ' . ($b['error']['message'] ?? ''));
// an OPEN order: a King drop-shipped by Zinus, confirmed and sent, not yet shipped
[, $b] = make_quote($sam, $maria, [['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]], ['customer_reference' => "S7-C-$run", 'delivery_method' => 'parcel']);
$C = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $C]);
act($nora, '/purchasing/send.php', ['purchase_order' => po_for_order($C), 'via' => 'phone']);
$from = date('Y-m-d', strtotime('-1 day'));
$to = date('Y-m-d', strtotime('+1 day'));
$noPeople = static function (string $json) use ($email): bool { foreach ([$email, '312-555-0177', '9 Secret Lane', 'salesperson', 'SMOKE Sam', 'SMOKE Nora'] as $needle) { if (stripos($json, $needle) !== false) { return false; } } return true; };

echo "1. os.inventory-sales/1\n";
$d = share_sales_closed($pdo, $from, $to);
$ordersByNo = array_column($d['orders'], null, 'number');
ok($d['schema'] === 'os.inventory-sales/1' && $d['application'] === 'inventory' && $d['currency'] === 'USD' && $d['count'] === (int) one("SELECT count(*) FROM sales_orders WHERE status = 'closed'") && $d['count'] >= 2 && count($d['orders']) === $d['count'] && $d['truncated'] === false, 'schema, application, currency, count, the orders — all of them closed in the period');
ok(array_keys($d) === array_keys($d) && !array_diff(['schema', 'generated_at', 'application', 'currency', 'period', 'count', 'offset', 'limit', 'truncated', 'orders', 'totals'], array_keys($d)), 'the top-level keys are the tool surface\'s: schema, generated_at, application, currency, period, count, offset, limit, truncated, orders, totals');
$oa = $ordersByNo[order_row($A)['number']] ?? [];
ok(!array_diff(['sales_order_id', 'number', 'ordered_on', 'closed_at', 'customer', 'location', 'delivery_method', 'lines', 'subtotal', 'discount_total', 'tax', 'shipping_charge', 'total', 'payments', 'payments_by_method', 'refunds', 'amount_paid', 'balance_due', 'cogs', 'returns'], array_keys($oa)), 'an order carries its lines, tax, payments, payments_by_method, refunds, returns and cogs');
ok(abs($oa['cogs'] - (float) one('SELECT cost_price FROM product_variants WHERE id = :v', ['v' => $w['queen']])) < 0.001 && $oa['payments_by_method'] == ['card' => 100, 'cash' => 1001.4] && abs($oa['total'] - (float) order_row($A)['total']) < 0.001 && $oa['lines'][0]['sku'] === 'SMOKE-NW-CR-Q' && $oa['tax']['name'] === 'Cook County', 'order A: cogs unnulled (the Queen\'s cost, 499.50), payments by method, total and tax as the order has them');
ok($noPeople(json_encode($d)) && array_keys($oa['customer']) === ['name', 'tax_id', 'ledger_ref', 'customer_id', 'income_account_id'], 'no salesperson, no e-mail, no phone, no address anywhere in the document; the customer is a name and the ledger\'s ids');
$sum = 0.0;
foreach ($d['orders'] as $o) { $sum += $o['total']; }
ok(abs($d['totals']['total'] - $sum) < 0.001 && abs($d['totals']['cogs'] - array_sum(array_column($d['orders'], 'cogs'))) < 0.001 && $d['totals']['payments_by_method']['check'] > 0, 'the totals tie to the orders (total, cogs, payments by method)');
$p1 = share_sales_closed($pdo, $from, $to, 0, 1);
$p2 = share_sales_closed($pdo, $from, $to, 1, 1);
ok($p1['count'] === $d['count'] && count($p1['orders']) === 1 && $p1['truncated'] === true && count($p2['orders']) === 1 && $p2['orders'][0]['number'] !== $p1['orders'][0]['number'] && share_sales_closed($pdo, $from, $to, 0, 501)['limit'] === 500, 'paged over the orders (limit 1: truncated, the next offset the next order); a limit over 500 is held at 500');
$threw = static function (callable $f): ?string { try { $f(); return null; } catch (PDOException $e) { return (string) $e->getCode() . ':' . db_raise_text($e); } };
ok($threw(fn () => share_sales_closed($pdo, '2026-01-01', '2026-04-05')) === '23514:A period is at most 92 days' && $threw(fn () => share_sales_closed($pdo, $to, $from)) === '23514:The period ends before it starts', 'a period over 92 days, or one that ends before it starts: the function\'s check_violation (the caller\'s 422 through inv_guard)');

echo "2. os.inventory-purchases/1\n";
$d = share_purchases_received($pdo, $from, $to);
$zin = array_values(array_filter($d['suppliers'], static fn (array $s): bool => $s['name'] === 'SMOKE Zinus'))[0] ?? [];
ok($d['schema'] === 'os.inventory-purchases/1' && !array_diff(['schema', 'generated_at', 'application', 'currency', 'period', 'count', 'offset', 'limit', 'suppliers', 'totals'], array_keys($d)) && $d['count'] >= 2, 'schema and the top-level keys; at least the receipt and the drop-ship');
ok(count($zin['receipts'] ?? []) >= 1 && count($zin['dropships'] ?? []) >= 1 && $zin['receipts'][0]['number'] === (string) one('SELECT number FROM goods_receipts WHERE id = :g', ['g' => $grn]) && $zin['receipts'][0]['lines'][0]['unit_cost'] == 300 && $zin['receipts'][0]['lines'][0]['qty'] === 2, 'Zinus: the posted receipt (2 Twins at 300.00) under its supplier');
$ds = $zin['dropships'][0] ?? [];
ok(($ds['sales_order_number'] ?? '') === order_row($B)['number'] && $ds['lines'][0]['line_cost'] == 649 && $d['totals']['dropships'] >= 649 && !array_key_exists('ship_to', $ds) && $noPeople(json_encode($d)) && stripos(json_encode($d), 'ship_to') === false, 'the delivered drop-ship carries its sales order\'s number and cost, and no ship-to');
ok($d['totals']['receipts'] == 600 && abs($d['totals']['total'] - ($d['totals']['receipts'] + $d['totals']['dropships'])) < 0.001, 'the totals: receipts 600, drop-ships, and their sum');

echo "3. os.inventory-valuation/1\n";
$d = share_stock_valuation($pdo, date('Y-m-d'));
$sqlValue = (float) one('SELECT COALESCE(sum(b.qty_on_hand * COALESCE(v.cost_price, 0)), 0) FROM inventory_balances b JOIN product_variants v ON v.id = b.variant_id');
$sqlUnits = (int) one('SELECT COALESCE(sum(b.qty_on_hand), 0) FROM inventory_balances b');
ok($d['schema'] === 'os.inventory-valuation/1' && $d['by'] === 'location' && abs($d['totals']['value'] - $sqlValue) < 0.01 && $d['totals']['units'] === $sqlUnits && count($d['rows']) >= 3 && !array_diff(['group_id', 'group_name', 'units', 'value', 'variants'], array_keys($d['rows'][0])), 'by location: units and value by location, tying to the balances at cost (' . $sqlUnits . ' units, ' . number_format($sqlValue, 2) . ')');
$db = share_stock_valuation($pdo, date('Y-m-d'), 'brand');
ok($db['by'] === 'brand' && abs($db['totals']['value'] - $sqlValue) < 0.01 && $db['rows'][0]['group_name'] === 'SMOKE Cloudrest' && $threw(fn () => share_stock_valuation($pdo, date('Y-m-d'), 'colour')) === '23514:Group by location or brand', 'by brand: the same total under the brand; any other grouping is the function\'s refusal');

echo "4. os.inventory-availability/1\n";
if ((int) psql_exec('SELECT sets_available FROM inv_variant_supply(' . $w['set_q'] . ')') === 0) { psql_exec("SELECT inv_post_txn('receipt', {$w['fnd_queen']}, {$w['wh']}, 2, 120.00, 'opening', 7, 'smoke-s7-fnd-shares-$run', 'none', NULL, NULL, NULL, 1)"); }
$d = share_availability_index($pdo, ['q' => 'zzqxv']);
ok($d['schema'] === 'os.inventory-availability/1' && $d['count'] === 0, 'a query nothing matches: count 0');
$d = share_availability_index($pdo, ['q' => 'cloudrest', 'size' => 'queen']);
$rows = array_column($d['rows'], null, 'sku');
$low = strtolower(json_encode($d));
ok($d['schema'] === 'os.inventory-availability/1' && $d['count'] === 3 && $rows['SMOKE-NW-CR-Q']['availability'] === 'in_stock' && !array_key_exists('quantity', $rows['SMOKE-NW-CR-Q']) && !str_contains($low, 'partner_price') && !str_contains($low, 'cost') && !str_contains($low, 'source') && !str_contains($low, 'zinus'), 'the three Queens: availability and lead time, no quantity, no partner price, no cost, no source');
ok($rows['SMOKE-SET-Q']['availability'] === 'in_stock' && $rows['SMOKE-SET-Q']['lead_time_days'] === 0, 'the Queen set is in stock — its state is its components\' (db/021)');
$d = share_availability_index($pdo, ['gtin' => ltrim($w['queen_gtin'], '0')]);
ok($d['count'] === 1 && $d['rows'][0]['sku'] === 'SMOKE-NW-CR-Q', 'by GTIN (12 digits normalized)');
$sku = share_availability_index($pdo, ['sku' => 'smoke-nw-cr-k']);
ok($sku['count'] === 1 && $sku['rows'][0]['availability'] === 'back_order' && $sku['rows'][0]['lead_time_days'] === 3, 'by SKU: the King is back_order, 3 days');

echo "5. os.inventory-orders/1\n";
$d = share_customer_orders($pdo, $email);
$o = $d['orders'][0] ?? [];
ok($d['schema'] === 'os.inventory-orders/1' && $d['found'] === true && $d['customer']['name'] === "SMOKE Maria $run" && $d['count'] === 1 && $o['number'] === order_row($C)['number'], 'the customer is found by e-mail and only her open order is listed (the two closed ones are not)');
ok(($o['lines'][0]['fulfilment'] ?? '') === 'ships from our supplier' && array_key_exists('order_page_live', $o) && $o['order_page_live'] === false && $noPeople(json_encode(array_diff_key($d, ['email' => 1]))) && preg_match('/[a-f0-9]{48}/', json_encode($d)) !== 1 && stripos(json_encode($d), 'zinus') === false, 'the line reads "ships from our supplier"; order_page_live is false (no link yet); no address, phone, token or supplier\'s name');
$all = share_customer_orders($pdo, strtoupper($email), false);
ok($all['found'] === true && $all['count'] === 3, 'the e-mail is matched ignoring case; open_only false lists all three orders');
$none = share_customer_orders($pdo, 'nobody@example.invalid');
ok($none['found'] === false && $none['orders'] === [] && $none['customer'] === null && $none['count'] === 0, 'an unknown e-mail: found false, orders [], no customer');

echo "6. The declaration and who may call\n";
$names = array_column(declared_shares(), 'tool');
ok($names === ['sales_closed', 'purchases_received', 'stock_valuation', 'availability_index', 'customer_orders'] && $names === array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['shares'], 'tool') && declared_reads() === [], 'declared_shares() is maludb-os.json\'s five tools in order; declared_reads() is empty in version 1');
ok(array_column(declared_shares(), 'document') === ['os.inventory-sales/1', 'os.inventory-purchases/1', 'os.inventory-valuation/1', 'os.inventory-availability/1', 'os.inventory-orders/1'], 'each share names its document');
$ro = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$viaRo = share_availability_index($ro, ['sku' => 'SMOKE-NW-CR-Q']);
$viaRoSales = share_sales_closed($ro, $from, $to);
ok($viaRo['count'] === 1 && $viaRoSales['count'] >= 2, 'the read role (the kernel\'s token, no acting member) calls the shares — they take no caller into account');
$callers = trim((string) shell_exec('grep -rlE "share_(sales_closed|purchases_received|stock_valuation|availability_index|customer_orders)\(" ' . escapeshellarg(dirname(__DIR__, 3) . '/html') . ' 2>/dev/null'));
ok($callers === '', 'no controller under html/ calls a share reader — no screen a Viewer opens reaches them (Phase 4\'s server and slice 9\'s gated exports will)');
finish();

function po_for_order(int $o): int { return (int) one("SELECT id FROM purchase_orders WHERE sales_order_id = :o AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", ['o' => $o]); }
