<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shares/queries.php';

/**
 * The exports (reports-admin.md "The exports"): five downloads. The three accounting files are the ledger's shares — `os.inventory-sales/1`, `os.inventory-purchases/1`, `os.inventory-valuation/1` — read by the same functions the kernel
 * reads (they carry cost unnulled, so they are reached only behind the gate of export_may()); the catalog and the listings are local documents read through the views, which null cost for anyone below the wall.
 * An export never carries a credential, a feed key's value or a customer's contact details.
 */

const EXPORT_ROW_LIMIT = 50000;
const EXPORT_PAGE = 500;
const EXPORT_ACCOUNTING = ['sales_closed', 'purchases_received', 'stock_valuation'];

/** The five: title, what it carries, the document, the fields it takes. */
function export_specs(): array
{
    return [
        'sales_closed' => ['title' => 'Sales closed', 'schema' => 'os.inventory-sales/1', 'accounting' => true, 'fields' => ['from', 'to'],
            'about' => 'Orders closed in the period, one row per order line with the order\'s money, the payments by method and the cost of goods sold. At most 92 days.'],
        'purchases_received' => ['title' => 'Purchases received', 'schema' => 'os.inventory-purchases/1', 'accounting' => true, 'fields' => ['from', 'to'],
            'about' => 'Goods receipts posted and drop-ships delivered in the period, by supplier, at cost — one row per line. At most 92 days.'],
        'stock_valuation' => ['title' => 'Stock valuation', 'schema' => 'os.inventory-valuation/1', 'accounting' => true, 'fields' => ['as_of', 'by'],
            'about' => 'Stock on hand at standard cost at the end of a day, by location or brand.'],
        'catalog' => ['title' => 'The catalog', 'schema' => 'os.inventory-catalog/1', 'accounting' => false, 'fields' => [],
            'about' => 'Every variant with its product, brand, identifiers, size, prices, reorder rule and stock. Cost only if your role sees cost.'],
        'listings' => ['title' => 'The listings', 'schema' => 'os.inventory-listings/1', 'accounting' => false, 'fields' => ['source'],
            'about' => 'What the sources offer: one row per listing variant with its price, availability and match. Cost only if your role sees cost.'],
    ];
}

function export_spec(string $export): ?array
{
    return export_specs()[$export] ?? null;
}

/** May the caller download this export? The three accounting files: exports.all, or reports.read with the cost wall's right; the catalog and the listings: reports.read. */
function export_may(string $export): bool
{
    if (in_array($export, EXPORT_ACCOUNTING, true)) {
        return has_right('exports.all') || (has_right('reports.read') && sees_cost());
    }
    return has_right('reports.read') || has_right('exports.all');
}

/** The fields of an export from a request, validated; mistakes go to $errors (name => sentence). */
function export_params(PDO $pdo, string $export, array $src, array &$errors): array
{
    $spec = export_spec($export) ?? throw new InvalidArgumentException('No such export.');
    $get = static function (string $k) use ($src): ?string { $v = $src[$k] ?? null; return is_array($v) || $v === null ? null : trim((string) $v); };
    $p = [];
    $tz = business_tz($pdo);
    $isDate = static function (?string $v): bool { if ($v === null) { return false; } $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v); return $d !== false && $d->format('Y-m-d') === $v; };
    if (in_array('from', $spec['fields'], true)) {
        $p['from'] = $get('from');
        $p['to'] = $get('to');
        foreach (['from', 'to'] as $k) {
            if ($p[$k] === null || $p[$k] === '') { $errors[$k] = 'A period needs from and to.'; }
            elseif (!$isDate($p[$k])) { $errors[$k] = ucfirst($k) . ' is a date, YYYY-MM-DD.'; }
        }
        if (!isset($errors['from']) && !isset($errors['to'])) {
            if ($p['to'] < $p['from']) { $errors['to'] = 'The period ends before it starts.'; }
            elseif ((strtotime($p['to']) - strtotime($p['from'])) / 86400 > 92) { $errors['to'] = 'A period is at most 92 days — narrow it.'; }
        }
    }
    if (in_array('as_of', $spec['fields'], true)) {
        $v = $get('as_of');
        $p['as_of'] = $v === null || $v === '' ? (new DateTimeImmutable('now', $tz))->format('Y-m-d') : $v;
        if (!$isDate($p['as_of'])) { $errors['as_of'] = 'As of is a date, YYYY-MM-DD.'; }
        $by = $get('by');
        $p['by'] = $by === null || $by === '' ? 'location' : $by;
        if (!in_array($p['by'], ['location', 'brand'], true)) { $errors['by'] = 'Group by location or brand.'; }
    }
    if (in_array('source', $spec['fields'], true)) {
        $v = $get('source');
        $p['source'] = null;
        if ($v !== null && $v !== '') {
            if (filter_var($v, FILTER_VALIDATE_INT) === false || one_value($pdo, 'SELECT 1 FROM mcp_sources WHERE source_id = :s', ['s' => (int) $v]) === null) { $errors['source'] = 'Choose a source from the list.'; }
            else { $p['source'] = (int) $v; }
        }
    }
    return $p;
}

