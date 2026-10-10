<?php
declare(strict_types=1);

/**
 * Suppliers (purchasing.md "Query functions"): the parties the business orders from. Read from mcp_suppliers (the view nulls the account number for a caller without
 * purchasing.write), mcp_supplier_items (cost behind the wall), mcp_purchase_orders (through inv_purchase_orders_open()) and mcp_sources. The price sheet is slice 3's
 * (supplier_items) — read here, never written. Phase 4's resolvers call find_suppliers() / find_supplier().
 */

/** The kinds the form offers; the table also admits the Cidery's words, which are shown as words when they occur. */
const SUPPLIER_KINDS = ['manufacturer' => 'Manufacturer', 'distributor' => 'Distributor', 'wholesaler' => 'Wholesaler', 'marketplace' => 'Marketplace', 'vendor' => 'Vendor', 'other' => 'Other'];
const SUPPLIER_ORDER_METHODS = ['email' => 'Email', 'portal' => "The supplier's portal", 'api' => 'API', 'edi' => 'EDI', 'phone' => 'Phone'];
const SUPPLIER_PAGE = 48;
const SUPPLIER_OPEN_PO_STATUSES = "('draft', 'sent', 'acknowledged', 'partial')";

const SUPPLIER_COLUMNS = "s.supplier_id, s.name, s.kind, s.contact_name, s.email, s.phone, s.address, s.notes, s.active, s.website, s.account_number, s.terms, s.dropships, s.lead_time_days,
    s.order_method, s.order_email, s.portal_url, s.min_order, s.created_at, s.updated_at,
    (SELECT count(*) FROM mcp_purchase_orders po WHERE po.supplier_id = s.supplier_id AND po.status IN ('draft', 'sent', 'acknowledged', 'partial')) AS open_orders,
    (SELECT count(*) FROM mcp_sources so WHERE so.supplier_id = s.supplier_id AND so.active) AS source_count";

function supplier_row_decode(array $s): array
{
    $s['supplier_id'] = (int) $s['supplier_id'];
    $s['active'] = (bool) $s['active'];
    $s['dropships'] = (bool) $s['dropships'];
    $s['lead_time_days'] = $s['lead_time_days'] === null ? null : (int) $s['lead_time_days'];
    $s['open_orders'] = (int) ($s['open_orders'] ?? 0);
    $s['source_count'] = (int) ($s['source_count'] ?? 0);
    return $s;
}

function suppliers_where(array $filters, array &$args): string
{
    $sql = '';
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q === '') {
        $sql .= ' AND s.active';                                       // inactive suppliers are hidden unless the list is searched
    } else {
        $sql .= ' AND (s.name ILIKE :qlike OR s.contact_name ILIKE :qlike OR lower(s.email::text) = lower(:q) OR lower(s.order_email::text) = lower(:q))';
        $args['q'] = $q;
        $args['qlike'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
    if (!empty($filters['dropships'])) {
        $sql .= ' AND s.dropships';
    }
    return $sql;
}

/** Cards: [filters q, dropships] → suppliers with their open purchase orders and sources, name order. */
function find_suppliers(PDO $pdo, array $filters, int $limit = 100, int $offset = 0): array
{
    $args = [];
    $where = suppliers_where($filters, $args);
    $st = $pdo->prepare('SELECT ' . SUPPLIER_COLUMNS . ' FROM mcp_suppliers s WHERE true' . $where . ' ORDER BY lower(s.name), s.supplier_id LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('supplier_row_decode', $st->fetchAll());
}

function count_suppliers(PDO $pdo, array $filters): int
{
    $args = [];
    $where = suppliers_where($filters, $args);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_suppliers s WHERE true' . $where);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

function find_supplier(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . SUPPLIER_COLUMNS . ' FROM mcp_suppliers s WHERE s.supplier_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : supplier_row_decode($r);
}

/** Purchase orders a supplier still has open (draft, sent, acknowledged, partial). */
function supplier_open_order_count(PDO $pdo, int $id): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM mcp_purchase_orders WHERE supplier_id = :s AND status IN ('draft', 'sent', 'acknowledged', 'partial')", ['s' => $id]);
}

/** The supplier's price sheet (mcp_supplier_items — cost behind the wall; slice 3's screen edits it). */
function supplier_items_for(PDO $pdo, int $supplierId, int $limit = 200): array
{
    $st = $pdo->prepare('SELECT si.supplier_item_id, si.variant_id, si.sku, v.product_name, v.size_name, si.supplier_sku, si.cost, si.cost_withheld, si.lead_time_days, si.moq, si.active, si.last_seen_at
                           FROM mcp_supplier_items si JOIN mcp_product_variants v ON v.variant_id = si.variant_id
                          WHERE si.supplier_id = :s ORDER BY si.active DESC, si.sku LIMIT ' . max(1, min(500, $limit)));
    $st->execute(['s' => $supplierId]);
    return $st->fetchAll();
}

/** inv_purchase_orders_open() kept to the supplier (what the Buyer watches: awaiting acknowledgment, overdue). */
function supplier_open_orders(PDO $pdo, int $supplierId): array
{
    $st = $pdo->prepare('SELECT * FROM inv_purchase_orders_open() WHERE supplier_id = :s ORDER BY expected_on NULLS LAST, number');
    $st->execute(['s' => $supplierId]);
    return $st->fetchAll();
}

/** inv_lead_time_actuals(id)'s row — null for a caller without reports.read (the function refuses; the refusal is caught) or when there is no shipped line yet. */
function supplier_lead_times(PDO $pdo, int $supplierId): ?array
{
    if (!has_right('reports.read')) { return null; }
    try {
        $st = $pdo->prepare('SELECT * FROM inv_lead_time_actuals(:s)');
        $st->execute(['s' => $supplierId]);
        $r = $st->fetch();
    } catch (PDOException) {
        return null;
    }
    return $r === false ? null : $r;
}

/** The last lines the supplier shipped: PO, SKU, expected, shipped, days late (negative = early). */
function supplier_shipped_lines(PDO $pdo, int $supplierId, int $limit = 20): array
{
    $st = $pdo->prepare("SELECT pl.purchase_order_line_id, pl.purchase_order_id, po.number AS purchase_order_number, pl.line_no, pl.sku, pl.expected_on, pl.shipped_at,
                                CASE WHEN pl.expected_on IS NOT NULL AND pl.shipped_at IS NOT NULL THEN (pl.shipped_at::date - pl.expected_on) END AS days_late
                           FROM mcp_purchase_order_lines pl JOIN mcp_purchase_orders po ON po.purchase_order_id = pl.purchase_order_id
                          WHERE po.supplier_id = :s AND pl.shipped_at IS NOT NULL ORDER BY pl.shipped_at DESC, pl.purchase_order_line_id DESC LIMIT " . max(1, min(100, $limit)));
    $st->execute(['s' => $supplierId]);
    return $st->fetchAll();
}

/** The sources that read this supplier (mcp_sources where supplier_id) — NOT catalog's supplier_sources(), which lists every supplier source for an identifier's select. */
function supplier_sources_of(PDO $pdo, int $supplierId): array
{
    $st = $pdo->prepare('SELECT source_id, name, connector, role, active, paused_at, last_ok_at, robots_state FROM mcp_sources WHERE supplier_id = :s ORDER BY name');
    $st->execute(['s' => $supplierId]);
    return $st->fetchAll();
}
