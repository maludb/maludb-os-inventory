<?php
declare(strict_types=1);
/** GET /receipts/po-options?supplier= — the purchase order select's options for a supplier (Pattern A: swapped into #receipt-form-field-purchase_order). stock.receive. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('stock.receive');
$pdo = db();
$sid = request_integer('supplier');
$opts = $sid === null ? [] : po_options($pdo, $sid);
if (wants_json()) { respond_screen(['supplier' => $sid, 'purchase_orders' => $opts]); }
echo view('receipts/partials/po-options.php', ['options' => $opts, 'selected' => null, 'supplier' => $sid]);
