<?php
/** The settings (reports-admin.md "Proof": ≥ 28): every column through the form and read back, the refusals in a field's name, the vocabulary's rules, the Buyer, the log, the right. The original row is put back at the end. */
require __DIR__ . '/lib.php';
$w = admin_world();
[$nora, $sam, $owner] = [who('nora'), who('sam'), who('owner')];
$row = static fn (): array => q('SELECT * FROM inv_settings WHERE id = 1')[0];
$orig = (string) one('SELECT to_jsonb(s)::text FROM inv_settings s WHERE id = 1');
$save = static fn (array $f, ?string $jar = null): array => (function () use ($f, $jar): array { [$c, $b, $r] = act($jar ?? who('owner'), '/admin/settings.php', $f); return [$c, $b, $r]; })();
$restore = static function () use ($orig): void {
    $cols = array_column(q("SELECT column_name FROM information_schema.columns WHERE table_name = 'inv_settings' AND column_name <> 'id' ORDER BY ordinal_position"), 'column_name');
    psql_exec('UPDATE inv_settings s SET (' . implode(', ', $cols) . ") = (SELECT " . implode(', ', array_map(static fn ($c) => "r.$c", $cols)) . " FROM jsonb_populate_record(NULL::inv_settings, '" . str_replace("'", "''", $orig) . "'::jsonb) r) WHERE s.id = 1");
};
$cols = ['business_name', 'business_contact_email', 'business_phone', 'business_address', 'currency', 'units', 'timezone', 'sales_sees_cost', 'supplier_sees_phone', 'feed_shows_quantity', 'order_link_days', 'supplier_link_days', 'feed_rate_per_minute', 'feed_rate_per_day',
         'key_rotation_overlap_hours', 'sizes', 'attribute_keys', 'reorder_point_default', 'reorder_qty_default', 'cost_source', 'cost_move_pct', 'reference_undercut_pct', 'ack_days', 'buyer_member_id', 'crawl_user_agent', 'crawl_rate_per_second',
         'crawl_backoff_minutes', 'crawl_max_pages', 'schedule_supplier_minutes', 'schedule_reference_minutes', 'schedule_jsonld_minutes', 'removed_after_pulls', 'snapshot_heartbeat_days', 'raw_max_bytes', 'max_attachment_bytes'];

echo "1. The page\n";
[$c, $html] = pg($owner, '/admin/settings');
$missing = array_filter($cols, static fn (string $col): bool => !has_id($html, 'admin-settings-field-' . ($col === 'buyer_member_id' ? 'buyer' : $col)));
ok($c === 200 && $missing === [] && count($cols) === 35, 'the form has a field for every one of the ' . count($cols) . ' columns (admin-settings-field-{column})' . ($missing ? ': missing ' . implode(', ', $missing) : ''));
$groups = ['business', 'doors', 'feed', 'vocabulary', 'buying', 'crawl', 'files'];
ok(array_reduce($groups, static fn (bool $a, string $g): bool => $a && has_id($html, "admin-settings-group-$g"), true) && has_id($html, 'admin-settings-sizes-table') && has_id($html, 'admin-settings-attributes-table') && has_id($html, 'admin-settings-form-save-btn') && has_id($html, 'admin-settings-form-cancel-btn'), 'seven groups as cards, the sizes and attributes tables beside their textareas, Save and Cancel in the header');
ok(substr_count($html, '<tr>') >= 13 && str_contains(text_of($html, 'admin-settings-sizes-table'), 'California King'), 'the sizes table renders the current list (twelve sizes)');
ok(str_contains($html, 'Last saved'), 'the time the row was last saved is shown');

