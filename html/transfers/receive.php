<?php
declare(strict_types=1);
/**
 * Action `transfer_receive` (log `stock.transfer_receive`: number, from_location_id, to_location_id, lines, units, units_received, short[]; confirm):
 * inv_transfer_receive() — `quantities` JSON {line: qty} or the form's `qty[<line>]` (every line in full when absent); a short line leaves the
 * difference in transit on the document. stock.transfer.
 */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.transfer');
$pdo = db();
$t = transfer_or_404($pdo, request_integer('transfer') ?? request_integer('transfer_id'));
$quantities = [];
$raw = $_POST['quantities'] ?? ($_POST['qty'] ?? null);
if ($raw !== null && $raw !== '') {
    $map = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($map)) { refuse(422, 'quantities is a JSON object of line → quantity received.'); }
    $lineIds = array_map(static fn (array $l): int => (int) $l['transfer_line_id'], transfer_lines($pdo, (int) $t['transfer_id']));
    $fields = [];
    foreach ($map as $line => $q) {
        if (!ctype_digit((string) $line) || !in_array((int) $line, $lineIds, true)) { $fields['quantities'] = 'Line ' . $line . ' is not on ' . $t['number'] . '.'; continue; }
        if (filter_var($q, FILTER_VALIDATE_INT) === false || (int) $q < 0) { $fields['qty_' . $line] = 'A received quantity is a whole number of 0 or more.'; continue; }
        $quantities[(string) (int) $line] = (int) $q;
    }
    if ($fields !== []) { inv_refuse_fields($fields); }
}
$res = inv_guard($pdo, static function () use ($pdo, $t, $quantities): array {
    $pdo->beginTransaction();
    $res = receive_transfer($pdo, (int) $t['transfer_id'], stock_me(), $quantities);
    stock_log($pdo, 'stock.transfer_receive', 'inventory_transfer', (int) $t['transfer_id'], (int) $t['to_location_id'], ['number' => $t['number'], 'from_location_id' => (int) $t['from_location_id'], 'to_location_id' => (int) $t['to_location_id'],
        'lines' => $res['lines'], 'units' => $res['units'], 'units_received' => $res['units_received'], 'short' => $res['short']]);
    $pdo->commit();
    return $res;
});
$left = $res['units'] - $res['units_received'];
inv_done('Received ' . $t['number'] . ': ' . $res['units_received'] . ' units into ' . $t['to_location'] . ($left > 0 ? ' — ' . $left . ' left in transit on the document' : ''), (int) $t['transfer_id'], inv_land('/transfers/' . (int) $t['transfer_id'], 'received'), 'stockChanged',
    ['units_received' => $res['units_received'], 'left_in_transit' => $left, 'short' => $res['short']]);
