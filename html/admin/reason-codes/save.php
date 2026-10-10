<?php
declare(strict_types=1);
/**
 * Action `reason_code_save` (log `reason_code.save`: the code, the name, where it applies, quantity, active, the fields changed): add a reason code or change one (`reason` names it). A new code is lower-case (from the name when blank) and
 * unique ("That code is already used."); an existing code never changes (a posted `code` is ignored and the answer says so); applies_to ⊆ adjustment · return · transaction, at least one. settings.manage. Location /admin/reason-codes/#reason-code-row-{id}.
 */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
inv_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('reason') ?? request_integer('reason_code') ?? request_integer('reason_code_id');
$cur = $id === null ? null : reason_code_or_404($pdo, $id);
$errors = [];
$notes = [];
$f = reason_code_from_request($cur, $errors, $notes);
if ($errors !== []) { inv_refuse_fields($errors); }
$newId = inv_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_reason_code($pdo, $id, $f, $me);
    $now = find_reason_code($pdo, $newId) ?? throw new RuntimeException('The reason code was not found after it was saved.');
    if ($cur === null) {
        log_activity($pdo, 'reason_code.save', 'reason_code', $newId, ['after' => reason_code_loggable($now) + ['changed' => array_keys(reason_code_loggable($now))]]);
    } else {
        $d = inv_diff(reason_code_loggable($cur), reason_code_loggable($now));
        if ($d['after'] !== []) { log_activity($pdo, 'reason_code.save', 'reason_code', $newId, ['before' => $d['before'], 'after' => $d['after'] + ['code' => $now['code'], 'changed' => array_keys($d['after'])]]); }
    }
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Added the reason code ' : 'Saved ') . $f['name'] . ($notes === [] ? '' : ' (' . implode('; ', $notes) . ')'), $newId, inv_land('/admin/reason-codes/', $id === null ? 'created' : 'saved', 'reason-code-row-' . $newId), 'settingsChanged', ['reason_code_id' => $newId]);