echo "2. Every column through the form\n";
$sizes = json_decode($row()['sizes'], true);
$sizes[] = ['key' => 'bunk', 'name' => 'Bunk', 'synonyms' => ['Bunk Bed', 'bunkbed']];
$attrs = json_decode($row()['attribute_keys'], true);
$attrs[] = ['key' => 'allergen', 'name' => 'Allergen', 'kind' => 'multi', 'choices' => ['latex', 'wool']];
$form = ['business_name' => 'SMOKE Mattress Co', 'business_contact_email' => 'ops@smoke.example.invalid', 'business_phone' => '312-555-0100', 'business_address' => "1 Main St\nChicago IL", 'currency' => 'eur', 'units' => 'metric',
    'timezone' => 'America/Chicago', 'sales_sees_cost' => 'yes', 'supplier_sees_phone' => ['parcel', 'ltl'], 'feed_shows_quantity' => 'yes', 'order_link_days' => '200', 'supplier_link_days' => '100', 'feed_rate_per_minute' => '90',
    'feed_rate_per_day' => '20000', 'key_rotation_overlap_hours' => '48', 'sizes' => json_encode($sizes), 'attribute_keys' => json_encode($attrs), 'reorder_point_default' => '3', 'reorder_qty_default' => '4', 'cost_source' => 'feed',
    'cost_move_pct' => '7.50', 'reference_undercut_pct' => '12.25', 'ack_days' => '5', 'buyer' => '41', 'crawl_user_agent' => 'SMOKE Mattress Co (+https://smoke.example.invalid/bot; mailto:ops@smoke.example.invalid)', 'crawl_rate_per_second' => '2.50',
    'crawl_backoff_minutes' => ['30', '120', '2880'], 'crawl_max_pages' => '150', 'schedule_supplier_minutes' => '45', 'schedule_reference_minutes' => '480', 'schedule_jsonld_minutes' => '720', 'removed_after_pulls' => '3',
    'snapshot_heartbeat_days' => '2', 'raw_max_bytes' => '16384', 'max_attachment_bytes' => '25'];
$before = $row();
$since = last_activity_id();
[$c, $b] = $save($form);
$r = $row();
ok($c === 200 && $b['ok'] === true && count($b['changed']) >= 30, 'one POST with every field → saved (' . count($b['changed'] ?? []) . ' fields changed)');
$exp = ['business_name' => 'SMOKE Mattress Co', 'business_contact_email' => 'ops@smoke.example.invalid', 'business_phone' => '312-555-0100', 'business_address' => "1 Main St\nChicago IL", 'currency' => 'EUR', 'units' => 'metric', 'timezone' => 'America/Chicago',
        'sales_sees_cost' => true, 'feed_shows_quantity' => true, 'order_link_days' => 200, 'supplier_link_days' => 100, 'feed_rate_per_minute' => 90, 'feed_rate_per_day' => 20000, 'key_rotation_overlap_hours' => 48, 'reorder_point_default' => 3,
        'reorder_qty_default' => 4, 'cost_source' => 'feed', 'ack_days' => 5, 'buyer_member_id' => 41, 'crawl_max_pages' => 150, 'schedule_supplier_minutes' => 45, 'schedule_reference_minutes' => 480, 'schedule_jsonld_minutes' => 720,
        'removed_after_pulls' => 3, 'snapshot_heartbeat_days' => 2, 'raw_max_bytes' => 16384, 'max_attachment_bytes' => 26214400];
