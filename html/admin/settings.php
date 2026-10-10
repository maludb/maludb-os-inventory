<?php
declare(strict_types=1);
/**
 * /admin/settings — one form over inv_settings in seven groups (screen `admin-settings`, GET) and action `settings_save` (POST; log `settings.save` with the fields changed — `crawl_user_agent` as "changed", sizes and attribute keys as a
 * count and the keys added or removed). Any field of the settings: a field left out stays as it was. A removed size key a variant uses is refused. settings.manage; `other` for an agent (it pauses by default).
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    inv_handler_begin();
    require_right('settings.manage');
    $me = (int) current_member_id();
    $cur = find_settings($pdo);
    $errors = [];
    $fields = settings_from_request($pdo, $cur, $errors);
    if ($errors !== []) { inv_refuse_fields($errors); }
    $res = inv_guard($pdo, static function () use ($pdo, $me, $fields): array {
        $pdo->beginTransaction();
        $res = save_settings($pdo, $fields, $me);
        if ($res['changed'] !== []) { log_activity($pdo, 'settings.save', 'settings', 1, settings_loggable($res)); }
        $pdo->commit();
        return $res;
    });
    $did = $res['changed'] === [] ? 'Nothing to change in the settings' : 'Saved the settings (' . implode(', ', $res['changed']) . ')';
    inv_done($did, 1, inv_land('/admin/settings', 'saved', 'admin-settings-group-' . settings_group_of($res['changed'][0] ?? 'business')), 'settingsChanged', ['changed' => $res['changed']]);
}
require_right('settings.manage');
$s = find_settings($pdo);
admin_screen_view($pdo, 'admin-settings');
if (wants_json()) {
    respond_screen(['settings' => present_settings($s), 'groups' => array_map(static fn (array $g): array => ['title' => $g[0], 'fields' => $g[2]], settings_groups())]);
}
render_screen('Settings', view('admin/settings.php', ['s' => $s, 'groups' => settings_groups(), 'inUse' => size_keys_in_use($pdo), 'shipsHow' => ships_how_kinds($pdo), 'buyers' => buyer_candidates($pdo),
    'timezones' => DateTimeZone::listIdentifiers(), 'tz' => member_timezone(), 'notice' => inv_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the settings.']])]),
    ['activeNav' => 'admin-settings', 'screen' => 'admin-settings', 'entity' => 'settings']);
