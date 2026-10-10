<?php
declare(strict_types=1);
/** /admin/price-lists/new and /admin/price-lists/{id}/edit (screens `price-list-add`, `price-list-edit`; the form `price-list-form`). feed.keys; people only. */
require_once dirname(__DIR__, 3) . '/app/features/feed/handler.php';
require_right('feed.keys');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('price_list');
$cur = $id === null ? null : price_list_or_404($pdo, $id);
$screen = $cur === null ? 'price-list-add' : 'price-list-edit';
feed_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['price_list' => $cur === null ? null : present_price_list($cur)]);
}
render_screen($cur === null ? 'Add a price list' : 'Change ' . $cur['name'], view('admin/price-lists/form.php', ['cur' => $cur]),
    ['activeNav' => 'price-list-list', 'screen' => $screen, 'entity' => 'price_list', 'recordId' => $id === null ? '' : (string) $id]);