$wrong = [];
foreach ($exp as $k => $v) { if ($r[$k] !== $v) { $wrong[] = "$k: " . var_export($r[$k], true) . ' ≠ ' . var_export($v, true); } }
ok($wrong === [], 'the row reads back: names, numbers, switches, the Buyer, the currency upper-cased, 25 MB as 26214400' . ($wrong ? ' — ' . implode('; ', $wrong) : ''));
ok((float) $r['cost_move_pct'] === 7.5 && (float) $r['reference_undercut_pct'] === 12.25 && (float) $r['crawl_rate_per_second'] === 2.5 && $r['supplier_sees_phone'] === '{parcel,ltl}' && $r['crawl_backoff_minutes'] === '{30,120,2880}', 'decimals, the shipping kinds and the backoff ladder too');
$rs = json_decode($r['sizes'], true); $ra = json_decode($r['attribute_keys'], true);
ok(end($rs)['key'] === 'bunk' && end($rs)['synonyms'] === ['bunk bed', 'bunkbed'] && end($ra)['key'] === 'allergen' && end($ra)['choices'] === ['latex', 'wool'] && count($rs) === 13, 'the sizes (synonyms lower-cased) and the attribute keys');
ok($r['crawl_user_agent'] === $form['crawl_user_agent'], 'the user-agent');
$lg = last_log('settings.save', $since); $a = after_of($lg); $bf = json_decode((string) $lg['before'], true);
ok($lg !== null && $lg['entity_type'] === 'settings' && $a['crawl_user_agent'] === 'changed' && $bf['crawl_user_agent'] === 'changed' && !str_contains($lg['after'], 'smoke.example.invalid/bot') && $a['sizes']['added'] === ['bunk'] && $a['attribute_keys']['added'] === ['allergen'] && $a['order_link_days'] === 200 && $bf['order_link_days'] === 180, 'settings.save logged: the fields changed with before/after — the user-agent as "changed", sizes as a count and the keys added');

echo "3. A field left out stays\n";
$snap = $row();
[$c, $b] = $save(['ack_days' => '6']);
$r = $row();
$diff = array_keys(array_filter($r, static fn ($v, $k): bool => $k !== 'updated_at' && $snap[$k] !== $v, ARRAY_FILTER_USE_BOTH));
ok($c === 200 && $diff === ['ack_days'] && $b['changed'] === ['ack_days'], 'a post with one field changes that column and no other');
$since = last_activity_id();
[$c, $b] = $save(['ack_days' => '6']);
ok($c === 200 && $b['changed'] === [] && last_log('settings.save', $since) === null, 'saving what is already there changes nothing and logs nothing');

echo "4. The refusals\n";
$snap = $row();
[$c, $b] = $save(['order_link_days' => '0']);
ok($c === 422 && isset(fields($b)['order_link_days']) && str_contains(msg($b), 'Order link days') && $row() == $snap, 'order_link_days 0 → 422 naming the field (PHP\'s bound; the CHECK is never reached)');
[$c, $b] = $save(['timezone' => 'Mars/Olympus']);
ok($c === 422 && isset(fields($b)['timezone']), 'timezone Mars/Olympus → 422');
[$c, $b] = $save(['currency' => 'dollars']);
ok($c === 422 && isset(fields($b)['currency']), 'currency "dollars" → 422');
[$c, $b] = $save(['business_contact_email' => 'not-an-address']);
ok($c === 422 && isset(fields($b)['business_contact_email']), 'a contact e-mail that is not an address → 422');
[$c, $b] = $save(['crawl_user_agent' => 'SMOKE Mattress Co bot']);
ok($c === 422 && str_contains(strtolower(msg($b)), 'honest user-agent names the business and a contact'), 'crawl_user_agent without "(+" → 422: an honest user-agent names the business and a contact');
[$c, $b] = $save(['crawl_backoff_minutes' => ['60', '30']]);
ok($c === 422 && str_contains(fields($b)['crawl_backoff_minutes'] ?? '', 'rising'), 'crawl_backoff_minutes [60, 30] → 422: rising');
[$c, $b] = $save(['crawl_rate_per_second' => '11']);
ok($c === 422 && isset(fields($b)['crawl_rate_per_second']), 'crawl_rate_per_second 11 → 422 (0.01 to 10)');
[$c, $b] = $save(['max_attachment_bytes' => '2000']);
ok($c === 422 && isset(fields($b)['max_attachment_bytes']), 'an attachment limit of 2000 MB → 422 (1 to 1024)');
[$c, $b] = $save(['buyer' => '47']);
ok($c === 422 && isset(fields($b)['buyer']) && (int) $row()['buyer_member_id'] === 41, 'the Buyer an agent → 422');
[$c, $b] = $save(['buyer' => '']);
ok($c === 200 && $row()['buyer_member_id'] === null, 'an empty Buyer → NULL (the first super-admin)');
[$c, $b] = $save(['buyer' => '40']);
ok($c === 200 && (int) $row()['buyer_member_id'] === 40, 'Nora is the Buyer again');

