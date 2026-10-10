<?php
declare(strict_types=1);
/** /admin/reason-codes/ — the reason codes in sort order (screen `reason-code-list`): code, name, where it applies, whether it moves quantity, active. settings.manage. */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
require_right('settings.manage');
$pdo = db();
$rows = find_reason_codes($pdo, true);
admin_screen_view($pdo, 'reason-code-list');
if (wants_json()) {
    respond_screen(['reason_codes' => array_map('present_reason_code', $rows)]);
}
render_screen('Reason codes', view('admin/reason-codes/index.php', ['rows' => $rows, 'notice' => inv_notice($_GET['notice'] ?? null, ['created' => ['success', 'Added the reason code.'], 'saved' => ['success', 'Saved the reason code.']])]),
    ['activeNav' => 'reason-code-list', 'screen' => 'reason-code-list', 'entity' => 'reason_code']);
