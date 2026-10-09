<?php
declare(strict_types=1);

/**
 * The ledger's reads (stock.md "Query functions" — the tools S1–S4 of Phase 4 call these): levels, movements, the four documents and their
 * lines, floor models, the scan resolver. Every read is a mcp_* view; cost is nulled by the views (the receipt's by inv_sees_receipt_cost()).
 * PHP computes no balance.
 */

const LEVELS_PAGE = 100;
const MOVEMENTS_PAGE = 100;
const DOC_STATUSES = ['draft' => 'Draft', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];
const TRANSFER_STATUSES = ['draft' => 'Draft', 'in_transit' => 'In transit', 'received' => 'Received', 'cancelled' => 'Cancelled'];
const COUNT_STATUSES = ['open' => 'Open', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];
const TXN_TYPES = ['receipt' => 'Receipt', 'issue' => 'Issue', 'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in', 'adjustment' => 'Adjustment',
    'count_correction' => 'Count correction', 'sale' => 'Sale', 'return' => 'Return', 'floor_model_in' => 'Floor model in', 'floor_model_out' => 'Floor model out', 'reversal' => 'Reversal'];
const DISCREPANCY_KINDS = ['none' => 'None', 'short' => 'Short', 'over' => 'Over', 'damaged' => 'Damaged', 'wrong_item' => 'Wrong item', 'substitute' => 'Substitute'];

// ---- levels --------------------------------------------------------------------------------------------------------------
/** The WHERE of the levels (shared by the rows and the totals). $filters: variant, product, brand, product_type, location, q, below_reorder, nonzero_only (true). */
function stock_levels_where(PDO $pdo, array $filters, array &$args): string
{
    $w = [];
    if ($filters['nonzero_only'] ?? true) { $w[] = '(b.qty_on_hand <> 0 OR b.qty_allocated <> 0 OR b.qty_floor_model <> 0)'; }
    if (!empty($filters['variant'])) { $w[] = 'b.variant_id = :variant'; $args['variant'] = (int) $filters['variant']; }
    if (!empty($filters['product'])) { $w[] = 'v.product_id = :product'; $args['product'] = (int) $filters['product']; }
    if (!empty($filters['brand'])) { $w[] = 'p.brand_id = :brand'; $args['brand'] = (int) $filters['brand']; }
    if (!empty($filters['product_type'])) {
        $t = ctype_digit((string) $filters['product_type']) ? (int) $filters['product_type'] : (int) (one_value($pdo, 'SELECT product_type_id FROM mcp_product_types WHERE key = :k', ['k' => (string) $filters['product_type']]) ?? -1);
        $w[] = 'p.product_type_id = :ptype'; $args['ptype'] = $t;
    }
    if (!empty($filters['location'])) { $w[] = 'b.location_id = :location'; $args['location'] = (int) $filters['location']; }
    if (trim((string) ($filters['q'] ?? '')) !== '') {
        $w[] = '(b.sku ILIKE :qp OR b.product_name ILIKE :qw OR v.barcode = inv_gtin14(:qr))';
        $args['qp'] = trim((string) $filters['q']) . '%'; $args['qw'] = '%' . trim((string) $filters['q']) . '%'; $args['qr'] = trim((string) $filters['q']);
    }
    if (!empty($filters['below_reorder'])) { $w[] = 'b.variant_id IN (SELECT r.variant_id FROM stock_below_reorder r)'; }
    return $w === [] ? '' : ' WHERE ' . implode(' AND ', $w);
}

/** The variants at or under their reorder point: available (sellable, every location) + on order ≤ the variant's point, else the product's, else the setting's. */
const STOCK_BELOW_REORDER_CTE = 'WITH stock_below_reorder AS (
    SELECT v.variant_id FROM mcp_product_variants v JOIN mcp_products pp ON pp.product_id = v.product_id
     WHERE v.active AND v.kind = \'single\'
       AND v.qty_available + inv_on_order(v.variant_id) <= COALESCE(v.reorder_point, pp.reorder_point, (SELECT reorder_point_default FROM mcp_settings)))';

