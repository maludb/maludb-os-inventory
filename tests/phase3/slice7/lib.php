<?php
/**
 * Helpers for the slice 7 proofs (docs/build-specs/feed.md, "Proof"). Run through tests/phase3/slice7/run.sh on the scratch database inv_dev7, the application on :8601 (under Apache :8601 is the PUBLIC vhost —
 * the feed IS on its allow-list —, :8607 the internal one), the fake kernel :8602, the fake MaluDB :8603; the fixture server on :8606 only while the world is built.
 * Builds on slice 5's library (the cast, act(), screen(), order_world() — which builds slice 4's find_world(), slice 3's sources from the fixture server and slice 1's catalog, and the customer SMOKE Alvarez).
 *
 * The world (feed_world()): slice 4's world — the SMOKE Cloudrest Hybrid in six sizes with the fixtures' GTINs, the Queen set (a bundle), the Warehouse with 4 Queens (1 allocated), the Showroom's floor King,
 * Malouf's Shopify store (lead 5) and Zinus' feed (lead 3) as supplier sources, the Casper site as a reference —, plus the price lists **Dealer 20** (20 %) and **Inactive 5** (inactive) and three keys minted through
 * the handler as the admin: **Website** (website), **Partner Store** (partner, Dealer 20), **Sister installation** (installation, rate_per_day 3). Their raw values are kept in $INV_FEED_KEYS (a file — a
 * key can be shown once, so the proofs that follow read it from here, the way a consumer keeps what it was handed).
 */
require dirname(__DIR__) . '/slice5/lib.php';

function feed_keys_file(): string { return (string) getenv('INV_FEED_KEYS'); }
function feed_keys_all(): array { $f = feed_keys_file(); return $f !== '' && is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; }
/** The raw key a proof was handed at the mint (by the name it was given). */
function feed_raw(string $name): string { return (string) (feed_keys_all()[$name]['raw'] ?? ''); }
function feed_key_id(string $name): int { return (int) (feed_keys_all()[$name]['id'] ?? 0); }
function feed_remember(string $name, int $id, string $raw): void { $a = feed_keys_all(); $a[$name] = ['id' => $id, 'raw' => $raw]; file_put_contents(feed_keys_file(), json_encode($a)); chmod(feed_keys_file(), 0666); }
function key_row(int $id): array { return q('SELECT * FROM feed_keys WHERE id = :id', ['id' => $id])[0]; }
function price_list_id(string $name): ?int { $v = one('SELECT id FROM price_lists WHERE lower(name) = lower(:n)', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }

/** A feed call: GET /api/v1/availability?<query> with a Bearer key (null = none). Returns [status, decoded body, the response]. */
function feed_get(string $query, ?string $key, array $o = []): array
{
    $h = $o['headers'] ?? [];
    if ($key !== null) { $h[] = 'Authorization: Bearer ' . $key; }
    $r = req($o['method'] ?? 'GET', '/api/v1/availability' . ($query === '' ? '' : '?' . $query), ['headers' => $h]);
    return [(int) $r['code'], json_decode((string) $r['body'], true) ?? [], $r];
}
/** A key minted through the handler as the admin (JSON mode: the raw key comes back once). Returns [status, body, raw|null, id|null]. */
function mint_key(string $label, array $extra = []): array
{
    [$c, $b, $raw] = act(as_member(1), '/admin/feed-keys/mint.php', ['label' => $label] + $extra);
    return [$c, $b, $b['key'] ?? null, isset($b['record_id']) ? (int) $b['record_id'] : null];
}
/** Wait out the end of a minute so a counted run of calls stays in ONE minute bucket. */
function within_minute(int $needSeconds = 40): void { $s = (int) date('s'); if ($s > 60 - $needSeconds) { sleep(61 - $s); } }

function feed_world(): array
{
    $w = order_world();
    $owner = as_member(1);
    $w['owner'] = $owner;
    $w['nora'] = as_member(40);
    $w['sam'] = as_member(41);
    $w['wes'] = as_member(42);
    $w['vera'] = as_member(43);
    if (price_list_id('Dealer 20') === null) {
        act($owner, '/admin/price-lists/save.php', ['name' => 'Dealer 20', 'percent_off_retail' => '20', 'notes' => 'SMOKE dealer terms', 'active' => 'yes']);
        act($owner, '/admin/price-lists/save.php', ['name' => 'Inactive 5', 'percent_off_retail' => '5', 'active' => 'no']);
    }
    psql_exec("UPDATE product_variants SET ships_how = 'parcel' WHERE id = {$w['queen']}; UPDATE product_variants SET ships_how = 'ltl' WHERE id = {$w['king']}");
    $w['dealer20'] = price_list_id('Dealer 20');
    $w['inactive5'] = price_list_id('Inactive 5');
    if (feed_key_id('website') === 0) {
        [, , $raw, $id] = mint_key('Website', ['consumer_kind' => 'website']);
        feed_remember('website', (int) $id, (string) $raw);
        [, , $raw, $id] = mint_key('Partner Store', ['consumer_kind' => 'partner', 'price_list' => $w['dealer20']]);
        feed_remember('partner', (int) $id, (string) $raw);
        [, , $raw, $id] = mint_key('Sister installation', ['consumer_kind' => 'installation', 'rate_per_day' => '3']);
        feed_remember('sister', (int) $id, (string) $raw);
    }
    $w['k_website'] = feed_key_id('website');
    $w['k_partner'] = feed_key_id('partner');
    $w['k_sister'] = feed_key_id('sister');
    $w['queen_gtin'] = (string) one('SELECT barcode FROM product_variants WHERE id = :v', ['v' => $w['queen']]);
    return $w;
}
