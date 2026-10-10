<?php
/**
 * Helpers for the slice 9 proofs (docs/build-specs/reports-admin.md, "Proof"). Run through tests/phase3/slice9/run.sh on the scratch database inv_dev9, the application on :8601 (under Apache :8601 is the PUBLIC
 * vhost, :8607 the internal one), the fake kernel :8602, the fake MaluDB :8603 and — once the world is built — the fake MaluMail on :8606.
 * admin_world() composes the worlds of slices 1–8 (slice 8's returns_world() builds slice 6's, 5's, 4's, 3's and 1's in turn) and adds what slice 9 reads: Sam's open orders at every step (a quote, a deposit owed, a late one,
 * a drop-ship waiting on its supplier), an `analyst` role that holds reports.read and not the cost wall (Omar, member 46 — the one person below the wall who may run a report), and the Stock Buyer's name. Idempotent.
 */
require dirname(__DIR__) . '/slice8/lib.php';

const OMAR = 46;

/** A signed-on jar for the people of the fixture, by name. */
function who(string $name): string
{
    static $omar = null;
    if ($name === 'omar') {                      // the analyst's claims (the fixture grants Omar nothing): the kernel would send roles [analyst]
        if ($omar === null) { [$omar] = sign_on(OMAR, ['claims' => ['member_id' => OMAR, 'application_id' => 60, 'capability' => 'read', 'role_key' => 'analyst', 'roles' => ['analyst'], 'rights' => [], 'scope_id' => null, 'scopes' => []]]); }
        return $omar;
    }
    return as_member(['owner' => 1, 'nora' => 40, 'sam' => 41, 'wes' => 42, 'vera' => 43, 'ann' => 44, 'omar' => OMAR][$name]);
}

function admin_world(): array
{
    $w = returns_world();
    // an `analyst`: reports.read and nothing else (no cost.read) — the cost wall's other side
    if (one("SELECT 1 FROM inv_roles WHERE role_key = 'analyst'") === false) {
        psql_exec("INSERT INTO inv_roles (role_key, name, description, capability, is_admin, sort_order) VALUES ('analyst', 'Analyst', 'SMOKE: runs the reports, never sees cost', 'read', false, 9);"
            . " INSERT INTO inv_role_rights (role_key, right_key) VALUES ('analyst', 'inventory.read'), ('analyst', 'reports.read');"
            . " UPDATE members SET roles = '{analyst}', capability = 'read', status = 'active' WHERE id = " . OMAR);
    }
    $sam = $w['sam'];
    if (ref_order('S9-QUOTE') === null) {
        psql_exec("SELECT inv_post_txn('receipt', {$w['queen']}, {$w['wh']}, 4, 450.00, 'opening', 91, 'smoke-s9-q4', 'none', NULL, NULL, NULL, 1)");          // four more Queens at the warehouse to sell
        make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => 'S9-QUOTE', 'delivery_method' => 'delivery']);
        // late: confirmed with a deposit, promised two days ago
        [, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => 'S9-LATE', 'delivery_method' => 'delivery', 'promised_on' => date('Y-m-d', strtotime('+10 days'))]);
        $late = (int) $b['record_id'];
        act($sam, '/orders/confirm.php', ['order' => $late]);
        act($sam, '/orders/payments/save.php', ['order' => $late, 'kind' => 'deposit', 'amount' => '200.00', 'method' => 'card']);
        psql_exec("UPDATE sales_orders SET promised_on = current_date - 2 WHERE id = $late");
        // a drop-ship waiting on Zinus: confirmed, a deposit, the purchase order sent (the line is `ordered`)
        [, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]], ['customer_reference' => 'S9-WAIT', 'delivery_method' => 'parcel', 'promised_on' => date('Y-m-d', strtotime('+20 days'))]);
        $wait = (int) $b['record_id'];
        act($sam, '/orders/confirm.php', ['order' => $wait]);
        act($sam, '/orders/payments/save.php', ['order' => $wait, 'kind' => 'deposit', 'amount' => '300.00', 'method' => 'card']);
        $po = po_for($wait, $w['zinus']);
        if ($po !== null) { psql_exec("SELECT inv_po_send($po, 40, 'email')"); }
    }
    $w['s9_quote'] = ref_order('S9-QUOTE');
    $w['s9_late'] = ref_order('S9-LATE');
    $w['s9_wait'] = ref_order('S9-WAIT');
    return $w;
}

/** A write over HTTP as a signed-on person WITHOUT asking for JSON (a download, a results partial): the raw request result (code, headers, body). */
function act_raw(string $jar, string $path, array $form, array $headers = []): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    return req('POST', $path, ['jar' => $jar, 'headers' => $headers, 'form' => $form + ['csrf_token' => $tok[$jar]]]);
}
/** One header's value from a raw response (null when absent). */
function hdr(array $r, string $name): ?string { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', (string) $r['headers'], $m) ? $m[1] : null; }
/** A page as the browser gets it: [status, html]. */
function pg(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar]); return [(int) $r['code'], (string) $r['body']]; }
/** The text of one element id in a page (tags stripped, spaces folded) — '' when it is not there. */
function text_of(string $html, string $id): string
{
    $d = new DOMDocument();
    @$d->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $e = $d->getElementById($id);
    return $e === null ? '' : trim((string) preg_replace('/\s+/', ' ', $e->textContent));
}
function has_id(string $html, string $id): bool { return str_contains($html, 'id="' . $id . '"'); }
/** The href of an element id (a link) — null when absent. */
function href_of(string $html, string $id): ?string { return preg_match('/<a [^>]*href="([^"]*)"[^>]*id="' . preg_quote($id, '/') . '"|<a [^>]*id="' . preg_quote($id, '/') . '"[^>]*href="([^"]*)"/', $html, $m) ? html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? '')) : null; }
