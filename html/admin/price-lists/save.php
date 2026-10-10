<?php
declare(strict_types=1);
/**
 * Actions `price_list_save` (log `price_list.save`, with the fields changed): a named percentage off retail that a partner key answers with. `price_list` names the one to change (a field left out stays); a new row
 * without it. Name 1–80 and unique ignoring case; percent 0–99.99 with two decimals; making a list inactive makes its keys' partner price the retail price. feed.keys. Location /admin/price-lists/#price-list-row-{id}.
 */
require_once dirname(__DIR__, 3) . '/app/features/feed/handler.php';
inv_handler_begin();
require_right('feed.keys');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('price_list') ?? request_integer('price_list_id');
$cur = $id === null ? null : price_list_or_404($pdo, $id);
$errors = [];
$f = price_list_from_request($cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$newId = inv_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_price_list($pdo, $id, $f, $me);
    $now = find_price_list($pdo, $newId) ?? throw new RuntimeException('The price list was not found after it was saved.');
    if ($cur === null) {
        log_activity($pdo, 'price_list.save', 'price_list', $newId, ['after' => price_list_loggable($now) + ['changed' => array_keys(price_list_loggable($now))]]);
    } else {
        $d = inv_diff(price_list_loggable($cur), price_list_loggable($now));
        $changed = array_keys($d['after']);
        if (($cur['notes'] ?? null) !== ($now['notes'] ?? null)) { $changed[] = 'notes'; }       // the notes' words are not logged — that they changed is
        if ($changed !== []) { log_activity($pdo, 'price_list.save', 'price_list', $newId, ['before' => $d['before'], 'after' => $d['after'] + ['name' => $now['name'], 'changed' => $changed]]); }
    }
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Made the price list ' : 'Saved ') . $f['name'], $newId, inv_land('/admin/price-lists/', $id === null ? 'created' : 'saved', 'price-list-row-' . $newId), 'feedChanged', ['price_list_id' => $newId]);
