<?php
declare(strict_types=1);
/**
 * /admin/sequences — the seven document sequences (screen `sequence-list`, GET) and action `sequence_set` (POST; log `sequence.set`: the kind, before and after): kind, prefix (^[A-Z][A-Z0-9]{0,7}-?$), next_value (never below what
 * was issued — a number is never reused), padding (1–12). One UPDATE. sequences.manage; `other` for an agent; a confirm in the browser.
 */
require_once dirname(__DIR__, 2) . '/app/features/admin/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    inv_handler_begin();
    require_right('sequences.manage');
    $me = (int) current_member_id();
    $kind = request_string('kind');
    if (!isset(SEQUENCE_KINDS[$kind])) { inv_refuse_fields(['kind' => 'Kind is one of ' . implode(', ', array_keys(SEQUENCE_KINDS)) . '.']); }
    $cur = array_values(array_filter(find_sequences($pdo), static fn (array $r): bool => $r['kind'] === $kind))[0] ?? throw new RuntimeException('The sequence is missing.');
    $errors = [];
    $fields = sequence_from_request($cur, $errors);
    if ($errors !== []) { inv_refuse_fields($errors); }
    $d = inv_guard($pdo, static function () use ($pdo, $me, $kind, $fields): array {
        $pdo->beginTransaction();
        $d = set_sequence($pdo, $kind, $fields, $me);
        if ($d['after'] !== []) { log_activity($pdo, 'sequence.set', 'sequence', null, ['before' => $d['before'], 'after' => $d['after'] + ['kind' => $kind]]); }
        $pdo->commit();
        return $d;
    });
    $now = array_values(array_filter(find_sequences($pdo), static fn (array $r): bool => $r['kind'] === $kind))[0];
    inv_done($d['after'] === [] ? 'Nothing to change in the ' . $kind . ' sequence' : 'Set the ' . $kind . ' sequence — the next number reads ' . $now['next_number'], $kind, inv_land('/admin/sequences', 'saved', 'sequence-row-' . $kind), 'settingsChanged', ['next_number' => $now['next_number']]);
}
require_right('sequences.manage');
$rows = find_sequences($pdo);
admin_screen_view($pdo, 'sequence-list');
if (wants_json()) {
    respond_screen(['sequences' => array_map('present_sequence', $rows)]);
}
render_screen('Sequences', view('admin/sequences.php', ['rows' => $rows, 'tz' => member_timezone(), 'notice' => inv_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the sequence.']])]),
    ['activeNav' => 'sequence-list', 'screen' => 'sequence-list', 'entity' => 'sequence']);
