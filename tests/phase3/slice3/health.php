<?php
/** Health and templates (sources.md "Proof — Health and templates"): inv_source_health() across the sources; the cards and the sentences agree; the next due time; the templates. */
require __DIR__ . '/lib.php';
$w = sources_world();
$nora = as_member(40);
psql_exec("UPDATE sources SET paused_at = NULL, paused_reason = NULL, consecutive_failures = 0, backoff_until = NULL WHERE id = {$w['src_walled']}");
psql_exec("UPDATE sources SET last_ok_at = now() - interval '3 days' WHERE id = {$w['src_woo']}");
psql_exec("UPDATE sources SET consecutive_failures = 1, backoff_until = now() + interval '1 hour' WHERE id = {$w['src_feed']}");
psql_exec("UPDATE sources SET robots_state = 'blocked' WHERE id = {$w['src_walled']}");
[$c, $b] = act($nora, '/sources/save.php', ['connector' => 'woocommerce', 'name' => 'SMOKE Never pulled', 'base_url' => FIX]);
$never = (int) $b['record_id'];
pdo()->exec("SELECT set_config('app.member_id', '40', false)");
$h = [];
foreach (pdo()->query('SELECT source_id, health FROM inv_source_health()')->fetchAll() as $r) { $h[(int) $r['source_id']] = $r['health']; }
ok(($h[$w['src_shopify']] ?? '') === 'ok', 'the Shopify store: ok');
ok(($h[$w['src_woo']] ?? '') === 'stale', 'the Woo store, last ok 3 days ago: stale');
ok(($h[$w['src_feed']] ?? '') === 'failing', 'the feed with a failure: failing');
ok(($h[$w['src_walled']] ?? '') === 'blocked', 'the walled store: blocked');
ok(($h[$w['src_manual']] ?? '') === 'manual', 'the price sheet: manual');
ok(($h[$never] ?? '') === 'never_pulled', 'a new source: never_pulled');
$mal = source_id('Malouf Home (maloufhome.com)');
ok(($h[$mal] ?? '') === 'paused', 'the template\'s source, paused: paused');
[$c, $d] = screen($nora, '/sources/');
$by = array_column($d['sources'], null, 'source_id');
ok($by[$w['src_woo']]['health'] === 'stale' && str_starts_with($by[$w['src_woo']]['health_sentence'], 'stale — last ok 3 days ago') && str_starts_with($by[$w['src_feed']]['health_sentence'], 'failing · 1 in a row · backing off until'), 'the cards\' chips and sentences agree with it');
ok($by[$w['src_shopify']]['next_due_at'] !== null && $by[$w['src_manual']]['next_due_at'] === null, 'the next due time (none for a manual source)');
[$c, $d] = screen($nora, '/sources/templates');
ok($c === 200 && count($d['templates']) === 18 && count(array_filter($d['templates'], static fn ($t) => $t['survey_result'] === 'unverified')) === 18, 'the templates screen lists the eighteen seeded, every one unverified');
ok(str_contains(page($nora, '/sources/templates')['body'], 'id="template-finding"'), '…under the survey\'s finding');
act(as_member(1), '/sources/delete.php', ['source' => $never]);
psql_exec("UPDATE sources SET consecutive_failures = 0, backoff_until = NULL WHERE id = {$w['src_feed']}; UPDATE sources SET robots_state = 'unknown', paused_at = now(), paused_reason = 'a wall' WHERE id = {$w['src_walled']}");
finish();
