<?php
declare(strict_types=1);
/**
 * GET /s/<48 hex> — screen `supplier-door`: the supplier's purchase-order page — and its three POSTs, /s/<token>/acknowledge | decline | tracking (NOT actions: source `portal`, no member). A token is the authority: no login, no acting member, no view
 * of the mcp_* family — the page reads the base tables as the writer (app/features/public/purchase_order.php). One dead page (404) for every dead token; 429 over the limits; `noindex`; opening it is logged `purchase_order.supplier_view`
 * (source portal, no actor). A POST re-resolves the token, checks the CSRF token of the anonymous session (a missing or wrong one is a 403 with the page), ignores a filled honeypot (`website`), runs the verb with source `portal` and re-renders the page
 * with a notice. Plain HTML, no script.
 */
$GLOBALS['__public_door'] = 'portal';
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/purchasing/handler.php';
require_once dirname(__DIR__) . '/app/features/public/purchase_order.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$pdo = db();
$settings = one_row($pdo, 'SELECT business_name, business_contact_email, business_phone FROM inv_settings WHERE id = 1') ?? [];
$token = (string) ($_GET['token'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$do = (string) ($_GET['do'] ?? '');
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method Not Allowed');
}
$po = public_purchase_order($token);
if ($po === null) {
    http_response_code(404);
    echo view('public/dead-link.php', ['settings' => $settings, 'title' => 'This link has expired', 'message' => 'This link has expired. Ask the business for a new one.']);
    exit;
}
$poId = (int) $po['purchase_order_id'];
$flash = null;
if ($method === 'POST') {
    $actions = ['acknowledge' => 'door_acknowledge', 'decline' => 'door_decline', 'tracking' => 'door_tracking'];
    if (!isset($actions[$do])) {
        http_response_code(404);
        echo view('public/dead-link.php', ['settings' => $settings, 'title' => 'Not found', 'message' => 'There is nothing at this address.']);
        exit;
    }
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $flash = ['danger', 'The form expired — reload the page.'];
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        $flash = null;                                                  // the honeypot: a robot filled the field a person cannot see — 200, the page unchanged, nothing written, nothing logged
    } elseif (!door_post_rate_ok($pdo, $poId, client_ip())) {
        http_response_code(429);
        header('Retry-After: 300');
        echo view('public/dead-link.php', ['settings' => $settings, 'title' => 'Slow down', 'message' => 'Too many requests — try again in a few minutes.']);
        exit;
    } elseif (!door_open($po)) {
        http_response_code(422);
        $flash = ['danger', 'This order is ' . strtolower(supplier_status_word($po['status'])) . '; nothing more to do here.'];
    } else {
        $r = $actions[$do]($pdo, $poId, $_POST, $po);
        $flash = [$r['ok'] ? 'success' : 'danger', $r['message']];
        if (!$r['ok']) { http_response_code((int) $r['status']); }
        $po = public_purchase_order($token) ?? $po;                      // the page as it is now (and a view counted for the re-render is accepted)
    }
    echo view('public/purchase-order.php', ['po' => $po, 'flash' => $flash]);
    exit;
}
if (!door_view_rate_ok($pdo, $poId, client_ip())) {
    http_response_code(429);
    header('Retry-After: 300');
    echo view('public/dead-link.php', ['settings' => $settings, 'title' => 'Slow down', 'message' => 'Too many requests — try again in a few minutes.']);
    exit;
}
door_log($pdo, 'purchase_order.supplier_view', $po, ['view_count' => $po['link']['view_count'] === null ? null : (int) $po['link']['view_count']]);
echo view('public/purchase-order.php', ['po' => $po, 'flash' => null]);
