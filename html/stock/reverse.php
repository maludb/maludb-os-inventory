<?php
declare(strict_types=1);
/** Action `stock_reverse` (log `stock.reverse`: transaction_id, reverses_id, txn_type, sku, location_id, qty, reference_kind, reference_id; confirm): the opposite movement, linked, once. stock.adjust. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.adjust');
$pdo = db();
$txnId = request_integer('transaction') ?? request_integer('transaction_id');
$t = $txnId === null ? null : find_movement($pdo, $txnId);
if ($t === null) { refuse(404, 'Movement not found.'); }
$note = stock_text('note', null, 1000);
$new = inv_guard($pdo, static function () use ($pdo, $t, $note): array {
    $pdo->beginTransaction();
    $new = reverse_transaction($pdo, (int) $t['transaction_id'], stock_me(), $note);
    stock_log($pdo, 'stock.reverse', 'inventory_transaction', (int) $new['id'], (int) $t['location_id'], ['transaction_id' => (int) $new['id'], 'reverses_id' => (int) $t['transaction_id'], 'txn_type' => $t['txn_type'],
        'new_txn_type' => $new['txn_type'], 'sku' => $t['sku'], 'location_id' => (int) $t['location_id'], 'qty' => (int) $new['qty'], 'reference_kind' => $t['reference_kind'], 'reference_id' => (int) $t['reference_id'], 'note' => $note]);
    $pdo->commit();
    return $new;
});
inv_done('Reversed movement #' . (int) $t['transaction_id'], (int) $new['id'], inv_land('/stock/movements?variant=' . (int) $t['variant_id'], 'reversed', 'movement-row-' . (int) $new['id']), 'stockChanged', ['transaction_id' => (int) $new['id'], 'reverses_id' => (int) $t['transaction_id']]);
