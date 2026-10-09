<?php
declare(strict_types=1);
/** Action `source_probe` (log `source.probe`: state, message ≤ 200, http_status, pull_id): can it be read as configured? A `probe` pull row, inline (≤ 3 requests); the ladder applies. The answer renders #source-probe-result. sources.write. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
source_write_begin('sources.write');
$pdo = db();
$s = source_or_404($pdo, request_integer('source') ?? request_integer('source_id'));
$running = one_value($pdo, "SELECT 1 FROM source_pulls WHERE source_id = :s AND status = 'running' AND started_at > now() - interval '6 hours'", ['s' => $s['source_id']]);
if ($running !== null) { refuse(422, 'A pull is running; probe when it finishes.'); }
$res = inv_guard($pdo, static fn (): array => run_probe($pdo, $s['source_id'], (int) current_member_id()));
source_log($pdo, 'source.probe', 'source', $s['source_id'], $s['source_id'], ['state' => $res['state'], 'message' => mb_substr($res['message'], 0, 200), 'http_status' => $res['http_status'], 'pull_id' => $res['pull_id']]);
$facts = probe_facts_public($res['facts']);
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'source-probe-result') {
    emit_action_status(true, ['did' => 'Probed ' . $s['name'] . ': ' . $res['state'], 'record_id' => $res['pull_id']]);
    hx_trigger('sourceChanged');
    echo view('sources/partials/probe-result.php', ['res' => $res + ['facts' => $facts], 's' => $s]);
    exit;
}
inv_done('Probed ' . $s['name'] . ': ' . $res['state'] . ' — ' . $res['message'], $res['pull_id'], inv_land('/sources/' . $s['source_id'], null, 'source-probe-result'), 'sourceChanged',
    ['state' => $res['state'], 'message' => $res['message'], 'facts' => $facts, 'pull_id' => $res['pull_id']]);