/** The period a download is logged under: ['from', 'to'] or ['as_of']. */
function export_period(array $params): array
{
    return isset($params['from']) ? ['from' => $params['from'], 'to' => $params['to']] : (isset($params['as_of']) ? ['as_of' => $params['as_of']] : []);
}

/** One row per variant, in the document's keys. */
function catalog_export_rows(PDO $pdo): array
{
    $n = (int) one_value($pdo, 'SELECT count(*) FROM mcp_product_variants');
    if ($n > EXPORT_ROW_LIMIT) { throw new DomainException('The catalog has ' . number_format($n) . ' variants — more than an export carries (' . number_format(EXPORT_ROW_LIMIT) . ').'); }
    $sees = sees_cost();
    $ids = [];
    foreach ($pdo->query("SELECT variant_id, kind, value FROM mcp_variant_identifiers ORDER BY variant_id, identifier_id")->fetchAll() as $r) { $ids[(int) $r['variant_id']][] = $r; }
    $st = $pdo->query('SELECT v.variant_id, p.name AS product, p.brand, p.product_type, p.kind, p.status, v.sku, v.size_name, v.barcode, v.mpn, v.weight_g, v.length_mm, v.width_mm, v.height_mm,
                              COALESCE(v.ships_how, p.ships_how) AS ships_how, v.retail_price, v.map_price, v.cost_price, v.cost_withheld, COALESCE(v.reorder_point, p.reorder_point) AS reorder_point, v.reorder_qty, v.active, v.qty_on_hand, v.qty_available
                         FROM mcp_product_variants v JOIN mcp_products p ON p.product_id = v.product_id ORDER BY lower(p.name), v.sku');
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $others = [];
        $gtin = $r['barcode'];
        foreach ($ids[(int) $r['variant_id']] ?? [] as $i) {
            if (in_array($i['kind'], ['gtin', 'upc', 'ean'], true) && $gtin === null) { $gtin = $i['value']; }
            $others[] = $i['kind'] . ':' . $i['value'];
        }
        $rows[] = ['product' => $r['product'], 'brand' => $r['brand'], 'type' => $r['product_type'], 'kind' => $r['kind'], 'status' => $r['status'], 'sku' => $r['sku'], 'size' => $r['size_name'], 'gtin' => $gtin, 'mpn' => $r['mpn'],
            'identifiers' => implode(';', $others), 'weight_g' => $r['weight_g'], 'length_mm' => $r['length_mm'], 'width_mm' => $r['width_mm'], 'height_mm' => $r['height_mm'], 'ships_how' => $r['ships_how'],
            'retail_price' => $r['retail_price'], 'map_price' => $r['map_price'], 'cost_price' => $sees && !$r['cost_withheld'] ? $r['cost_price'] : null, 'reorder_point' => $r['reorder_point'], 'reorder_qty' => $r['reorder_qty'],
            'active' => (bool) $r['active'], 'on_hand' => $r['qty_on_hand'], 'available' => $r['qty_available']];
    }
    return $rows;
}

/** One row per listing variant of a source (or of every active source), cost only through the view's own rule. */
function listings_export_rows(PDO $pdo, ?int $sourceId): array
{
    $where = $sourceId !== null ? 'lv.source_id = :s' : 's.active';
    $n = $pdo->prepare("SELECT count(*) FROM mcp_listing_variants lv JOIN mcp_sources s ON s.source_id = lv.source_id WHERE $where");
    $n->execute($sourceId !== null ? ['s' => $sourceId] : []);
    $count = (int) $n->fetchColumn();
    if ($count > EXPORT_ROW_LIMIT) { throw new DomainException('That is ' . number_format($count) . ' listings — more than an export carries (' . number_format(EXPORT_ROW_LIMIT) . '). Choose one source.'); }
    $st = $pdo->prepare("SELECT lv.source_name AS source, lv.source_role AS role, lv.listing_title, lv.title AS variant_title, lv.size_name AS size, lv.sku, lv.barcode, lv.mpn, lv.price, lv.compare_at_price, lv.currency,
                                lv.cost_price, lv.cost_withheld, lv.availability, lv.qty, lv.lead_time_days, lv.ships_how, pv.sku AS matched_sku, lv.match_kind, lv.first_seen_at, lv.last_seen_at, lv.removed_at, lv.url
                           FROM mcp_listing_variants lv JOIN mcp_sources s ON s.source_id = lv.source_id LEFT JOIN mcp_product_variants pv ON pv.variant_id = lv.variant_id
                          WHERE $where ORDER BY lower(lv.source_name), lv.listing_id, lv.listing_variant_id");
    $st->execute($sourceId !== null ? ['s' => $sourceId] : []);
    $sees = sees_cost();
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['cost_price'] = $sees && !$r['cost_withheld'] ? $r['cost_price'] : null;
        unset($r['cost_withheld']);
        $rows[] = $r;
    }
    return $rows;
}