/** The levels table (tool `stock_levels`): variant × location rows, each with `below_reorder`. */
function stock_levels(PDO $pdo, array $filters, int $limit = LEVELS_PAGE, int $offset = 0): array
{
    $args = [];
    $where = stock_levels_where($pdo, $filters, $args);
    $st = $pdo->prepare(STOCK_BELOW_REORDER_CTE . ' SELECT b.variant_id, b.sku, b.product_name, v.product_id, v.size_name, v.brand, b.location_id, b.location_name,
            b.qty_on_hand, b.qty_allocated, b.qty_floor_model, b.qty_available, b.updated_at, v.reorder_point,
            (b.variant_id IN (SELECT r.variant_id FROM stock_below_reorder r)) AS below_reorder
          FROM mcp_inventory_balances b JOIN mcp_product_variants v ON v.variant_id = b.variant_id JOIN mcp_products p ON p.product_id = v.product_id'
        . $where . ' ORDER BY b.product_name, v.size_key NULLS LAST, b.sku, b.location_name LIMIT ' . max(1, min(1000, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map(static function (array $r): array {
        foreach (['variant_id', 'product_id', 'location_id', 'qty_on_hand', 'qty_allocated', 'qty_floor_model', 'qty_available'] as $k) { $r[$k] = (int) $r[$k]; }
        $r['below_reorder'] = (bool) $r['below_reorder'];
        return $r;
    }, $st->fetchAll());
}

/** The totals over the same filters: {rows, on_hand, allocated, floor_model, available}. */
function stock_totals(PDO $pdo, array $filters): array
{
    $args = [];
    $where = stock_levels_where($pdo, $filters, $args);
    $st = $pdo->prepare(STOCK_BELOW_REORDER_CTE . ' SELECT count(*) AS rows, COALESCE(sum(b.qty_on_hand), 0) AS on_hand, COALESCE(sum(b.qty_allocated), 0) AS allocated,
            COALESCE(sum(b.qty_floor_model), 0) AS floor_model, COALESCE(sum(b.qty_available), 0) AS available
          FROM mcp_inventory_balances b JOIN mcp_product_variants v ON v.variant_id = b.variant_id JOIN mcp_products p ON p.product_id = v.product_id' . $where);
    $st->execute($args);
    return array_map('intval', $st->fetch());
}

/** What a location holds (tool `stock_by_location`). */
function stock_by_location(PDO $pdo, int $locationId, array $filters, int $limit = LEVELS_PAGE, int $offset = 0): array
{
    require_once dirname(__DIR__) . '/locations/queries.php';
    $f = ['location' => $locationId] + $filters;
    return ['location' => find_location($pdo, $locationId), 'rows' => stock_levels($pdo, $f, $limit, $offset), 'totals' => stock_totals($pdo, $f)];
}

// ---- movements ---------------------------------------------------------------------------------------------------------
/**
 * The ledger (tool `stock_movements`): $filters variant, location, txn_type (one or a list), reference_kind + reference_id, from, to (dates),
 * days (the default window when no from: 30 for the screen, 7 for the tool). Each row carries its document's number, the counterparty in
 * words and whether it was reversed. Newest first.
 */
function stock_movements(PDO $pdo, array $filters, int $limit = MOVEMENTS_PAGE, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (!empty($filters['variant'])) { $w[] = 't.variant_id = :variant'; $args['variant'] = (int) $filters['variant']; }
    if (!empty($filters['location'])) { $w[] = 't.location_id = :location'; $args['location'] = (int) $filters['location']; }
    $types = $filters['txn_type'] ?? [];
    $types = array_values(array_filter(is_array($types) ? $types : [$types], static fn ($x): bool => isset(TXN_TYPES[$x])));
    if ($types !== []) { $w[] = 't.txn_type = ANY (CAST(:types AS text[]))'; $args['types'] = pg_array_literal($types); }
    if (!empty($filters['reference_kind']) && isset($filters['reference_id'])) { $w[] = 't.reference_kind = :rk AND t.reference_id = :rid'; $args['rk'] = (string) $filters['reference_kind']; $args['rid'] = (int) $filters['reference_id']; }
    if (!empty($filters['from'])) { $w[] = 't.occurred_at >= CAST(:from AS date)'; $args['from'] = (string) $filters['from']; }
    elseif (empty($filters['reference_kind']) && ($filters['days'] ?? 30) !== null) { $w[] = 't.occurred_at >= now() - make_interval(days => :days)'; $args['days'] = (int) ($filters['days'] ?? 30); }
    if (!empty($filters['to'])) { $w[] = 't.occurred_at < CAST(:to AS date) + 1'; $args['to'] = (string) $filters['to']; }
    $sql = 'SELECT t.transaction_id, t.group_id, t.txn_type, t.affects, t.variant_id, t.sku, v.product_name, v.size_name, t.location_id, t.location_name, t.qty, t.unit_cost,
                   t.counterparty_kind, t.counterparty_id, t.reason_code, t.reference_kind, t.reference_id, t.reverses_id, t.note, t.occurred_at, t.posted_at, t.actor_member_id, t.actor_name,
                   CASE t.reference_kind WHEN \'goods_receipt\' THEN (SELECT number FROM mcp_goods_receipts WHERE goods_receipt_id = t.reference_id)
                                         WHEN \'transfer\' THEN (SELECT number FROM mcp_inventory_transfers WHERE transfer_id = t.reference_id)
                                         WHEN \'adjustment\' THEN (SELECT number FROM mcp_inventory_adjustments WHERE adjustment_id = t.reference_id)
                                         WHEN \'count\' THEN (SELECT number FROM mcp_inventory_counts WHERE count_id = t.reference_id)
                                         WHEN \'return\' THEN (SELECT number FROM mcp_return_authorizations WHERE return_id = t.reference_id)
                                         WHEN \'reversal\' THEN \'#\' || t.reference_id END AS document_number,
                   CASE t.counterparty_kind WHEN \'location\' THEN (SELECT name FROM mcp_locations WHERE location_id = t.counterparty_id)
                                            WHEN \'supplier\' THEN (SELECT name FROM mcp_suppliers WHERE supplier_id = t.counterparty_id)
                                            WHEN \'customer\' THEN (SELECT name FROM mcp_customers WHERE customer_id = t.counterparty_id)
                                            WHEN \'disposal\' THEN \'disposal\' END AS counterparty_name,
                   (SELECT r.transaction_id FROM mcp_inventory_transactions r WHERE r.reference_kind = \'reversal\' AND r.reference_id = t.transaction_id LIMIT 1) AS reversed_by
              FROM mcp_inventory_transactions t JOIN mcp_product_variants v ON v.variant_id = t.variant_id'
        . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w)) . ' ORDER BY t.occurred_at DESC, t.transaction_id DESC LIMIT ' . (max(1, min(1000, $limit)) + 1) . ' OFFSET ' . max(0, $offset);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_map(static function (array $r): array {
        foreach (['transaction_id', 'variant_id', 'location_id', 'qty', 'reference_id'] as $k) { $r[$k] = (int) $r[$k]; }
        $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
        return $r;
    }, array_slice($rows, 0, $limit));
    return ['rows' => $rows, 'more' => $more];
}

/** The screen a movement's document is on, or null. */
function movement_document_url(array $row): ?string
{
    $id = (int) $row['reference_id'];
    return match ($row['reference_kind']) {
        'goods_receipt' => '/receipts/' . $id, 'transfer' => '/transfers/' . $id, 'adjustment' => '/adjustments/' . $id, 'count' => '/counts/' . $id,
        'shipment' => '/shipments/' . $id, 'return' => '/returns/' . $id, 'reversal' => '/stock/movements?variant=' . (int) $row['variant_id'] . '#movement-row-' . $id,
        default => null,
    };
}

/** The movements a document posted (its view's "posted" section). */
function document_movements(PDO $pdo, string $referenceKind, int $referenceId): array
{
    return stock_movements($pdo, ['reference_kind' => $referenceKind, 'reference_id' => $referenceId], 500)['rows'];
}

function find_movement(PDO $pdo, int $txnId): ?array
{
    $st = $pdo->prepare('SELECT transaction_id, txn_type, variant_id, sku, location_id, location_name, qty, reference_kind, reference_id, reverses_id FROM mcp_inventory_transactions WHERE transaction_id = :id');
    $st->execute(['id' => $txnId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

// ---- floor models ------------------------------------------------------------------------------------------------------
function floor_models(PDO $pdo, ?int $locationId): array
{
    $st = $pdo->prepare('SELECT b.variant_id, b.sku, b.product_name, v.size_name, b.location_id, b.location_name, b.qty_floor_model, b.qty_on_hand, b.qty_available
                           FROM mcp_inventory_balances b JOIN mcp_product_variants v ON v.variant_id = b.variant_id
                          WHERE b.qty_floor_model > 0' . ($locationId !== null ? ' AND b.location_id = :l' : '') . ' ORDER BY b.location_name, b.product_name, b.sku');
    $st->execute($locationId !== null ? ['l' => $locationId] : []);
    return $st->fetchAll();
}

// ---- receipts ----------------------------------------------------------------------------------------------------------
const RECEIPT_COLUMNS = 'r.goods_receipt_id, r.number, r.supplier_id, r.supplier_name, r.purchase_order_id, r.purchase_order_number, r.location_id, r.location_name, r.status,
    r.delivery_note_ref, r.received_on, r.notes, r.posted_by, r.posted_at, r.created_by, r.created_at, r.updated_at,
    (SELECT count(*) FROM mcp_goods_receipt_lines gl WHERE gl.goods_receipt_id = r.goods_receipt_id) AS line_count,
    (SELECT COALESCE(sum(gl.qty), 0) FROM mcp_goods_receipt_lines gl WHERE gl.goods_receipt_id = r.goods_receipt_id) AS units,
    (SELECT display_name FROM mcp_members m WHERE m.member_id = r.posted_by) AS posted_by_name';

/** Receipts (tool `receipts_open`): $filters supplier, purchase_order, location, status (one or a list; default draft for the tool), from, to. Drafts first. */
function receipts_open(PDO $pdo, array $filters, int $limit = 100, int $offset = 0): array
{
    $w = [];
    $args = [];
    foreach (['supplier' => 'supplier_id', 'purchase_order' => 'purchase_order_id', 'location' => 'location_id'] as $k => $col) {
        if (!empty($filters[$k])) { $w[] = "r.$col = :$k"; $args[$k] = (int) $filters[$k]; }
    }
    $st = $filters['status'] ?? null;
    $st = array_values(array_filter(is_array($st) ? $st : ($st === null || $st === '' ? [] : [$st]), static fn ($x): bool => isset(DOC_STATUSES[$x])));
    if ($st !== []) { $w[] = 'r.status = ANY (CAST(:st AS text[]))'; $args['st'] = pg_array_literal($st); }
    if (!empty($filters['from'])) { $w[] = 'r.received_on >= CAST(:from AS date)'; $args['from'] = $filters['from']; }
    if (!empty($filters['to'])) { $w[] = 'r.received_on <= CAST(:to AS date)'; $args['to'] = $filters['to']; }
    $q = $pdo->prepare('SELECT ' . RECEIPT_COLUMNS . ' FROM mcp_goods_receipts r' . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w))
        . " ORDER BY (r.status = 'draft') DESC, r.received_on DESC, r.goods_receipt_id DESC LIMIT " . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $q->execute($args);
    return $q->fetchAll();
}

function find_receipt(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . RECEIPT_COLUMNS . ' FROM mcp_goods_receipts r WHERE r.goods_receipt_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function receipt_lines(PDO $pdo, int $receiptId): array
{
    $st = $pdo->prepare('SELECT gl.goods_receipt_line_id, gl.goods_receipt_id, gl.line_no, gl.purchase_order_line_id, gl.variant_id, gl.sku, v.product_name, v.size_name, gl.qty, gl.unit_cost,
                                gl.discrepancy_kind, gl.discrepancy_note, gl.putaway_location_id, l.name AS putaway_location_name
                           FROM mcp_goods_receipt_lines gl JOIN mcp_product_variants v ON v.variant_id = gl.variant_id LEFT JOIN mcp_locations l ON l.location_id = gl.putaway_location_id
                          WHERE gl.goods_receipt_id = :r ORDER BY gl.line_no');
    $st->execute(['r' => $receiptId]);
    return $st->fetchAll();
}

function receipt_movements(PDO $pdo, int $receiptId): array
{
    return document_movements($pdo, 'goods_receipt', $receiptId);
}

/** The open lines of a stock PO: qty_ordered − qty_received > 0. */
function po_open_lines(PDO $pdo, int $purchaseOrderId): array
{
    $st = $pdo->prepare("SELECT pl.purchase_order_line_id, pl.line_no, pl.variant_id, pl.sku, pl.product_name, pl.qty_ordered, pl.qty_received, pl.qty_ordered - pl.qty_received AS qty_open, pl.unit_cost
                           FROM mcp_purchase_order_lines pl WHERE pl.purchase_order_id = :po AND pl.qty_ordered - pl.qty_received > 0 AND pl.status NOT IN ('declined', 'cancelled', 'closed_short') ORDER BY pl.line_no");
    $st->execute(['po' => $purchaseOrderId]);
    return $st->fetchAll();
}

/** The open stock POs of a supplier (a receipt's PO select). */
function po_options(PDO $pdo, int $supplierId): array
{
    $st = $pdo->prepare("SELECT purchase_order_id, number, status, expected_on FROM mcp_purchase_orders WHERE supplier_id = :s AND kind = 'stock' AND status IN ('sent', 'acknowledged', 'partial') ORDER BY ordered_on DESC NULLS LAST, purchase_order_id DESC");
    $st->execute(['s' => $supplierId]);
    return $st->fetchAll();
}

function find_purchase_order_brief(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT purchase_order_id, number, supplier_id, supplier_name, kind, status, location_id FROM mcp_purchase_orders WHERE purchase_order_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

// ---- adjustments -------------------------------------------------------------------------------------------------------
const ADJUSTMENT_COLUMNS = 'a.adjustment_id, a.number, a.location_id, a.location_name, a.reason_code_id, a.reason_code, rc.name AS reason_name, rc.affects_qty, a.status, a.notes,
    a.posted_by, a.posted_at, a.created_by, a.created_at, a.updated_at,
    (SELECT count(*) FROM mcp_inventory_adjustment_lines l WHERE l.adjustment_id = a.adjustment_id) AS line_count,
    (SELECT COALESCE(sum(l.qty_delta), 0) FROM mcp_inventory_adjustment_lines l WHERE l.adjustment_id = a.adjustment_id) AS units_delta,
    (SELECT display_name FROM mcp_members m WHERE m.member_id = a.posted_by) AS posted_by_name';

function adjustments(PDO $pdo, array $filters, int $limit = 100, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (!empty($filters['location'])) { $w[] = 'a.location_id = :location'; $args['location'] = (int) $filters['location']; }
    if (!empty($filters['reason'])) { $w[] = (ctype_digit((string) $filters['reason']) ? 'a.reason_code_id = :reason' : 'a.reason_code = :reason'); $args['reason'] = $filters['reason']; }
    if (!empty($filters['status']) && isset(DOC_STATUSES[$filters['status']])) { $w[] = 'a.status = :status'; $args['status'] = $filters['status']; }
    $st = $pdo->prepare('SELECT ' . ADJUSTMENT_COLUMNS . ' FROM mcp_inventory_adjustments a JOIN mcp_reason_codes rc ON rc.reason_code_id = a.reason_code_id'
        . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w)) . " ORDER BY (a.status = 'draft') DESC, a.created_at DESC, a.adjustment_id DESC LIMIT " . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return $st->fetchAll();
}

function find_adjustment(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . ADJUSTMENT_COLUMNS . ' FROM mcp_inventory_adjustments a JOIN mcp_reason_codes rc ON rc.reason_code_id = a.reason_code_id WHERE a.adjustment_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function adjustment_lines(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT l.adjustment_line_id, l.adjustment_id, l.line_no, l.variant_id, l.sku, v.product_name, v.size_name, l.qty_delta, l.unit_cost, l.note
                           FROM mcp_inventory_adjustment_lines l JOIN mcp_product_variants v ON v.variant_id = l.variant_id WHERE l.adjustment_id = :a ORDER BY l.line_no');
    $st->execute(['a' => $id]);
    return $st->fetchAll();
}

/** The active reason codes that apply to adjustments. */
function adjustment_reasons(PDO $pdo): array
{
    return $pdo->query("SELECT reason_code_id, code, name, affects_qty FROM mcp_reason_codes WHERE active AND 'adjustment' = ANY (applies_to) ORDER BY sort_order, name")->fetchAll();
}

/** A reason code by id or code (any applies_to — the handler judges it). */
function find_reason(PDO $pdo, string $v): ?array
{
    $st = $pdo->prepare('SELECT reason_code_id, code, name, applies_to, affects_qty, active FROM mcp_reason_codes WHERE ' . (ctype_digit($v) ? 'reason_code_id = :v' : 'code = :v'));
    $st->execute(['v' => ctype_digit($v) ? (int) $v : $v]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

// ---- transfers ---------------------------------------------------------------------------------------------------------
const TRANSFER_COLUMNS = 't.transfer_id, t.number, t.from_location_id, t.from_location, t.to_location_id, t.to_location, t.status, t.notes, t.shipped_by, t.shipped_at,
    t.received_by, t.received_at, t.created_by, t.created_at, t.updated_at,
    (SELECT count(*) FROM mcp_inventory_transfer_lines l WHERE l.transfer_id = t.transfer_id) AS line_count,
    (SELECT COALESCE(sum(l.qty), 0) FROM mcp_inventory_transfer_lines l WHERE l.transfer_id = t.transfer_id) AS units,
    (SELECT COALESCE(sum(l.qty_received), 0) FROM mcp_inventory_transfer_lines l WHERE l.transfer_id = t.transfer_id) AS units_received,
    (SELECT display_name FROM mcp_members m WHERE m.member_id = t.shipped_by) AS shipped_by_name,
    (SELECT display_name FROM mcp_members m WHERE m.member_id = t.received_by) AS received_by_name';

/** Transfers (tool `transfers_open`): $filters from_location, to_location, location (either side), status (one or a list; default draft + in_transit for the tool). */
function transfers_open(PDO $pdo, array $filters, int $limit = 100, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (!empty($filters['from_location'])) { $w[] = 't.from_location_id = :f'; $args['f'] = (int) $filters['from_location']; }
    if (!empty($filters['to_location'])) { $w[] = 't.to_location_id = :t'; $args['t'] = (int) $filters['to_location']; }
    if (!empty($filters['location'])) { $w[] = '(t.from_location_id = :l OR t.to_location_id = :l2)'; $args['l'] = (int) $filters['location']; $args['l2'] = (int) $filters['location']; }
    $st = $filters['status'] ?? null;
    $st = array_values(array_filter(is_array($st) ? $st : ($st === null || $st === '' ? [] : [$st]), static fn ($x): bool => isset(TRANSFER_STATUSES[$x])));
    if ($st !== []) { $w[] = 't.status = ANY (CAST(:st AS text[]))'; $args['st'] = pg_array_literal($st); }
    $q = $pdo->prepare('SELECT ' . TRANSFER_COLUMNS . ' FROM mcp_inventory_transfers t' . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w))
        . " ORDER BY (t.status IN ('draft', 'in_transit')) DESC, t.created_at DESC, t.transfer_id DESC LIMIT " . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $q->execute($args);
    return $q->fetchAll();
}

function find_transfer(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . TRANSFER_COLUMNS . ' FROM mcp_inventory_transfers t WHERE t.transfer_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function transfer_lines(PDO $pdo, int $transferId): array
{
    $st = $pdo->prepare('SELECT l.transfer_line_id, l.transfer_id, l.line_no, l.variant_id, l.sku, v.product_name, v.size_name, l.qty, l.qty_received
                           FROM mcp_inventory_transfer_lines l JOIN mcp_product_variants v ON v.variant_id = l.variant_id WHERE l.transfer_id = :t ORDER BY l.line_no');
    $st->execute(['t' => $transferId]);
    return $st->fetchAll();
}

// ---- counts ------------------------------------------------------------------------------------------------------------
const COUNT_COLUMNS = 'c.count_id, c.number, c.location_id, c.location_name, c.status, c.notes, c.started_by, c.started_at, c.posted_by, c.posted_at, c.created_at, c.updated_at,
    c.line_count, c.lines_differing,
    (SELECT count(*) FROM mcp_inventory_count_lines l WHERE l.count_id = c.count_id AND l.counted_qty IS NOT NULL) AS lines_counted,
    (SELECT display_name FROM mcp_members m WHERE m.member_id = c.started_by) AS started_by_name,
    (SELECT display_name FROM mcp_members m WHERE m.member_id = c.posted_by) AS posted_by_name';

/** Counts (tool `counts`): $filters location, status, since (days). */
function counts(PDO $pdo, array $filters, int $limit = 100, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (!empty($filters['location'])) { $w[] = 'c.location_id = :location'; $args['location'] = (int) $filters['location']; }
    if (!empty($filters['status']) && isset(COUNT_STATUSES[$filters['status']])) { $w[] = 'c.status = :status'; $args['status'] = $filters['status']; }
    if (!empty($filters['since'])) { $w[] = 'c.started_at >= now() - make_interval(days => :since)'; $args['since'] = (int) $filters['since']; }
    $st = $pdo->prepare('SELECT ' . COUNT_COLUMNS . ' FROM mcp_inventory_counts c' . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w))
        . " ORDER BY (c.status = 'open') DESC, c.started_at DESC, c.count_id DESC LIMIT " . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return $st->fetchAll();
}

function find_count(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . COUNT_COLUMNS . ' FROM mcp_inventory_counts c WHERE c.count_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A count's lines (tool `count_lines`); on a posted count each row + the correction it wrote. */
function count_lines(PDO $pdo, int $countId, bool $differingOnly = false): array
{
    $st = $pdo->prepare("SELECT l.count_line_id, l.count_id, l.variant_id, l.sku, v.product_name, v.size_name, l.system_qty, l.counted_qty, l.difference, l.counted_by,
                                (SELECT display_name FROM mcp_members m WHERE m.member_id = l.counted_by) AS counted_by_name, l.counted_at,
                                (SELECT t.transaction_id FROM mcp_inventory_transactions t WHERE t.reference_kind = 'count' AND t.reference_id = l.count_id AND t.variant_id = l.variant_id LIMIT 1) AS correction_transaction_id,
                                (SELECT t.qty FROM mcp_inventory_transactions t WHERE t.reference_kind = 'count' AND t.reference_id = l.count_id AND t.variant_id = l.variant_id LIMIT 1) AS correction_qty
                           FROM mcp_inventory_count_lines l JOIN mcp_product_variants v ON v.variant_id = l.variant_id
                          WHERE l.count_id = :c" . ($differingOnly ? ' AND l.counted_qty IS NOT NULL AND l.counted_qty <> l.system_qty' : '') . ' ORDER BY v.product_name, v.size_key NULLS LAST, l.sku');
    $st->execute(['c' => $countId]);
    return $st->fetchAll();
}

function find_count_line(PDO $pdo, int $countId, int $variantId): ?array
{
    $st = $pdo->prepare('SELECT count_line_id, variant_id, sku, system_qty, counted_qty FROM mcp_inventory_count_lines WHERE count_id = :c AND variant_id = :v');
    $st->execute(['c' => $countId, 'v' => $variantId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

// ---- the scan ----------------------------------------------------------------------------------------------------------
/**
 * The variant a code names: a GTIN (inv_gtin14() against the barcode or a gtin/upc/ean identifier), else the SKU exact. Null when none. A bundle
 * answers with kind = bundle so the handler may refuse it.
 */
function variant_by_scan(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') { return null; }
    $st = $pdo->prepare("SELECT v.variant_id, v.product_id, v.product_name, v.sku, v.size_name, v.kind, v.barcode, v.active, v.cost_price
                           FROM mcp_product_variants v
                          WHERE inv_gtin14(:c) IS NOT NULL AND (v.barcode = inv_gtin14(:c2)
                                OR EXISTS (SELECT 1 FROM mcp_variant_identifiers i WHERE i.variant_id = v.variant_id AND i.kind IN ('gtin', 'upc', 'ean') AND i.value = inv_gtin14(:c3)))
                          ORDER BY v.active DESC LIMIT 1");
    $st->execute(['c' => $code, 'c2' => $code, 'c3' => $code]);
    $r = $st->fetch();
    if ($r === false) {
        $st = $pdo->prepare('SELECT v.variant_id, v.product_id, v.product_name, v.sku, v.size_name, v.kind, v.barcode, v.active, v.cost_price FROM mcp_product_variants v WHERE lower(v.sku) = lower(:c)');
        $st->execute(['c' => $code]);
        $r = $st->fetch();
    }
    if ($r === false) { return null; }
    $r['variant_id'] = (int) $r['variant_id'];
    $r['product_id'] = (int) $r['product_id'];
    return $r;
}

/** The balance of one variant at one location now (0 when none). */
function balance_now(PDO $pdo, int $variantId, int $locationId): int
{
    return (int) (one_value($pdo, 'SELECT qty_on_hand FROM mcp_inventory_balances WHERE variant_id = :v AND location_id = :l', ['v' => $variantId, 'l' => $locationId]) ?? 0);
}

/** The suppliers for a receipt's select (active). */
function suppliers_active(PDO $pdo): array
{
    return $pdo->query('SELECT supplier_id, name FROM mcp_suppliers WHERE active ORDER BY name')->fetchAll();
}
