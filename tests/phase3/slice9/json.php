<?php
/** JSON mode (reports-admin.md "Proof": ≥ 12): the seven actions under a signed action token answer their facts, a partial update keeps the rest, a refusal is {error: {code: invalid, fields}}, the home and the trail answer data, the registry reads every screen and action built. */
require __DIR__ . '/lib.php';
$w = admin_world();
$tok = person_token(1);
$H = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . relay_of($tok)];
$shape = static fn (int $c, array $b): bool => $c === 200 && ($b['ok'] ?? false) === true && isset($b['did']) && array_key_exists('record_id', $b);
$row = static fn (): array => q('SELECT * FROM inv_settings WHERE id = 1')[0];
$orig = (string) one('SELECT to_jsonb(s)::text FROM inv_settings s WHERE id = 1');

echo "1. settings_save\n";
$snap = $row();
[$c, $b] = act_token('/admin/settings.php', ['ack_days' => '4', '_partial' => '1'], $H);
$r = $row();
$diff = array_keys(array_filter($r, static fn ($v, $k): bool => $k !== 'updated_at' && $snap[$k] !== $v, ARRAY_FILTER_USE_BOTH));
ok($shape($c, $b) && $diff === ['ack_days'] && (int) $r['ack_days'] === 4, 'settings_save with _partial=1 keeps every other column');
[$c, $b] = act_token('/admin/settings.php', ['_settings' => '1', 'ack_days' => '5', '_partial' => '1'], $H);
$r2 = $row();
$diff = array_keys(array_filter($r2, static fn ($v, $k): bool => $k !== 'updated_at' && $r[$k] !== $v, ARRAY_FILTER_USE_BOTH));
ok($shape($c, $b) && $diff === ['ack_days'], 'and with _settings=1 the kit fills the row\'s own values (lists as {a,b}, bytes for the attachment limit) and still only ack_days changes');
[$c, $b] = act_token('/admin/settings.php', ['order_link_days' => '0'], $H);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']->order_link_days) || isset($b['error']['fields']['order_link_days']), 'a refusal is {error: {code: invalid, fields}}');

echo "2. The other actions\n";
$next = (int) one("SELECT next_value FROM document_sequences WHERE kind = 'transfer'") + 3;
[$c, $b] = act_token('/admin/sequences.php', ['kind' => 'transfer', 'next_value' => (string) $next], $H);
ok($shape($c, $b) && $b['record_id'] === 'transfer' && str_ends_with($b['next_number'], (string) $next), 'sequence_set answers its facts (did, record_id = the kind, next_number ' . ($b['next_number'] ?? '') . ')');
psql_exec("UPDATE document_sequences SET next_value = " . ($next - 3) . " WHERE kind = 'transfer'");
[$c, $b] = act_token('/admin/tax-rates/save.php', ['name' => 'S9 JSON rate', 'rate' => '3.5'], $H);
$tr = $b['record_id'] ?? 0;
ok($shape($c, $b) && $tr > 0 && (int) $b['tax_rate_id'] === $tr, 'tax_rate_save answers its facts (the new id)');
[$c, $b] = act_token('/admin/tax-rates/save.php', ['tax_rate' => (string) $tr, 'rate' => '3.75', '_partial' => '1'], $H);
ok($shape($c, $b) && (float) one('SELECT rate FROM tax_rates WHERE id = :i', ['i' => $tr]) === 3.75 && one('SELECT name FROM tax_rates WHERE id = :i', ['i' => $tr]) === 'S9 JSON rate', 'tax_rate_save with _partial=1 changes the rate and keeps the name');
[$c, $b] = act_token('/admin/tax-rates/archive.php', ['tax_rate' => (string) $tr], $H);
ok($shape($c, $b) && one('SELECT archived_at FROM tax_rates WHERE id = :i', ['i' => $tr]) !== null, 'tax_rate_archive answers its facts');
[$c, $b] = act_token('/admin/reason-codes/save.php', ['name' => 'S9 JSON reason', 'applies_to' => 'adjustment,return'], $H);
$rr = $b['record_id'] ?? 0;
ok($shape($c, $b) && $rr > 0 && one('SELECT applies_to FROM reason_codes WHERE id = :i', ['i' => $rr]) === '{adjustment,return}' && one('SELECT code FROM reason_codes WHERE id = :i', ['i' => $rr]) === 's9_json_reason', 'reason_code_save answers its facts (applies_to as a comma list; the code made from the name)');
[$c, $b] = act_token('/admin/reason-codes/save.php', ['reason' => (string) $rr, 'sort_order' => '77', '_partial' => '1'], $H);
ok($shape($c, $b) && (int) one('SELECT sort_order FROM reason_codes WHERE id = :i', ['i' => $rr]) === 77 && one('SELECT applies_to FROM reason_codes WHERE id = :i', ['i' => $rr]) === '{adjustment,return}', 'and a partial update of it keeps where it applies (the kit fills "{a,b}" back)');
[$c, $b] = act_token('/reports/run.php', ['report' => 'stock-value', 'by' => 'brand'], $H);
ok($c === 200 && $b['ok'] === true && $b['report'] === 'stock-value' && is_array($b['rows']) && isset($b['totals']) && array_key_exists('truncated', $b) && $b['params']['by'] === 'brand', 'report_run answers {ok, did, rows, totals, truncated, report, params}');
[$c, $b] = act_token('/reports/run.php', ['report' => 'sales', 'by' => 'galaxy'], $H);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid', 'a bad report filter is the 422 shape');
[$c, $b] = act_token('/exports/download.php', ['export' => 'stock_valuation', 'format' => 'json'], $H);
ok($shape($c, $b) && $b['rows'] >= 1 && $b['bytes'] > 100 && $b['period']['as_of'] === gmdate('Y-m-d') && !isset($b['totals']), 'export_download answers the facts (rows, bytes, period) and no file');

