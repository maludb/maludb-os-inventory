<?php
declare(strict_types=1);
/**
 * Action `tax_rate_save` (log `tax_rate.save`: the name, the rate, the default, the fields changed): add a tax rate or change one (`tax_rate` names it). The name is unique among the live rates (ignoring case); the rate a percent
 * 0–99.9999; making a rate the default clears the previous default in the same transaction before the write; an archived rate cannot be edited. settings.manage; `other` for an agent. Location /admin/tax-rates/#tax-rate-row-{id}.
 */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
inv_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('tax_rate') ?? request_integer('tax_rate_id');
$cur = $id === null ? null : tax_rate_or_404($pdo, $id);
$errors = [];
$f = tax_rate_from_request($cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$newId = inv_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_tax_rate($pdo, $id, $f, $me);
    $now = find_tax_rate($pdo, $newId) ?? throw new RuntimeException('The tax rate was not found after it was saved.');
    if ($cur === null) {
        log_activity($pdo, 'tax_rate.save', 'tax_rate', $newId, ['after' => tax_rate_loggable($now) + ['changed' => array_keys(tax_rate_loggable($now))]]);
    } else {
        $d = inv_diff(tax_rate_loggable($cur), tax_rate_loggable($now));
        if ($d['after'] !== []) { log_activity($pdo, 'tax_rate.save', 'tax_rate', $newId, ['before' => $d['before'], 'after' => $d['after'] + ['name' => $now['name'], 'changed' => array_keys($d['after'])]]); }
    }
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Added the tax rate ' : 'Saved ') . $f['name'], $newId, inv_land('/admin/tax-rates/', $id === null ? 'created' : 'saved', 'tax-rate-row-' . $newId), 'settingsChanged', ['tax_rate_id' => $newId]);
