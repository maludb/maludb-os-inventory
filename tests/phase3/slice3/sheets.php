<?php
/** Price sheets (sources.md "Proof — Price sheets"): a row by hand, changed inline, removed; the feed's rows show their source; cost walled; logged. */
require __DIR__ . '/lib.php';
$w = sources_world();
$nora = as_member(40);
$since = last_activity_id();
[$c, $b] = act($nora, '/supplier-items/save.php', ['supplier' => $w['dealer'], 'variant' => $w['fnd_queen'], 'supplier_sku' => 'NW-FND-Q', 'cost' => '189.00', 'lead_time_days' => '5', 'moq' => '2']);
$id = (int) ($b['record_id'] ?? 0);
$r = q('SELECT supplier_sku, cost, lead_time_days, moq, source_id, last_seen_at FROM supplier_items WHERE id = :i', ['i' => $id])[0] ?? [];
ok($c === 200 && $r['cost'] === '189.00' && (int) $r['lead_time_days'] === 5 && (int) $r['moq'] === 2 && $r['source_id'] === null && $r['last_seen_at'] === null, 'Nora adds a row by hand (cost 189.00, lead 5, MOQ 2): no source, never "seen"');
$html = req('POST', '/supplier-items/save.php', ['jar' => $nora, 'headers' => ['HX-Request: true', 'HX-Target: supplier-item-row-' . $id], 'form' => ['supplier_item' => $id, 'cost' => '185.50', 'csrf_token' => csrf_of(page($nora, '/')['body'])]])['body'];
ok(str_contains($html, 'id="supplier-item-row-' . $id . '"') && one('SELECT cost FROM supplier_items WHERE id = :i', ['i' => $id]) === '185.50' && (int) one('SELECT moq FROM supplier_items WHERE id = :i', ['i' => $id]) === 2, 'changed inline (Pattern C answers the row); what was left out stays');
$l = last_log('supplier.item_save', $since);
ok($l !== null && after_of($l)['supplier_id'] === $w['dealer'] && after_of($l)['supplier_sku'] === 'NW-FND-Q', 'supplier.item_save logged');
[$c, $d] = screen($nora, '/supplier-items/?supplier=' . $w['dealer']);
$fromFeed = array_values(array_filter($d['items'], static fn ($i) => $i['source_id'] === $w['src_feed']));
ok(count($fromFeed) >= 4 && $fromFeed[0]['source_name'] === 'SMOKE Dealer feed', 'the feed\'s rows show the feed that keeps them');
[$c, $b] = act(as_member(41), '/supplier-items/save.php', ['supplier' => $w['dealer'], 'variant' => $w['pillow_v'], 'cost' => '1.00']);
ok($c === 403, 'Sam (no suppliers.write) may not change a price sheet');
psql_exec("INSERT INTO inv_role_rights (role_key, right_key) VALUES ('user', 'suppliers.write') ON CONFLICT DO NOTHING");
[$c, $b] = act(as_member(41), '/supplier-items/save.php', ['supplier' => $w['dealer'], 'variant' => $w['pillow_v'], 'cost' => '1.00']);
ok($c === 422 && fields($b)['cost'] === 'You may not set a cost — leave cost empty.', 'a writer who does not see cost sending one: refused in words, not dropped');
psql_exec("DELETE FROM inv_role_rights WHERE role_key = 'user' AND right_key = 'suppliers.write'");
[$c, $d] = screen(as_member(43), '/supplier-items/?supplier=' . $w['dealer']);
ok($c === 200 && array_filter(array_column($d['items'], 'cost')) === [] && $d['items'][0]['cost_withheld'] === true, 'Vera reads the sheet with cost withheld');
[$c, $b] = act($nora, '/supplier-items/remove.php', ['supplier_item' => $id]);
ok($c === 200 && one('SELECT count(*) FROM supplier_items WHERE id = :i', ['i' => $id]) === 0 && last_log('supplier.item_remove', $since) !== null, 'removed, logged');
finish();
