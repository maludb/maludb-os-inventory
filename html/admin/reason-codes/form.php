<?php
declare(strict_types=1);
/** /admin/reason-codes/new and /admin/reason-codes/{id}/edit (screens `reason-code-add`, `reason-code-edit`; the form `reason-code-form`). settings.manage; people only. The code is fixed after creation. */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('reason');
$cur = $id === null ? null : reason_code_or_404($pdo, $id);
$screen = $cur === null ? 'reason-code-add' : 'reason-code-edit';
admin_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['reason_code' => $cur === null ? null : present_reason_code($cur)]);
}
render_screen($cur === null ? 'Add a reason code' : 'Change ' . $cur['name'], view('admin/reason-codes/form.php', ['cur' => $cur]),
    ['activeNav' => 'reason-code-list', 'screen' => $screen, 'entity' => 'reason_code', 'recordId' => $id === null ? '' : (string) $id]);
