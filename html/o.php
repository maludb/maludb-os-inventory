<?php
declare(strict_types=1);
/**
 * GET /o/<48 hex> — screen `customer-door`: the customer's order page. A token is the authority — no session, no acting member, no view of the mcp_* family: the page reads the base tables as the writer (app/features/public/order.php).
 * One dead page (404) for every dead token; 429 over the limits; `noindex`; opening it is logged `order.customer_view` with source `portal` and no actor. Read-only.
 */
$GLOBALS['__public_door'] = 'portal';
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/orders/queries.php';
require_once dirname(__DIR__) . '/app/features/orders/present.php';
require_once dirname(__DIR__) . '/app/features/catalog/present.php';
require_once dirname(__DIR__) . '/app/features/public/order.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$settings = one_row(db(), 'SELECT business_name, business_contact_email, business_phone FROM inv_settings WHERE id = 1') ?? [];
$o = public_order((string) ($_GET['token'] ?? ''));
if ($o === null) {
    http_response_code(404);
    echo view('public/dead-link.php', ['settings' => $settings, 'title' => 'This link has expired', 'message' => 'This link has expired. Ask the business for a new one.']);
    exit;
}
$pdo = db();
if (!door_rate_ok($pdo, (int) $o['sales_order_id'], client_ip())) {
    http_response_code(429);
    header('Retry-After: 300');
    echo view('public/dead-link.php', ['settings' => $settings, 'title' => 'Slow down', 'message' => 'Too many requests — try again in a few minutes.']);
    exit;
}
log_activity($pdo, 'order.customer_view', 'sales_order', (int) $o['sales_order_id'], ['actor_member_id' => null, 'source' => 'portal', 'sales_order_id' => (int) $o['sales_order_id'],
    'after' => ['number' => $o['number'], 'link_id' => $o['link']['link_id'] === null ? null : (int) $o['link']['link_id'], 'view_count' => $o['link']['view_count'] === null ? null : (int) $o['link']['view_count']]]);
echo view('public/order.php', ['o' => $o]);