echo "5. The vocabulary\n";
$dup = [['key' => 'a', 'name' => 'A', 'synonyms' => []], ['key' => 'a', 'name' => 'A2', 'synonyms' => []]];
[$c, $b] = $save(['sizes' => json_encode($dup)]);
ok($c === 422 && str_contains(fields($b)['sizes'] ?? '', 'twice'), 'sizes with a duplicate key → 422');
[$c, $b] = $save(['sizes' => json_encode([['key' => 'a', 'name' => 'A', 'synonyms' => ['big']], ['key' => 'b', 'name' => 'B', 'synonyms' => ['Big']]])]);
ok($c === 422 && str_contains(fields($b)['sizes'] ?? '', 'big'), 'a synonym shared by two sizes → 422');
[$c, $b] = $save(['sizes' => '[{"key": "a", "name": ']);
ok($c === 422 && isset(fields($b)['sizes']), 'a JSON parse error → 422 naming sizes, with the parser\'s words: ' . mb_substr(fields($b)['sizes'] ?? '', 0, 60));
$queenN = (int) one("SELECT count(*) FROM product_variants WHERE size_key = 'queen'");
$noQueen = array_values(array_filter($sizes, static fn (array $s): bool => $s['key'] !== 'queen'));
[$c, $b] = $save(['sizes' => json_encode($noQueen)]);
ok($queenN >= 1 && $c === 422 && str_contains(fields($b)['sizes'] ?? '', "Size 'queen' is used by $queenN variant"), "removing queen while variants use it → 422 \"used by $queenN variants\"");
[$c, $b] = $save(['attribute_keys' => json_encode([['key' => 'type', 'name' => 'Type', 'kind' => 'choice']])]);
ok($c === 422 && str_contains(fields($b)['attribute_keys'] ?? '', 'choices'), 'attribute_keys with a choice kind and no choices → 422');
[$c, $b] = $save(['attribute_keys' => json_encode([['key' => 'Bad Key', 'name' => 'X', 'kind' => 'text']])]);
ok($c === 422 && isset(fields($b)['attribute_keys']), 'an attribute key that is not lower-case snake → 422');

echo "6. Who may, and what the agent's call is\n";
[$c] = $save(['ack_days' => '4'], $nora);
ok($c === 403, 'Nora (the Buyer, no settings.manage) → 403');
[$c] = $save(['ack_days' => '4'], $sam);
ok($c === 403, 'Sam → 403');
ok(pg($nora, '/admin/settings')[0] === 403, 'and the page too (403)');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok(($reg['settings_save']['approval'] ?? null) === 'other' && ($reg['sequence_set']['approval'] ?? null) === 'other' && ($reg['tax_rate_save']['approval'] ?? null) === 'other', 'the registry carries `other` on settings_save, sequence_set and tax_rate_save (an agent\'s call pauses by default)');
$r = act_raw($owner, '/admin/settings.php', ['ack_days' => '7'], ['HX-Request: true', 'HX-Target: flash']);
ok($r['code'] === 200 && hdr($r, 'HX-Trigger') === 'settingsChanged' && str_contains(stripslashes((string) hdr($r, 'HX-Location')), '/admin/settings'), 'HX-Trigger: settingsChanged, and the location is the settings page');
ok(str_contains(stripslashes((string) hdr($r, 'HX-Location')), 'admin-settings-group-buying'), 'landing at the group that changed (#admin-settings-group-buying)');
$restore();
ok(q('SELECT * FROM inv_settings WHERE id = 1')[0]['units'] === 'imperial', 'the original settings are put back for the proofs after');
finish();
