<?php
declare(strict_types=1);
/**
 * Action `prefs_save` (log `prefs.save`): a person's own notification choices — email_enabled, text_enabled (yes/no), kinds[] (the events to be told
 * about), text_kinds[] (the events to be texted about). The manifest names `timezone` too: it is the directory's and is refused here in words.
 * A field left out stays as it was. Both channels off is allowed: that person is told nothing but in the app. Always about oneself (an agent may
 * save its own). Location /settings/; refresh prefsChanged.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
inv_handler_begin();
$pdo = db();
$me = (int) current_member_id();
if (req_has('timezone') && (string) req_val('timezone') !== '' && (string) req_val('timezone') !== member_timezone()) {
    inv_refuse_fields(['timezone' => 'The time zone is the directory\'s: change it in the operating system and it follows within a minute.']);
}
$f = [];
foreach (['email_enabled', 'text_enabled'] as $k) {
    if (req_has($k)) {
        $f[$k] = inv_yes($k);
    }
}
foreach (['kinds', 'text_kinds'] as $k) {
    $list = request_list($k);
    if ($list === null) {
        continue;
    }
    $bad = array_diff($list, array_keys(NOTICE_KINDS));
    if ($bad !== []) {
        inv_refuse_fields([$k => 'Unknown event: ' . implode(', ', array_map(static fn (string $x): string => mb_substr($x, 0, 30), $bad)) . '.']);
    }
    $f[$k] = $list;
}
$r = inv_guard($pdo, static function () use ($pdo, $me, $f): array {
    $pdo->beginTransaction();
    $r = save_prefs($pdo, $me, $f);
    log_activity($pdo, 'prefs.save', 'notification_prefs', $me, ['before' => $r['before'], 'after' => $r['after']]);
    $pdo->commit();
    return $r;
});
inv_done('Saved how you are told', $me, inv_land(return_path('/settings/'), 'prefs_saved'), 'prefsChanged',
    ['email_enabled' => $r['prefs']['email_enabled'], 'text_enabled' => $r['prefs']['text_enabled'], 'kinds' => $r['prefs']['kinds'], 'text_kinds' => $r['prefs']['text_kinds']]);
