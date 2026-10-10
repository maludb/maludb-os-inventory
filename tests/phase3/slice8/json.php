<?php
/** JSON mode (returns-worker.md "Proof": JSON mode ≥ 14): every handler under a signed action token answers {ok, did, record_id, location, refresh} with its facts; the expert's reach; the registry. */
require __DIR__ . '/lib.php';
$w = returns_world();
kernel_clean();
$tokSam = ['X-Action-Token: ' . person_token(41)];
$tokNora = ['X-Action-Token: ' . person_token(40)];
$tokWes = ['X-Action-Token: ' . person_token(42)];
$tokOwner = ['X-Action-Token: ' . person_token(1)];
// an order with two foundations shipped
psql_exec("SELECT inv_post_txn('receipt', {$w['fnd_queen']}, {$w['wh']}, 4, 300.00, 'opening', 1, 'smoke-s8-f4json', 'none', NULL, NULL, NULL, 1)");
if (ref_order('S8-SO5') === null) {
    [, $b] = make_quote($w['sam'], $w['alvarez'], [['variant' => $w['fnd_queen'], 'qty' => 2, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => 'S8-SO5', 'delivery_method' => 'delivery']);
    act($w['sam'], '/orders/confirm.php', ['order' => (int) $b['record_id']]);
    $ll = order_lines_of((int) $b['record_id'])[0];
    act($w['wes'], '/orders/ship.php', ['order' => (int) $b['record_id'], 'kind' => 'own_delivery', 'lines' => [$ll['id'] => ['ship' => '1', 'qty' => '2']]]);
}
$so5 = ref_order('S8-SO5');
$fl = sol_of($so5, 'SMOKE-FND-Q');

echo "1. A return, end to end, under tokens\n";
[$c, $b] = act_token('/returns/save.php', ['order' => $so5, 'lines' => json_encode([['line' => $fl['id'], 'qty' => 1, 'reason' => 'comfort', 'disposition' => 'restock']]), 'location' => $w['wh'], 'notes' => 'SMOKE json', 'method' => 'drop_off'], $tokSam);
$ra = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $b['ok'] === true && str_starts_with($b['did'], 'Requested return RA-') && $b['location'] === "/returns/$ra?notice=requested" && $b['refresh'] === 'returnChanged' && str_starts_with($b['number'], 'RA-') && $b['lines'] === 1 && $b['status'] === 'requested', 'return_request answers {ok, did, record_id, location, refresh} with the number and the lines');
[$c, $b] = act_token('/returns/save.php', ['return' => $ra, 'scheduled_on' => date('Y-m-d', strtotime('+4 days')), '_partial' => '1'], $tokSam);
$r = return_row($ra);
ok($c === 200 && $r['scheduled_on'] === date('Y-m-d', strtotime('+4 days')) && $r['notes'] === 'SMOKE json' && $r['method'] === 'drop_off' && (int) $r['location_id'] === $w['wh'] && count(return_lines_of($ra)) === 1, 'return_update with _partial=1 keeps the lines, the notes, the method and the location when only scheduled_on is sent');
[$c, $b] = act_token('/returns/save.php', ['return' => $ra, 'lines' => json_encode([['line' => $fl['id'], 'qty' => 9, 'reason' => 'comfort']])], $tokSam);
ok($c === 422 && $b['error']['code'] === 'invalid' && isset($b['error']['fields']["lines.{$fl['id']}.qty"]) && str_contains($b['error']['message'], 'may still come back'), 'a refusal answers {error: {code: invalid, message, fields}} naming the line\'s field');
$rl = (int) return_lines_of($ra)[0]['id'];
[$c, $b] = act_token('/returns/lines/save.php', ['return_line' => $rl, 'condition_note' => 'boxed', '_partial' => '1'], $tokSam);
ok($c === 200 && $b['ok'] === true && $b['sku'] === 'SMOKE-FND-Q' && return_lines_of($ra)[0]['condition_note'] === 'boxed' && (int) return_lines_of($ra)[0]['qty'] === 1, 'return_line_add with _partial=1 changes one field of a line');
[$c, $b] = act_token('/returns/approve.php', ['return' => $ra], $tokSam);
ok($c === 403, 'return_authorize under Sam\'s token: 403');
[$c, $b] = act_token('/returns/approve.php', ['return' => $ra], $tokNora);
ok($c === 200 && $b['status'] === 'approved' && $b['refresh'] === 'returnChanged', 'return_authorize under Nora\'s');
[$c, $b] = act_token('/returns/disposition.php', ['return_line' => $rl, 'disposition' => 'floor_model', 'location' => $w['sr']], $tokNora);
ok($c === 200 && $b['disposition'] === 'floor_model' && $b['return_line_id'] === $rl, 'return_disposition_set');
[$c, $b] = act_token('/returns/receive.php', ['return' => $ra, 'quantities' => json_encode([(string) $rl => 1]), 'condition_notes' => json_encode([(string) $rl => 'as new'])], $tokWes);
ok($c === 200 && $b['status'] === 'received' && $b['floor'][0]['sku'] === 'SMOKE-FND-Q' && $b['restocked'] === [] && $b['short'] === [] && $b['refresh'] === 'returnChanged, stockChanged', 'return_receive answers restocked, floor, short (the stockChanged event beside returnChanged)');
act($w['sam'], '/orders/payments/save.php', ['order' => $so5, 'kind' => 'deposit', 'amount' => '100.00', 'method' => 'card']);
[$c, $b] = act_token('/returns/close.php', ['return' => $ra, 'refund_amount' => '10.00', 'restocking_fee' => '0'], $tokNora);
ok($c === 200 && $b['refund_amount'] === '10.00' && $b['status'] === 'closed', 'return_close');
[$c, $b] = act_token('/returns/save.php', ['order' => $so5, 'lines' => json_encode([['line' => $fl['id'], 'qty' => 1, 'reason' => 'damaged', 'disposition' => 'dispose']])], $tokSam);
$rb = (int) $b['record_id'];
[$c, $b] = act_token('/returns/deny.php', ['return' => $rb, 'reason' => 'Damaged by the customer'], $tokNora);
ok($c === 200 && $b['status'] === 'denied', 'return_deny');
[$c, $b] = act_token('/returns/lines/remove.php', ['return_line' => $rl], $tokSam);
ok($c === 422 && $b['error']['code'] === 'invalid', 'return_line_remove on a closed return: 422 in JSON');

