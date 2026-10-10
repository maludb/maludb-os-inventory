<?php
/**
 * Helpers for the slice 8 proofs (docs/build-specs/returns-worker.md, "Proof"). Run through tests/phase3/slice8/run.sh on the scratch database inv_dev8, the application on :8601 (under Apache :8601 is the PUBLIC
 * vhost, :8607 the internal one), the fake kernel :8602 (the chat endpoint's and K6's states), the fake MaluDB :8603 and — once the world is built — THE FAKE MALUMAIL on :8606 (FAKE_MALUMAIL_LOG records each send).
 * Builds on slice 6's library (the cast, act(), screen(), order_world(), purchasing_world() — which build slice 4's world, slice 3's sources from the fixture server and slice 1's catalog).
 *
 * The world (returns_world()): slice 6's world, the agent SMOKE Buyer (member 47, the `buyer` role), and
 *   **SO-1** (tag S8-SO1: SMOKE Alvarez, Sam the salesperson) confirmed and SHIPPED: line 1 a stock Queen (shipped 1 by Wes), line 2 a drop-ship King (Zinus) shipped by the supplier's tracking;
 *   **SO-2** (S8-SO2: a stock Queen) shipped, delivered, paid in full and closed;
 *   a watch of Sam's (back_in_stock on the Zinus King offer, text_me, agent = the expert 45) and of Nora's (price_below 300, no agent);
 *   a feed key with 40-day-old usage buckets and an order link expired yesterday (in SQL).
 */
require dirname(__DIR__) . '/slice6/lib.php';

const EXPERT = 45;
const BUYER_AGENT = 47;

/** A worker pass or several (CLI, the scratch env, optional extra env): the JSON report's `ran` (errors, exit code and the whole report under `_`). */
function wk(string $passes, array $env = [], string $extra = ''): array
{
    $e = '';
    foreach ($env as $k => $v) { $e .= $k . '=' . escapeshellarg((string) $v) . ' '; }
    $out = [];
    $code = 0;
    exec($e . 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . escapeshellarg($passes) . ' ' . $extra . ' 2>/dev/null', $out, $code);
    $last = json_decode((string) end($out), true);
    return is_array($last) ? ($last['ran'] ?? []) + ['_' => $last + ['exit' => $code]] : ['_' => ['exit' => $code, 'raw' => implode("\n", $out)]];
}
/** The clock a pass reads (INV_WORKER_NOW) $minutes from now. */
function later(int $minutes): array { return ['INV_WORKER_NOW' => gmdate('c', time() + $minutes * 60)]; }
function return_id_of(string $number): ?int { $v = one('SELECT id FROM return_authorizations WHERE number = :n', ['n' => $number]); return $v === false || $v === null ? null : (int) $v; }
function return_row(int $id): array { return q('SELECT * FROM return_authorizations WHERE id = :id', ['id' => $id])[0]; }
function return_lines_of(int $id): array { return q('SELECT rl.*, v.sku FROM return_lines rl JOIN product_variants v ON v.id = rl.variant_id WHERE rl.return_id = :id ORDER BY rl.id', ['id' => $id]); }
function outbox_rows(int $since = 0): array { return q('SELECT * FROM notification_outbox WHERE id > :s ORDER BY id', ['s' => $since]); }
function last_outbox_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM notification_outbox'); }
function sms_log(): array { $f = need('FAKE_KERNEL_STATE') . '.sms'; return is_file($f) ? array_values(array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f))))) : []; }
function chat_log(): array { $f = need('FAKE_KERNEL_STATE') . '.chat'; return is_file($f) ? array_values(array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f))))) : []; }
function dispatch_rows(int $since = 0): array { return q('SELECT * FROM agent_dispatches WHERE id > :s ORDER BY id', ['s' => $since]); }
function last_dispatch_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM agent_dispatches'); }
/** The fake kernel's chat/sms states: set (merge) or clear a key. */
function kstate(array $set, array $unset = []): void { kernel_state(function ($s) use ($set, $unset) { foreach ($unset as $k) { unset($s[$k]); } return $set + $s; }); }
/** Reset what the passes read: the chat and sms states, the logs. */
function kernel_clean(): void { kernel_state(function ($s) { unset($s['chat'], $s['chat_status'], $s['chat_http'], $s['chat_runs'], $s['chat_message'], $s['chat_get_status'], $s['chat_by_agent'], $s['sms']); return $s; }); foreach (['.chat', '.sms'] as $x) { @unlink(need('FAKE_KERNEL_STATE') . $x); } }
/** Mail log lines for an address since the log had $n lines. */
function mail_to(string $address, int $since = 0): array { return array_values(array_filter(array_slice(mail_log(), $since), static fn ($m) => ($m['to'] ?? '') === $address)); }
/** A PNG of $w × $h written to a temp file. */
function png(int $w = 640, int $h = 480): string { $p = sys_get_temp_dir() . '/inv-s8-' . getmypid() . "-$w.png"; $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, 0x3454d1); imagepng($im, $p); return $p; }
/** The shipped line of an order by SKU. */
function sol_of(int $order, string $sku): array { return q('SELECT l.* FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = :o AND v.sku = :s ORDER BY l.line_no', ['o' => $order, 's' => $sku])[0]; }

