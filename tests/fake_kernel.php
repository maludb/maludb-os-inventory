<?php
/**
 * A FAKE Business OS kernel for the proofs — `php -S 127.0.0.1:8292 tests/fake_kernel.php` — answering the few internal
 * endpoints Inventory calls, from the JSON state file $FAKE_KERNEL_STATE (the proofs rewrite it between steps):
 *   GET  /api/v1/directory/changes.php  → state.feed (os.directory-changes/1); ?since= answers state.incremental when set, else an empty change
 *   POST /api/v1/runs/facts.php {token} → state.facts[<run id>] (the run token's third part), else {valid: false}
 *   POST /api/v1/agents/chat.php        → state.chat (a canned reply; state.chat_http = 202 with {finished: false} for a run still going; {approval_request_id} for a paused write; state.chat_by_agent[<agent>] for one agent)
 *                                         or the refusal / failure state.chat_status names (400, 403, 404, 409, 422, 500), echoing the acting member it saw; every request is appended to "<state file>.chat"
 *   GET  /api/v1/agents/chat.php?run=N  → state.chat_runs[N] (the body of a run the POST answered 202 for), 404 when none; state.chat_get_status refuses it
 *   GET  /api/v1/ledger/periods.php     → A5, from state.ledger: no ?period= answers os.ledger-periods/1 (state.ledger.periods — the months and their status); ?period=YYYY-MM answers
 *                                         state.ledger.docs[<period>] (an os.ledger-period/1 document, as the kernel sends it), a 422 for a month it has none of; state.ledger.mode = down answers 503;
 *                                         every request is appended to "<state file>.ledger" (the period asked, or "list")
 *   POST /api/v1/notify/sms.php         → K6, as state.sms says (mode = ok | no_sender | not_held | no_verified_phone | opted_out | rate_limited | server_error);
 *                                         every request body is appended to "<state file>.sms" (one JSON line each)
 * Every call needs Authorization: Bearer $OS_APPLICATION_TOKEN (else 401), like the real one. Never a real kernel.
 */
