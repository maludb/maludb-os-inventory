<?php
declare(strict_types=1);
/** /admin/feed-keys/new — mint a feed key (screen `feed-key-add`, the form `feed-key-form`). feed.keys; people only (an agent mints through feed_key_mint). The key is shown once on the keys page. */
require_once dirname(__DIR__, 3) . '/app/features/feed/handler.php';
require_right('feed.keys');
require_human();
$pdo = db();
feed_screen_view($pdo, 'feed-key-add');
$defaults = ['rate_per_minute' => (int) one_value($pdo, 'SELECT feed_rate_per_minute FROM inv_settings WHERE id = 1'), 'rate_per_day' => (int) one_value($pdo, 'SELECT feed_rate_per_day FROM inv_settings WHERE id = 1')];
if (wants_json()) {
    respond_screen(['consumer_kinds' => FEED_CONSUMER_KINDS, 'price_lists' => array_map('present_price_list', find_price_lists($pdo, true)), 'defaults' => $defaults]);
}
render_screen('Mint a feed key', view('admin/feed-keys/form.php', ['priceLists' => find_price_lists($pdo, true), 'defaults' => $defaults, 'tomorrow' => date('Y-m-d', time() + 86400)]),
    ['activeNav' => 'feed-key-list', 'screen' => 'feed-key-add', 'entity' => 'feed_key']);