function returns_world(): array
{
    $w = purchasing_world();
    $nora = $w['nora']; $sam = $w['sam']; $wes = $w['wes']; $owner = $w['owner'];
    if (one('SELECT 1 FROM members WHERE id = :m', ['m' => BUYER_AGENT]) === false) {
        psql_exec("INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles) VALUES (" . BUYER_AGENT . ", 'agent', 'SMOKE Buyer', NULL, 'user', 'active', 'write', '{buyer}')");
    }
    if (ref_order('S8-SO1') === null) {
        // SO-1: a stock Queen and a drop-ship King, confirmed; the Queen shipped by Wes, the King shipped by the supplier
        [, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']], ['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]],
            ['customer_reference' => 'S8-SO1', 'delivery_method' => 'delivery']);
        $so1 = (int) $b['record_id'];
        act($sam, '/orders/confirm.php', ['order' => $so1]);
        act($sam, '/orders/payments/save.php', ['order' => $so1, 'kind' => 'deposit', 'amount' => '2000.00', 'method' => 'card']);
        $ql = sol_of($so1, 'SMOKE-NW-CR-Q');
        act($wes, '/orders/ship.php', ['order' => $so1, 'kind' => 'own_delivery', 'lines' => [$ql['id'] => ['ship' => '1', 'qty' => '1']]]);
        $kl = sol_of($so1, 'SMOKE-NW-CR-K');
        $po = po_for($so1, $w['zinus']);
        psql_exec("SELECT inv_po_send($po, 40, 'email')");                                    // the world is built while :8606 is the fixture server: the SQL's verb, no e-mail
        $pol = po_lines_of($po)[0];
        act($nora, '/purchasing/tracking.php', ['line' => $pol['id'], 'carrier' => 'UPS', 'tracking' => '1Z999AA10123456785']);
        // SO-2: a stock Queen, shipped, delivered, paid in full, closed
        [, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => 'S8-SO2', 'delivery_method' => 'delivery']);
        $so2 = (int) $b['record_id'];
        act($sam, '/orders/confirm.php', ['order' => $so2]);
        $l2 = sol_of($so2, 'SMOKE-NW-CR-Q');
        act($wes, '/orders/ship.php', ['order' => $so2, 'kind' => 'own_delivery', 'lines' => [$l2['id'] => ['ship' => '1', 'qty' => '1']]]);
        act($wes, '/orders/deliver.php', ['order' => $so2]);
        $due = (string) one('SELECT total - amount_paid FROM sales_orders WHERE id = :o', ['o' => $so2]);
        act($sam, '/orders/payments/save.php', ['order' => $so2, 'kind' => 'balance', 'amount' => $due, 'method' => 'transfer']);
        act($sam, '/orders/close.php', ['order' => $so2]);
        // the watches
        act($sam, '/watches/save.php', ['kind' => 'back_in_stock', 'listing_variant' => $w['lv_zinus_k'], 'text_me' => '1', 'note' => 'SMOKE s8 Sam']);
        psql_exec("UPDATE watches SET agent_member_id = " . EXPERT . " WHERE member_id = 41 AND note = 'SMOKE s8 Sam'");
        act($nora, '/watches/save.php', ['kind' => 'price_below', 'listing_variant' => $w['lv_malouf_ck'], 'threshold' => '300', 'note' => 'SMOKE s8 Nora']);
        // the feed key with old usage, the expired link
        psql_exec("INSERT INTO feed_keys (member_id, label, token_hash, consumer_kind) VALUES (1, 'S8 key', md5(random()::text), 'website')");
        $kid = (int) one("SELECT id FROM feed_keys WHERE label = 'S8 key'");
        psql_exec("INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES ($kid, 'day', date_trunc('day', now()) - interval '40 days', 7), ($kid, 'day', date_trunc('day', now()), 3), ($kid, 'minute', date_trunc('minute', now()) - interval '3 hours', 2), ($kid, 'minute', date_trunc('minute', now()), 1) ON CONFLICT DO NOTHING");
    }
    $w['so1'] = ref_order('S8-SO1');
    $w['so2'] = ref_order('S8-SO2');
    $w['agent'] = BUYER_AGENT;
    $w['watch_sam'] = (int) one("SELECT id FROM watches WHERE note = 'SMOKE s8 Sam'");
    $w['watch_nora'] = (int) one("SELECT id FROM watches WHERE note = 'SMOKE s8 Nora'");
    return $w;
}

/** The application's own function as a member: decoded {result} / {error}. $member 0 = no one (as a worker pass). */
function call_fn(int $member, string $fn, array $args = []): array
{
    $out = shell_exec('php ' . escapeshellarg(__DIR__ . '/call.php') . ' ' . $member . ' ' . escapeshellarg($fn) . ' ' . escapeshellarg(json_encode($args)) . ' 2>/dev/null');
    return json_decode((string) $out, true) ?? ['error' => 'no answer: ' . $out];
}