$state = json_decode((string) @file_get_contents((string) getenv('FAKE_KERNEL_STATE')), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$out = static function (array $body, int $status = 200): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($body); exit; };
if ($auth !== 'Bearer ' . getenv('OS_APPLICATION_TOKEN')) { $out(['error' => ['code' => 'unauthorized', 'message' => 'A valid application token is required.']], 401); }
$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
switch ($path) {
    case '/api/v1/ledger/periods.php':
        $ledger = $state['ledger'] ?? ['periods' => [], 'docs' => []];
        file_put_contents((string) getenv('FAKE_KERNEL_STATE') . '.ledger', ($_GET['period'] ?? 'list') . "\n", FILE_APPEND);
        if (($ledger['mode'] ?? '') === 'down') { $out(['error' => ['code' => 'unavailable', 'message' => 'The ledger is down.']], 503); }
        if (!isset($_GET['period'])) { $out(['schema' => 'os.ledger-periods/1', 'periods' => $ledger['periods'] ?? []]); }
        if (!isset($ledger['docs'][$_GET['period']])) { $out(['error' => ['code' => 'invalid', 'message' => 'period is written YYYY-MM.']], 422); }
        $out($ledger['docs'][$_GET['period']]);
    case '/api/v1/notify/sms.php':
        $sms = $state['sms'] ?? ['mode' => 'ok'];
        $log = (string) getenv('FAKE_KERNEL_STATE') . '.sms';
        $member = (int) ($input['member_id'] ?? 0);
        $mode = $sms['members'][(string) $member] ?? ($sms['mode'] ?? 'ok');
        if (($input['text'] ?? '') === '' || mb_strlen((string) ($input['text'] ?? '')) > 480) { $mode = 'invalid'; }
        file_put_contents($log, json_encode($input + ['accepted' => $mode === 'ok', 'mode' => $mode]) . "\n", FILE_APPEND);
        $refuse = static fn (string $code, int $status) => $out(['error' => ['code' => $code, 'message' => 'Refused: ' . $code]], $status);
        switch ($mode) {
            case 'ok':           $out(['notification' => ['id' => 9000 + substr_count((string) @file_get_contents($log), "\n"), 'status' => 'queued']], 202);
            case 'no_sender':    $refuse('no_sender', 503);
            case 'server_error': $refuse('server_error', 500);
            default:             $refuse($mode, 422);
        }
    case '/api/v1/directory/changes.php':
        if (isset($_GET['since']) && isset($state['incremental'])) { $out($state['incremental']); }
        if (isset($_GET['since'])) { $out(['schema' => 'os.directory-changes/1', 'since' => $_GET['since'], 'next' => $state['feed']['next'] ?? $_GET['since'], 'full' => false,
                                          'members' => [], 'departments' => [], 'memberships' => [], 'deleted_departments' => [], 'scopes' => [], 'access' => []]); }
        $out($state['feed'] ?? []);
    case '/api/v1/runs/facts.php':
        $parts = explode('.', (string) ($input['token'] ?? ''));
        $run = $parts[2] ?? '';
        $out($state['facts'][$run] ?? ['valid' => false]);
    case '/api/v1/agents/chat.php':
        $log = (string) getenv('FAKE_KERNEL_STATE') . '.chat';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
        file_put_contents($log, json_encode(['method' => $method, 'run' => $_GET['run'] ?? null, 'agent' => $_GET['agent'] ?? null, 'acting' => $_SERVER['HTTP_X_ACTING_MEMBER'] ?? null, 'utterance' => $input['utterance'] ?? null,
            'conversation_id' => $input['conversation_id'] ?? null, 'screen' => $input['screen'] ?? null, 'context' => $input['context'] ?? null, 'wait' => $input['wait'] ?? null]) . "\n", FILE_APPEND);
        if ($method === 'GET' && isset($_GET['run'])) {                                    // a run the POST answered 202 for: state.chat_runs[<run id>] is its body (404 when the proof set none)
            $body = ($state['chat_runs'] ?? [])[(string) $_GET['run']] ?? null;
            if (isset($state['chat_get_status'])) { $out(['error' => ['code' => 'refused', 'message' => 'The run cannot be read.']], (int) $state['chat_get_status']); }
            if ($body === null) { $out(['error' => ['code' => 'not_found', 'message' => 'No such run.']], 404); }
            $out($body);
        }
        if (isset($state['chat_status'])) {                                               // state.chat_status: the refusal (400, 403, 404, 409, 422) or the failure (500) the POST answers
            $messages = [400 => 'No acting member.', 403 => 'This person may not use the expert.', 404 => 'No expert for this application.', 409 => 'The expert is busy with another turn.', 422 => 'Say what you want.', 500 => 'The kernel failed.'];
            $out(['error' => ['code' => 'refused', 'message' => $state['chat_message'] ?? ($messages[(int) $state['chat_status']] ?? 'Refused.')]], (int) $state['chat_status']);
        }
        // state.chat: the canned body (finished, or {finished: false, status: running} with state.chat_http = 202; {approval_request_id: N} for a paused write); state.chat_by_agent[<agent>] overrides it for one agent
        $chat = ($state['chat_by_agent'][(string) ($_GET['agent'] ?? '')] ?? null) ?? ($state['chat'] ?? ['run_id' => 1, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Hello from the fake expert', 'actions' => []]);
        $out($chat + ['seen_acting_member' => $_SERVER['HTTP_X_ACTING_MEMBER'] ?? null, 'seen_agent' => $_GET['agent'] ?? null, 'seen_utterance' => $input['utterance'] ?? null], (int) ($state['chat_http'] ?? 200));
}
$out(['error' => ['code' => 'not_found', 'message' => 'No such internal endpoint.']], 404);
