<?php
declare(strict_types=1);
/**
 * GET /returns/{id}/receive — screen `return-receive` (returns.receive; built at 375 px: a card per line with a 44 px stepper, a scan field that focuses the line, the Receive button pinned in the header).
 * POST — action `return_receive` (log `return.receive` with the store as location_id: number, lines, restocked, floor, disposed, donated, to_supplier [{sku, qty, purchase_order_id}], short; confirm): returns.receive.
 * `quantities` as JSON {return_line: qty received} or the form's lines[<return line>][qty_received] (absent = what was asked for; less lowers the line and is logged as `short`; 0 removes it), `condition_notes`
 * likewise, `lines[<id>][location]` where a restock needs one. receive_return() calls the schema's verb, which writes the `return` movement (and `floor_model_in` for a floor model), notes the supplier's purchase
 * order for a return to the supplier, counts what came back on the order's lines. The requester and the order's salesperson are told. Location /returns/{id}; refresh returnChanged, stockChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/returns/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    returns_write_begin('returns.receive');
    $r = return_or_404($pdo, request_return_id($pdo));
    $errors = [];
    $quantities = [];
    $notes = [];
    $locations = [];
    $json = static function (string $name) use (&$errors): array {
        if (!isset($_POST[$name])) { return []; }
        $v = is_array($_POST[$name]) ? $_POST[$name] : json_decode((string) $_POST[$name], true);
        if (!is_array($v)) { refuse(422, $name . ' is a JSON object of return line → value.'); }
        return $v;
    };
    foreach ($json('quantities') as $k => $q) {
        if (!is_scalar($q) || filter_var($q, FILTER_VALIDATE_INT) === false || (int) $q < 0) { $errors['quantities.' . $k] = 'The quantity is a whole number of 0 or more.'; continue; }
        $quantities[(int) $k] = (int) $q;
    }
    foreach ($json('condition_notes') as $k => $n) {
        if (mb_strlen((string) $n) > 500) { $errors['condition_notes.' . $k] = 'The condition note is up to 500 characters.'; continue; }
        $notes[(int) $k] = trim((string) $n);
    }
    foreach ((array) ($_POST['lines'] ?? []) as $k => $row) {
        if (!is_array($row)) { continue; }
        $k = (int) $k;
        if (isset($row['qty_received']) && trim((string) $row['qty_received']) !== '') {
            $q = trim((string) $row['qty_received']);
            if (filter_var($q, FILTER_VALIDATE_INT) === false || (int) $q < 0) { $errors['lines.' . $k . '.qty_received'] = 'The quantity is a whole number of 0 or more.'; continue; }
            $quantities[$k] = (int) $q;
        }
        if (isset($row['condition_note'])) {
            if (mb_strlen((string) $row['condition_note']) > 500) { $errors['lines.' . $k . '.condition_note'] = 'The condition note is up to 500 characters.'; continue; }
            $notes[$k] = trim((string) $row['condition_note']);
        }
        if (isset($row['location']) && trim((string) $row['location']) !== '') {
            $lv = trim((string) $row['location']);
            if (!ctype_digit($lv) || one_value($pdo, 'SELECT 1 FROM mcp_locations WHERE location_id = :id AND active', ['id' => (int) $lv]) === null) { $errors['lines.' . $k . '.location'] = 'That location is not here.'; continue; }
            $locations[$k] = (int) $lv;
        }
    }
    if ($errors !== []) { inv_refuse_fields($errors); }
    $out = inv_guard($pdo, static function () use ($pdo, $r, $quantities, $notes, $locations): array {
        $pdo->beginTransaction();
        try {
            $out = receive_return($pdo, (int) $r['return_id'], $quantities, $notes, (int) current_member_id(), $locations);
        } catch (DomainException $e) {
            if (preg_match('/^Say where (\S+) comes back to\.$/', $e->getMessage(), $m)) {         // name the line's field
                $pdo->rollBack();
                $lid = one_value($pdo, 'SELECT return_line_id FROM mcp_return_lines WHERE return_id = :r AND sku = :s ORDER BY return_line_id LIMIT 1', ['r' => (int) $r['return_id'], 's' => $m[1]]);
                inv_refuse_fields([$lid === null ? 'location' : 'lines.' . $lid . '.location' => $e->getMessage()]);
            }
            throw $e;
        }
        $now = find_return($pdo, (int) $r['return_id']);
        return_log($pdo, 'return.receive', $r, ['number' => $r['number'], 'status' => $now['status'], 'lines' => $now['line_count'], 'restocked' => $out['restocked'], 'floor' => $out['floor'], 'disposed' => $out['disposed'],
            'donated' => $out['donated'], 'to_supplier' => $out['to_supplier'], 'short' => $out['short']], ['location_id' => $r['location_id']]);
        foreach (return_people($r) as $m) { notify($pdo, (int) $m, 'return', 'return', (int) $r['return_id'], 'Return ' . $r['number'] . ' received', null, 'return:' . $r['return_id'] . ':received:' . $m); }
        $pdo->commit();
        return $out;
    });
    inv_done('Received ' . $r['number'], (int) $r['return_id'], inv_land('/returns/' . (int) $r['return_id'], 'received'), 'returnChanged, stockChanged',
        ['return_id' => (int) $r['return_id'], 'number' => $r['number'], 'status' => 'received', 'restocked' => $out['restocked'], 'floor' => $out['floor'], 'disposed' => $out['disposed'], 'donated' => $out['donated'], 'to_supplier' => $out['to_supplier'], 'short' => $out['short']]);
}
require_right('returns.receive');
$r = return_or_404($pdo, request_return_id($pdo));
return_screen_view($pdo, $r, 'return-receive');
$lines = return_lines($pdo, (int) $r['return_id']);
if (wants_json()) { respond_screen(['return' => present_return_row($r), 'lines' => array_map('present_return_line', $lines), 'may_receive' => $r['status'] === 'approved', 'locations' => return_locations($pdo)]); }
render_screen('Receive ' . $r['number'], view('returns/receive.php', ['r' => $r, 'lines' => $lines, 'locations' => return_locations($pdo), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, RETURN_NOTICES)]),
    ['activeNav' => 'return-list', 'screen' => 'return-receive', 'entity' => 'return_authorization', 'recordId' => (string) $r['return_id']]);
