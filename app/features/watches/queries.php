<?php
declare(strict_types=1);

/** Watches' reads (find.md "Query functions"): the list through mcp_watches (own, or everyone's for watches.all — the view decides) and a target through its view. */

const WATCH_KINDS = ['back_in_stock' => ['Back in stock', 'success'], 'price_below' => ['Price below', 'info'], 'cost_below' => ['Cost below', 'info'],
                     'map_breach' => ['MAP breach', 'danger'], 'lead_time_over' => ['Lead time over', 'warning'], 'removed' => ['Removed', 'dark']];
const WATCH_THRESHOLD_KINDS = ['price_below', 'cost_below', 'lead_time_over'];
const WATCH_PAGE = 100;

/** The list's WHERE over mcp_watches w. */
function watch_where(array $f, array &$p): string
{
    $w = ['true'];
    if (($f['kind'] ?? '') !== '' && isset(WATCH_KINDS[$f['kind']])) { $w[] = 'w.kind = :kind'; $p['kind'] = $f['kind']; }
    if (($f['fired'] ?? '') === 'fired') { $w[] = 'w.fire_count > 0'; }
    if (($f['fired'] ?? '') === 'never') { $w[] = 'w.fire_count = 0'; }
    if (($f['member'] ?? null) !== null) { $w[] = 'w.member_id = :member'; $p['member'] = (int) $f['member']; }
    if (empty($f['cleared'])) { $w[] = 'w.active'; }
    return implode(' AND ', $w);
}

const WATCH_SELECT = "SELECT w.*, a.display_name AS agent_name, pv.sku AS variant_sku, pv.product_name AS variant_product, lv.listing_title, lv.title AS lv_title, lv.source_name, lv.source_id AS lv_source_id, p.name AS product_name
                        FROM mcp_watches w LEFT JOIN mcp_members a ON a.member_id = w.agent_member_id LEFT JOIN mcp_product_variants pv ON pv.variant_id = w.variant_id
                        LEFT JOIN mcp_listing_variants lv ON lv.listing_variant_id = w.listing_variant_id LEFT JOIN mcp_products p ON p.product_id = w.product_id";

