<?php
declare(strict_types=1);
/**
 * Actions `source_create` (no `source`; `template` to adopt one — connectors.md §8) and `source_update` (`source`; a field left out stays) — logs
 * `source.create` / `source.update` with source_id and settings_keys, never a mapping's rows. sources.write; an agent's create pauses (other).
 * A change of base_url, settings or user-agent resumes the source. Location /sources/{id}; refresh sourceChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
source_write_begin('sources.write');
$pdo = db();
$id = request_integer('source') ?? request_integer('source_id');
$cur = $id === null ? null : source_or_404($pdo, $id);
$me = (int) current_member_id();
$tpl = null;
if ($cur === null && req_has('template') && (string) req_val('template') !== '') {
    $tpl = find_template($pdo, (string) req_val('template')) ?? refuse(422, 'That template is not here.');
    foreach (['name' => $tpl['name'], 'connector' => $tpl['connector'], 'role' => $tpl['role'], 'base_url' => $tpl['base_url']] as $k => $v) {
        if ((!req_has($k) || (string) req_val($k) === '') && $v !== null) { $_POST[$k] = (string) $v; }     // every field the caller gives wins
    }
}
$errors = [];
$f = source_from_request($pdo, $cur, $errors);
$base = $cur !== null ? source_settings_raw($pdo, $cur['source_id']) : ($tpl !== null ? adopt_template_settings($tpl) : []);
$settings = isset($errors['connector']) ? [] : source_settings_from_request($f['connector'], $base, $errors);
if ($errors === [] && $f['connector'] === 'feed') { $errors += feed_mapping_errors($settings, $id, $pdo); }
if ($errors !== []) { inv_refuse_fields($errors); }
[$res, $brandId] = inv_guard($pdo, static function () use ($pdo, $id, $cur, $f, $settings, $me, $tpl, $base): array {
    $pdo->beginTransaction();
    $brandId = $tpl !== null ? adopt_template($pdo, $tpl, [], $me)['brand_id'] : null;
    $res = save_source($pdo, $id, $f, $settings, $me);
    $after = source_loggable($f, $settings);
    if ($cur === null) {
        source_log($pdo, 'source.create', 'source', $res['id'], $res['id'], $after + ['template' => $tpl['key'] ?? null, 'brand_id' => $brandId]);
    } else {
        $prior = ['name' => $cur['name'], 'connector' => $cur['connector'], 'role' => $cur['role'], 'supplier_id' => $cur['supplier_id'], 'base_url' => $cur['base_url'], 'schedule_minutes' => $cur['schedule_minutes'],
                  'rate_per_second' => $cur['rate_per_second'], 'user_agent' => null, 'active' => $cur['active']];
        $d = inv_diff(array_diff_key(source_loggable($prior, $base), ['settings_keys' => 1, 'user_agent_set' => 1]), array_diff_key($after, ['settings_keys' => 1, 'user_agent_set' => 1]));
        $changed = array_values(array_unique(array_merge(array_keys(array_diff_key($settings, $base)), array_keys(array_diff_key($base, $settings)),
            array_keys(array_filter($settings, static fn ($v, $k) => array_key_exists($k, $base) && $base[$k] != $v, ARRAY_FILTER_USE_BOTH)))));
        source_log($pdo, 'source.update', 'source', $res['id'], $res['id'], $d['after'] + ['name' => $f['name'], 'settings_changed' => $changed, 'settings_keys' => array_keys($settings), 'resumed' => $res['resumed']], ['before' => $d['before']]);
    }
    $pdo->commit();
    return [$res, $brandId];
});
$land = $cur === null && inv_yes('then_credential', false) ? '/sources/' . $res['id'] . '/credential' : return_path('/sources/' . $res['id']);
inv_done(($cur === null ? 'Made the source ' : 'Saved ') . $f['name'], $res['id'], inv_land($land, $cur === null ? 'created' : 'saved'), 'sourceChanged', ['source_id' => $res['id'], 'resumed' => $res['resumed'], 'brand_id' => $brandId]);
