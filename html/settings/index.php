<?php
declare(strict_types=1);
/**
 * /settings/ — the person's own settings (screen `my-settings`): HOW I AM TOLD (email, text, which kinds, which by text), WHO I AM HERE (the badge, the
 * roles held, whether cost shows), MY TIME ZONE (the directory's — changed in the operating system). Texts go to the phone verified in the operating
 * system — this application never sees it; what the kernel last said about it is shown in words. People only.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
$prefs = my_prefs($pdo, $me);
$tz = member_timezone();
$refusal = last_text_refusal($pdo, $me);
$roles = my_roles($pdo, $me);
$badge = role_badge();
log_screen_view($pdo, 'my-settings');
if (wants_json()) {
    respond_screen(['notify' => present_prefs($prefs) + ['text_note' => $refusal], 'kinds' => NOTICE_KINDS, 'timezone' => $tz, 'badge' => $badge,
        'roles' => array_map(static fn (array $r): array => ['role_key' => $r['role_key'], 'name' => $r['name']], $roles), 'sees_cost' => sees_cost(), 'may' => ['settings' => has_right('settings.manage')]]);
}
$notices = ['prefs_saved' => ['success', 'Saved how you are told.']];
render_screen('My settings', view('settings/index.php', ['prefs' => $prefs, 'refusal' => $refusal, 'tz' => $tz, 'roles' => $roles, 'badge' => $badge, 'seesCost' => sees_cost(),
    'may' => ['settings' => has_right('settings.manage')],
    'osChannels' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/settings/channels', 'osProfile' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/settings',
    'notice' => inv_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'my-settings', 'screen' => 'my-settings', 'entity' => 'settings']);