echo "2. The Buyer's actions and the notes\n";
$tokAgent = as_agent(run_token(47, 341));
[$c, $b] = act_token('/proposals/save.php', ['kind' => 'price', 'subject' => 'S8 json price', 'variant' => $w['fnd_queen'], 'confidence' => '0.5'], $tokAgent);
$pp = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $b['proposal_id'] === $pp && $b['kind'] === 'price' && $b['location'] === "/proposals/#proposal-card-$pp" && $b['refresh'] === 'proposalChanged', 'buyer_propose answers proposal_id and the card\'s location');
[$c, $b] = act_token('/proposals/accept.php', ['proposal' => $pp], $tokNora);
ok($c === 200 && $b['status'] === 'accepted', 'buyer_proposal_accept under a person\'s token');
[$c, $b] = act_token('/proposals/morning-note.php', ['body' => 'JSON note', 'to_member' => 44], $tokAgent);
ok($c === 200 && $b['queued'] === true && $b['to'] === 44 && $b['note_date'] === gmdate('Y-m-d'), 'morning_note_send answers queued and to');
[$c, $b] = act_token('/notes/add.php', ['record_type' => 'return', 'record' => $ra, 'body' => 'json note'], $tokSam);
$nn = (int) $b['record_id'];
ok($c === 200 && $b['record_type'] === 'return_authorization' && $b['refresh'] === 'noteChanged', 'note_add');
[$c, $b] = act_token('/notes/delete.php', ['note' => $nn], $tokSam);
ok($c === 200 && $b['refresh'] === 'noteChanged', 'note_delete');

