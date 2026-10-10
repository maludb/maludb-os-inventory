<?php
declare(strict_types=1);

/**
 * Customers (orders.md "Query functions"): the people the business sells to — a record, not a login. Read from mcp_customers (the view nulls email,
 * phone, phone_alt and the addresses for a caller without orders.write — a Viewer sees the name and the status); the open orders and the last order
 * come from mcp_sales_orders. The resolvers of Phase 4 call find_customers() / find_customer().
 */

const CUSTOMER_SOURCES = ['walk_in' => 'Walk-in', 'phone' => 'Phone', 'web' => 'Web', 'referral' => 'Referral', 'other' => 'Other'];
const CUSTOMER_PAGE = 48;
const CUSTOMER_COLUMNS = 'c.customer_id, c.name, c.legal_name, c.email, c.phone, c.phone_alt, c.billing_address, c.shipping_address, c.tax_id, c.terms_days, c.currency, c.income_account_id,
    c.tax_rate_id, c.member_id, c.notes, c.source, c.email_opt_in, c.archived_at, c.created_by, c.created_at, c.updated_at, c.order_count,
    (SELECT count(*) FROM mcp_sales_orders o WHERE o.customer_id = c.customer_id AND o.status NOT IN (\'closed\', \'cancelled\')) AS open_orders,
    (SELECT max(o.ordered_on) FROM mcp_sales_orders o WHERE o.customer_id = c.customer_id AND o.status <> \'cancelled\') AS last_order_on,
    (SELECT t.name FROM mcp_tax_rates t WHERE t.tax_rate_id = c.tax_rate_id) AS tax_rate_name';

function customer_row_decode(array $c): array
{
    $c['customer_id'] = (int) $c['customer_id'];
    foreach (['tax_rate_id', 'member_id', 'income_account_id', 'terms_days', 'created_by'] as $k) {
        $c[$k] = $c[$k] === null ? null : (int) $c[$k];
    }
    $c['email_opt_in'] = (bool) $c['email_opt_in'];
    $c['order_count'] = (int) ($c['order_count'] ?? 0);
    $c['open_orders'] = (int) ($c['open_orders'] ?? 0);
    return $c;
}

function customers_where(array $filters, array &$args): string
{
    $sql = '';
    if (empty($filters['archived'])) {
        $sql .= ' AND c.archived_at IS NULL';
    }
    $src = (string) ($filters['source'] ?? '');
    if ($src !== '' && isset(CUSTOMER_SOURCES[$src])) {
        $sql .= ' AND c.source = :src';
        $args['src'] = $src;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $sql .= ' AND (c.name ILIKE :qlike OR similarity(c.name, :q) > 0.3 OR lower(c.email) = lower(:q) OR c.phone = :q OR c.phone_alt = :q'
            . (strlen($digits) >= 7 ? ' OR regexp_replace(COALESCE(c.phone, \'\'), \'\\D\', \'\', \'g\') = :digits OR regexp_replace(COALESCE(c.phone_alt, \'\'), \'\\D\', \'\', \'g\') = :digits' : '') . ')';
        $args['q'] = $q;
        $args['qlike'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        if (strlen($digits) >= 7) {
            $args['digits'] = $digits;
        }
    }
    return $sql;
}

/** Cards: [filters q, source, archived] → customers with open orders and last order, name order. */
function find_customers(PDO $pdo, array $filters, int $limit = CUSTOMER_PAGE, int $offset = 0): array
{
    $args = [];
    $where = customers_where($filters, $args);
    $st = $pdo->prepare('SELECT ' . CUSTOMER_COLUMNS . ' FROM mcp_customers c WHERE true' . $where . ' ORDER BY lower(c.name), c.customer_id LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('customer_row_decode', $st->fetchAll());
}

function count_customers(PDO $pdo, array $filters): int
{
    $args = [];
    $where = customers_where($filters, $args);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_customers c WHERE true' . $where);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

function find_customer(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . CUSTOMER_COLUMNS . ' FROM mcp_customers c WHERE c.customer_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : customer_row_decode($r);
}

/** The live customer with this email (an agent resolving a name and an email), or null. A caller without orders.write cannot match on an email (the view nulls it). */
function find_customer_by_email(PDO $pdo, string $email): ?array
{
    $st = $pdo->prepare('SELECT ' . CUSTOMER_COLUMNS . ' FROM mcp_customers c WHERE lower(c.email) = lower(:e) AND c.archived_at IS NULL ORDER BY c.customer_id LIMIT 1');
    $st->execute(['e' => trim($email)]);
    $r = $st->fetch();
    return $r === false ? null : customer_row_decode($r);
}

/** The live customer with this name (case-insensitive), or null. */
function find_customer_by_name(PDO $pdo, string $name): ?array
{
    $st = $pdo->prepare('SELECT ' . CUSTOMER_COLUMNS . ' FROM mcp_customers c WHERE lower(c.name) = lower(:n) AND c.archived_at IS NULL ORDER BY c.customer_id LIMIT 1');
    $st->execute(['n' => trim($name)]);
    $r = $st->fetch();
    return $r === false ? null : customer_row_decode($r);
}

/** Orders not closed or cancelled — quotes count (a quote is cancelled first). */
function customer_open_orders(PDO $pdo, int $customerId): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM mcp_sales_orders WHERE customer_id = :c AND status NOT IN ('closed', 'cancelled')", ['c' => $customerId]);
}

/** Every order of any status (the delete check). */
function customer_any_orders(PDO $pdo, int $customerId): int
{
    return (int) one_value($pdo, 'SELECT count(*) FROM mcp_sales_orders WHERE customer_id = :c', ['c' => $customerId]);
}

/** The ship-to a new order takes from the customer (what the trigger would copy): ship_to_name, ship_to_address1, ship_to_phone (the view nulls the last two for a Viewer). */
function customer_ship_to(PDO $pdo, int $customerId): array
{
    $c = find_customer($pdo, $customerId);
    if ($c === null) {
        return [];
    }
    return ['ship_to_name' => $c['name'], 'ship_to_address1' => $c['shipping_address'], 'ship_to_phone' => $c['phone']];
}

/** The customer's returns (the records; the screens are slice 8's). */
function customer_returns(PDO $pdo, int $customerId): array
{
    $st = $pdo->prepare('SELECT return_id, number, sales_order_id, order_number, status, refund_amount, created_at FROM mcp_return_authorizations WHERE customer_id = :c ORDER BY created_at DESC');
    $st->execute(['c' => $customerId]);
    return $st->fetchAll();
}

/** The live tax rates: [{tax_rate_id, name, rate, is_default}]. */
function tax_rates_live(PDO $pdo): array
{
    return $pdo->query('SELECT tax_rate_id, name, rate, is_default FROM mcp_tax_rates WHERE archived_at IS NULL ORDER BY is_default DESC, name')->fetchAll();
}

/** Live customers for a pick (the record picker): name order, a query on the name or the email. [{customer_id, name, email}] */
function customers_for_pick(PDO $pdo, string $q = '', int $limit = 100): array
{
    $args = [];
    $where = customers_where(['q' => $q], $args);
    $st = $pdo->prepare('SELECT c.customer_id, c.name, c.email, c.phone FROM mcp_customers c WHERE true' . $where . ' ORDER BY lower(c.name) LIMIT ' . max(1, min(500, $limit)));
    $st->execute($args);
    return $st->fetchAll();
}
