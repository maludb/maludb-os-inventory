<?php
/** The survey's --record (sources.md "Proof — Survey"): a template pointed at the fixture server records `open`; a host with no template changes nothing. */
require __DIR__ . '/lib.php';
$w = sources_world();
psql_exec("UPDATE source_templates SET base_url = 'http://127.0.0.1:8606' WHERE key = 'zinus'");
$since = last_activity_id();
$out = (string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/source_survey.php') . ' 127.0.0.1:8606 unknown-host.invalid --record --timeout 3 2>&1');
$t = q("SELECT survey_result, surveyed_at FROM source_templates WHERE key = 'zinus'")[0];
ok($t['survey_result'] === 'open' && $t['surveyed_at'] !== null, 'the zinus template, pointed at the fixture store: open, surveyed_at set');
ok(str_contains($out, 'recorded zinus: unverified → open'), 'the tool prints what changed');
$l = q("SELECT * FROM activity_log WHERE action = 'source.update' AND entity_type = 'source_template' AND id > :i", ['i' => $since]);
ok(count($l) === 1 && json_decode($l[0]['after'], true)['survey_result'] === 'open' && $l[0]['source'] === 'cron' && $l[0]['actor_member_id'] === null, 'logged source.update on the template (source cron, no actor)');
ok((int) one("SELECT count(*) FROM source_templates WHERE surveyed_at IS NOT NULL") === 1, 'the unknown host changed nothing');
psql_exec("UPDATE source_templates SET base_url = 'https://www.zinus.com', survey_result = 'unverified', surveyed_at = NULL WHERE key = 'zinus'");
ok(true, 'the template restored');
finish();