echo "3. The screens and the registry\n";
foreach (['/' => ['note', 'at_risk', 'my_orders', 'bell', 'may'], '/trail' => ['rows', 'page', 'more', 'filters'], '/reports/' => ['reports'], '/exports/' => ['exports'], '/admin/settings' => ['settings', 'groups'], '/admin/sequences' => ['sequences'],
          '/admin/tax-rates/' => ['tax_rates'], '/admin/reason-codes/' => ['reason_codes'], '/admin/agents' => ['agents'], '/reports/lead-times' => ['rows', 'totals']] as $path => $keys) {
    [$c, $d] = screen(who('owner'), $path);
    $ok = $c === 200;
    foreach ($keys as $k) { $ok = $ok && array_key_exists($k, $d); }
    if (!$ok) { ok(false, "$path answers data with " . implode(', ', $keys) . " (got $c)"); }
}
ok(true, 'the home, the trail, the hub, the exports and the admin screens all answer data (200) in JSON');
[$c, $d] = screen(who('owner'), '/admin/settings');
ok(array_key_exists('crawl_user_agent', $d['settings']) && $d['settings']['max_attachment_mb'] > 0 && !str_contains(json_encode($d), 'password'), 'the settings JSON carries every column (and the attachment limit in MB)');
$out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php bin/build_action_registry.php --check 2>&1');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$bs = count(array_filter($reg['screens'], static fn (array $s): bool => !empty($s['built'])));
$ba = count(array_filter($reg['actions'], static fn (array $a): bool => !empty($a['built'])));
ok(str_contains((string) $out, 'OK') && $bs === 108 && count($reg['screens']) === 108 && $ba === 132 && count($reg['actions']) === 132, "the registry reads every screen and action built: $bs of " . count($reg['screens']) . ' screens, ' . $ba . ' of ' . count($reg['actions']) . ' actions');
$out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php bin/sync_approvals.php --check 2>&1');
ok(str_contains((string) $out, '26 approvals in sync'), 'sync_approvals --check: ' . trim((string) $out));
$cols = array_column($reg['actions'], 'approval');
ok(in_array('other', $cols, true) && ($reg['actions']['tax_rate_archive']['confirm'] ?? false) === true && ($reg['actions']['sequence_set']['confirm'] ?? false) === true, 'tax_rate_archive and sequence_set carry a confirm; the three saves carry `other`');
psql_exec("DELETE FROM tax_rates WHERE name = 'S9 JSON rate'");
finish();
