<?php
declare(strict_types=1);
/**
 * GET /watches/form?variant=|listing_variant=|product=&return_to= — the inline watch form (find.md): a fragment under a Find card or on a record's
 * page; JavaScript off, a page. watches.own; the target visible through its view.
 */
require_once dirname(__DIR__, 2) . '/app/features/watches/handler.php';
require_right('watches.own');
$pdo = db();
$tkind = null;
foreach (['variant', 'listing_variant', 'product'] as $k) { if (request_integer($k) !== null) { $tkind = $k; break; } }
if ($tkind === null) { refuse(404, 'Variant not found.'); }
$target = watch_target($pdo, $tkind, (int) request_integer($tkind)) ?? refuse(404, ['variant' => 'Variant', 'listing_variant' => 'Listing', 'product' => 'Product'][$tkind] . ' not found.');
$seesCost = sees_cost();
$kinds = array_keys(WATCH_KINDS);
if (!$seesCost) { $kinds = array_values(array_diff($kinds, ['cost_below'])); }
$default = ($target['state'] ?? null) === 'in_stock' ? 'price_below' : 'back_in_stock';
$html = view('watches/partials/watch-form.php', ['target' => $target, 'kinds' => $kinds, 'default' => $default, 'prefill' => ($p = $target['best_price'] ?? $target['retail'] ?? null) === null ? null : number_format((float) $p, 2, '.', ''),
    'agents' => has_right('watches.all') ? agents_for_pick($pdo) : null, 'returnTo' => safe_local_path($_GET['return_to'] ?? null) ?? '/watches/']);
if (is_htmx_request()) { echo $html; return; }
render_screen('Watch ' . $target['label'], view('shared/header.php', ['id' => 'watch-form-page', 'title' => 'Watch', 'crumbs' => [['Home', '/'], ['Watches', '/watches/'], ['Watch', null]]])
    . '<div class="main-content"><div class="card"><div class="card-body">' . $html . '</div></div></div>', ['activeNav' => 'watch-list', 'screen' => 'watch-list', 'entity' => 'watch', 'recordId' => '']);