echo "3. The expert and the registry\n";
$tokExpert = as_agent(run_token(45, 342));
[$c, $b] = act_token('/returns/save.php', ['order' => $so5, 'lines' => json_encode([['line' => $fl['id'], 'qty' => 1, 'reason' => 'comfort', 'disposition' => 'dispose']])], $tokExpert);
ok($c === 200 && (int) q("SELECT agent_run_id FROM activity_log WHERE action = 'return.request' ORDER BY id DESC LIMIT 1")[0]['agent_run_id'] === 342, 'the expert (a `user`, holding orders.write) requests a return — source agent, its run');
$re = (int) $b['record_id'];
[$c, $b] = act_token('/returns/approve.php', ['return' => $re], $tokExpert);
ok($c === 403, 'but cannot authorize it (no returns.write)');
[$c, $b] = act_token('/proposals/accept.php', ['proposal' => $pp], $tokExpert);
ok($c === 403, 'nor accept a proposal');
[$c, $b] = act_token('/admin/dispatches/retry.php', ['dispatch' => 1], $tokNora);
ok($c === 403, 'dispatch_retry under Nora\'s token: 403');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$screens = ['return-list', 'return-add', 'return-view', 'return-edit', 'return-receive', 'proposal-list', 'dispatch-list'];
$actions = ['return_request', 'return_update', 'return_line_add', 'return_line_remove', 'return_authorize', 'return_deny', 'return_receive', 'return_disposition_set', 'return_close', 'buyer_propose', 'buyer_proposal_accept', 'buyer_proposal_dismiss', 'morning_note_send', 'note_add', 'note_delete', 'attachment_add', 'attachment_delete', 'dispatch_retry'];
ok(count(array_filter($screens, static fn ($s) => ($reg['screens'][$s]['built'] ?? false) === true)) === 7 && count(array_filter($actions, static fn ($a) => ($reg['actions'][$a]['built'] ?? false) === true)) === 18, 'the registry reads the seven screens and eighteen actions of this slice as built');
ok($reg['actions']['return_authorize']['approval'] === 'other' && $reg['actions']['return_authorize']['endpoint'] === '/returns/approve.php' && $reg['actions']['dispatch_retry']['endpoint'] === '/admin/dispatches/retry.php' && ($reg['actions']['morning_note_send']['approval'] ?? null) === null, 'return_authorize carries the `other` category (the hook pauses it on the MCP path); morning_note_send none');
$sync = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php bin/sync_approvals.php --check 2>&1; echo "exit=$?"');
ok(str_contains((string) $sync, 'exit=0'), 'bin/sync_approvals.php --check: maludb-os.json approvals[] in sync with the manifest');
$built = (int) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php bin/build_action_registry.php --check >/dev/null 2>&1; echo $?');
ok($built === 0, 'bin/build_action_registry.php --check: the registry is current');

echo "4. The screens as JSON\n";
[$c, $d] = screen($w['nora'], '/returns/new?order=' . ref_order('S8-SO3'));
ok($c === 200 && $d['order']['number'] !== '' && count($d['returnable_lines']) >= 1 && $d['returnable_lines'][0]['returnable'] >= 1 && count($d['reasons']) === 5 && array_keys($d['dispositions']) === ['restock', 'floor_model', 'dispose', 'return_to_supplier', 'donate'], '/returns/new?order= : the order, its returnable lines, the five return reasons and the dispositions');
[$c, $d] = screen($w['nora'], "/returns/$re/edit");
ok($c === 200 && $d['return']['status'] === 'requested', '/returns/N/edit answers JSON for a requested return');
[$c, $d] = screen($w['nora'], "/returns/$ra/edit");
ok($c === 422, '…and refuses a closed one in the trigger\'s words');
[$c, $d] = screen($w['owner'], '/admin/dispatches');
ok($c === 200 && isset($d['dispatches'][0]['run_url']) && array_key_exists('reply_excerpt', $d['dispatches'][0]), '/admin/dispatches answers JSON with the run links');
[$c, $d] = screen($w['nora'], '/proposals/');
ok($c === 200 && isset($d['counts']['proposed']), '/proposals/ answers JSON with the tab counts');
act($w['nora'], '/returns/deny.php', ['return' => $re, 'reason' => 'cleanup']);
finish();
