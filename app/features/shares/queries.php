<?php
declare(strict_types=1);

/**
 * The five shares' readers (feed.md "The five shares' readers"): one SELECT of db/016's inv_share_<name>() each, the jsonb decoded, NOTHING added and NOTHING removed — the document is the function's. The functions carry cost
 * unnulled and take no caller into account (they are for the kernel's token and, here, for the exports): PHP calls them only from a handler that has already gated (`exports.all`, or `reports.read` with sees_cost() — slice 9's
 * rule; Phase 4's records server for the kernel). They are never reachable from a screen a Viewer opens. A refusal (a period over 92 days, a limit) is the function's check_violation — the caller's inv_guard() makes it a 422.
 */

function share_document(PDO $pdo, string $sql, array $args): array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return json_decode((string) $st->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
}

/** os.inventory-sales/1 — orders closed in [from, to] (at most 92 days), paged over the orders (limit ≤ 500). */
function share_sales_closed(PDO $pdo, string $from, string $to, int $offset = 0, int $limit = 200): array
{
    return share_document($pdo, 'SELECT inv_share_sales_closed(CAST(:f AS date), CAST(:t AS date), :o, :l)', ['f' => $from, 't' => $to, 'o' => $offset, 'l' => $limit]);
}

/** os.inventory-purchases/1 — posted receipts and delivered drop-ships in [from, to], by supplier, at cost. */
function share_purchases_received(PDO $pdo, string $from, string $to, int $offset = 0, int $limit = 200): array
{
    return share_document($pdo, 'SELECT inv_share_purchases_received(CAST(:f AS date), CAST(:t AS date), :o, :l)', ['f' => $from, 't' => $to, 'o' => $offset, 'l' => $limit]);
}

/** os.inventory-valuation/1 — stock at cost as of a date, by location or brand. */
function share_stock_valuation(PDO $pdo, string $asOf, string $by = 'location'): array
{
    return share_document($pdo, 'SELECT inv_share_stock_valuation(CAST(:d AS date), :by)', ['d' => $asOf, 'by' => $by]);
}

/** os.inventory-availability/1 — $query is ['q' => …, 'size' => …] | ['gtin' => …] | ['sku' => …]; at most 100. No quantity, no partner price, no source. */
function share_availability_index(PDO $pdo, array $query, int $limit = 25): array
{
    return share_document($pdo, 'SELECT inv_share_availability_index(CAST(:q AS jsonb), :l)', ['q' => json_encode($query, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'l' => $limit]);
}

/** os.inventory-orders/1 — a customer's orders by their email (exact); `found` says whether we know them. No address, phone, cost, source or token. */
function share_customer_orders(PDO $pdo, string $email, bool $openOnly = true, int $limit = 25): array
{
    return share_document($pdo, 'SELECT inv_share_customer_orders(CAST(:e AS citext), CAST(:o AS boolean), :l)', ['e' => $email, 'o' => $openOnly ? 'true' : 'false', 'l' => $limit]);
}
