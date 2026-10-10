<?php
declare(strict_types=1);
/** /admin/price-lists/ — the partner price lists (screen `price-list-list`): name, percent off retail, active, the keys using each. feed.keys. */
require_once dirname(__DIR__, 3) . '/app/features/feed/handler.php';
require_right('feed.keys');
$pdo = db();
$rows = find_price_lists($pdo);
feed_screen_view($pdo, 'price-list-list');
if (wants_json()) {
    respond_screen(['price_lists' => array_map('present_price_list', $rows)]);
}
render_screen('Price lists', view('admin/price-lists/index.php', ['rows' => $rows, 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, PRICE_LIST_NOTICES)]),
    ['activeNav' => 'price-list-list', 'screen' => 'price-list-list', 'entity' => 'price_list']);