function find_watches(PDO $pdo, array $filters, int $limit = WATCH_PAGE, int $offset = 0): array
{
    $p = [];
    $st = $pdo->prepare(WATCH_SELECT . ' WHERE ' . watch_where($filters, $p) . ' ORDER BY w.active DESC, w.fired_at DESC NULLS LAST, w.created_at DESC, w.watch_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    $st->execute($p);
    return array_map('watch_decode', $st->fetchAll());
}

function count_watches(PDO $pdo, array $filters): int
{
    $p = [];
    $st = $pdo->prepare('SELECT count(*) FROM mcp_watches w WHERE ' . watch_where($filters, $p));
    $st->execute($p);
    return (int) $st->fetchColumn();
}

function find_watch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(WATCH_SELECT . ' WHERE w.watch_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : watch_decode($r);
}

/** A watch row with its target's label and link. */
function watch_decode(array $w): array
{
    foreach (['watch_id', 'member_id', 'fire_count'] as $k) { $w[$k] = (int) $w[$k]; }
    foreach (['agent_member_id', 'variant_id', 'listing_variant_id', 'product_id', 'lv_source_id'] as $k) { $w[$k] = $w[$k] === null ? null : (int) $w[$k]; }
    foreach (['text_me', 'active'] as $k) { $w[$k] = (bool) $w[$k]; }
    $w['last_state'] = $w['last_state'] === null ? null : (bool) $w['last_state'];
    [$w['target_label'], $w['target_url']] = match (true) {
        $w['variant_id'] !== null => [trim(($w['variant_sku'] ?? '') . ' — ' . ($w['variant_product'] ?? ''), ' —'), '/variants/' . $w['variant_id']],
        $w['listing_variant_id'] !== null => [trim(($w['listing_title'] ?? '') . ($w['lv_title'] && $w['lv_title'] !== $w['listing_title'] ? ' — ' . $w['lv_title'] : '') . ' · ' . ($w['source_name'] ?? ''), ' ·'), '/listings/' . ($w['listing_variant_id'])],
        default => [(string) ($w['product_name'] ?? ''), '/products/' . $w['product_id']],
    };
    return $w;
}

/**
 * A watch's target through its view (the caller's wall): ['kind', 'id', 'label', 'state', 'best_price', 'retail', 'source_id'] or null when not
 * visible. $kind: variant | listing_variant | product.
 */
function watch_target(PDO $pdo, string $kind, int $id): ?array
{
    if ($kind === 'variant') {
        $r = one_row($pdo, 'SELECT variant_id, sku, product_name, retail_price FROM mcp_product_variants WHERE variant_id = :id', ['id' => $id]);
        if ($r === null) { return null; }
        $a = json_decode((string) one_value($pdo, 'SELECT inv_availability(:v)', ['v' => $id]), true) ?: [];
        $best = null;
        foreach ($a['offers'] ?? [] as $o) { if (empty($o['removed']) && $o['price'] !== null) { $best = $o['price']; break; } }
        return ['kind' => 'variant', 'id' => $id, 'label' => $r['sku'] . ' — ' . $r['product_name'], 'state' => $a['state'] ?? null, 'best_price' => $best, 'retail' => $r['retail_price'], 'source_id' => null];
    }
    if ($kind === 'listing_variant') {
        $r = one_row($pdo, 'SELECT listing_variant_id, listing_title, title, source_name, source_id, price, availability, removed_at FROM mcp_listing_variants WHERE listing_variant_id = :id', ['id' => $id]);
        if ($r === null) { return null; }
        return ['kind' => 'listing_variant', 'id' => $id, 'label' => $r['listing_title'] . ($r['title'] && $r['title'] !== $r['listing_title'] ? ' — ' . $r['title'] : '') . ' · ' . $r['source_name'],
                'state' => $r['removed_at'] === null && $r['availability'] === 'in_stock' ? 'in_stock' : 'other', 'best_price' => $r['price'], 'retail' => null, 'source_id' => (int) $r['source_id']];
    }
    if ($kind === 'product') {
        $r = one_row($pdo, 'SELECT product_id, name FROM mcp_products WHERE product_id = :id', ['id' => $id]);
        if ($r === null) { return null; }
        return ['kind' => 'product', 'id' => $id, 'label' => $r['name'], 'state' => null, 'best_price' => null, 'retail' => null, 'source_id' => null];
    }
    return null;
}

/** An active watch of this member with the same kind, target and threshold (the schema has no unique index — find.md's dedupe). */
function watch_exists(PDO $pdo, int $memberId, string $kind, array $target, ?string $threshold): ?int
{
    $col = ['variant' => 'variant_id', 'listing_variant' => 'listing_variant_id', 'product' => 'product_id'][$target['kind']];
    $st = $pdo->prepare("SELECT id FROM watches WHERE active AND member_id = :m AND kind = :k AND {$col} = :t AND threshold IS NOT DISTINCT FROM CAST(:th AS numeric) ORDER BY id LIMIT 1");
    $st->execute(['m' => $memberId, 'k' => $kind, 't' => $target['id'], 'th' => $threshold]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** The agents a watch can name: members_for_pick() kept to agents. */
function agents_for_pick(PDO $pdo): array
{
    return array_values(array_filter(members_for_pick($pdo), static fn ($m) => $m['member_kind'] === 'agent'));
}

/**
 * The worker's pass and the proof (find.md): t0, inv_fire_watches(), then the watches fired since t0 with their notification, whether a text row
 * was queued for this fire's dedupe key, and the dispatch id. [{watch_id, kind, member_id, target, source_id, notified, texted, dispatched}]
 */
function fire_watches(PDO $pdo): array
{
    $t0 = (string) $pdo->query('SELECT clock_timestamp()')->fetchColumn();
    $pdo->query('SELECT inv_fire_watches()');
    $st = $pdo->prepare("SELECT w.id AS watch_id, w.kind, w.member_id, w.fire_count, w.listing_variant_id,
                                COALESCE((SELECT pv.sku FROM product_variants pv WHERE pv.id = w.variant_id), (SELECT l.title FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE lv.id = w.listing_variant_id),
                                         (SELECT p.name FROM products p WHERE p.id = w.product_id)) AS target,
                                (SELECT l.source_id FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE lv.id = w.listing_variant_id) AS source_id,
                                (SELECT n.id FROM notifications n WHERE n.record_type = 'watch' AND n.record_id = w.id AND n.created_at >= CAST(:t0n AS timestamptz) ORDER BY n.id DESC LIMIT 1) AS notified,
                                EXISTS (SELECT 1 FROM notification_outbox o WHERE o.dedupe_key = 'watch:' || w.id || ':' || w.fire_count || ':text') AS texted,
                                (SELECT d.id FROM agent_dispatches d WHERE d.watch_id = w.id AND d.created_at >= CAST(:t0 AS timestamptz) ORDER BY d.id DESC LIMIT 1) AS dispatched
                           FROM watches w WHERE w.fired_at >= CAST(:t0b AS timestamptz) ORDER BY w.id");
    $st->execute(['t0' => $t0, 't0b' => $t0, 't0n' => $t0]);
    return array_map(static fn ($r) => ['watch_id' => (int) $r['watch_id'], 'kind' => $r['kind'], 'member_id' => (int) $r['member_id'], 'fire_count' => (int) $r['fire_count'], 'target' => $r['target'],
        'source_id' => $r['source_id'] === null ? null : (int) $r['source_id'], 'notified' => $r['notified'] === null ? null : (int) $r['notified'], 'texted' => (bool) $r['texted'],
        'dispatched' => $r['dispatched'] === null ? null : (int) $r['dispatched']], $st->fetchAll());
}
