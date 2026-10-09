<?php
declare(strict_types=1);
/** Action `source_schedule_set` (log `source.schedule_set`: before/after schedule_minutes, rate_per_second; an agent's pauses — other): `schedule_minutes` (0 = manual), `rate_per_second` (capped by the policy). sources.write. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
source_write_begin('sources.write');
$pdo = db();
$s = source_or_404($pdo, request_integer('source') ?? request_integer('source_id'));
$errors = [];
$m = inv_int('schedule_minutes', null, 0, 525600, 'The schedule', $errors);
if ($m === null && !isset($errors['schedule_minutes'])) { $errors['schedule_minutes'] = 'Give the schedule in minutes (0 = manual).'; }
$rate = null;
if (req_has('rate_per_second') && (string) req_val('rate_per_second') !== '') {
    $v = (string) req_val('rate_per_second');
    if (!is_numeric($v) || (float) $v < 0.1 || (float) $v > 10) { $errors['rate_per_second'] = 'The rate is between 0.1 and 10 requests a second.'; } else { $rate = round((float) $v, 2); }
}
if ($errors !== []) { inv_refuse_fields($errors); }
$res = inv_guard($pdo, static function () use ($pdo, $s, $m, $rate): array {
    $pdo->beginTransaction();
    $res = set_schedule($pdo, $s['source_id'], $m, $rate);
    source_log($pdo, 'source.schedule_set', 'source', $s['source_id'], $s['source_id'], $res['after'], ['before' => $res['before']]);
    $pdo->commit();
    return $res;
});
inv_done('Set the schedule of ' . $s['name'] . ' to ' . schedule_words($res['after']['schedule_minutes']), $s['source_id'], inv_land('/sources/' . $s['source_id'], 'schedule'), 'sourceChanged', $res['after']);
