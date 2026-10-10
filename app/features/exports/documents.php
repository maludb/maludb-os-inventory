<?php
declare(strict_types=1);

/** The documents an export hands over: the three shares' (read in pages of 500 and joined, nothing added or removed), and the two local ones (versioned for the download alone — DECISION 10). */

/** The document of an export: the share's verbatim (paged shares joined), or {schema, generated_at, application, currency, count, rows} for the catalog and the listings. */
function export_document(PDO $pdo, string $export, array $params): array
{
    switch ($export) {
        case 'sales_closed':
            $doc = null;
            for ($off = 0; ; $off += EXPORT_PAGE) {
                $page = share_sales_closed($pdo, $params['from'], $params['to'], $off, EXPORT_PAGE);
                if ($doc === null) { $doc = $page; } else { $doc['orders'] = array_merge($doc['orders'], $page['orders']); }
                if (!$page['truncated'] || $page['orders'] === []) { break; }
                if (count($doc['orders']) > EXPORT_ROW_LIMIT) { break; }
            }
            $doc['offset'] = 0;
            $doc['limit'] = count($doc['orders']);
            $doc['truncated'] = false;
            return $doc;
        case 'purchases_received':
            $doc = null;
            $docs = 0;
            for ($off = 0; ; $off += EXPORT_PAGE) {
                $page = share_purchases_received($pdo, $params['from'], $params['to'], $off, EXPORT_PAGE);
                if ($doc === null) {
                    $doc = $page;
                } else {
                    foreach ($page['suppliers'] as $s) {              // a supplier whose documents fall in two pages is one supplier
                        $found = false;
                        foreach ($doc['suppliers'] as &$have) {
                            if ($have['supplier_id'] === $s['supplier_id'] && $have['name'] === $s['name']) {
                                $have['receipts'] = array_merge($have['receipts'], $s['receipts']);
                                $have['dropships'] = array_merge($have['dropships'], $s['dropships']);
                                $have['total'] = round((float) $have['total'] + (float) $s['total'], 2);
                                $found = true;
                                break;
                            }
                        }
                        unset($have);
                        if (!$found) { $doc['suppliers'][] = $s; }
                    }
                }
                if (!$page['truncated']) { break; }
                if (($docs += EXPORT_PAGE) > EXPORT_ROW_LIMIT) { break; }
            }
            $doc['offset'] = 0;
            $doc['limit'] = (int) $doc['count'];
            $doc['truncated'] = false;
            return $doc;
        case 'stock_valuation':
            return share_stock_valuation($pdo, $params['as_of'], $params['by']);
        case 'catalog':
            $rows = catalog_export_rows($pdo);
            break;
        case 'listings':
            $rows = listings_export_rows($pdo, $params['source'] ?? null);
            break;
        default:
            throw new InvalidArgumentException('No such export.');
    }
    return ['schema' => export_spec($export)['schema'], 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'application' => 'inventory', 'currency' => (string) one_value($pdo, 'SELECT inv_currency()'), 'count' => count($rows), 'rows' => $rows];
}

/** How many rows the download carries (a CSV line, a JSON row). */
function export_rows_count(string $export, array $doc): int
{
    switch ($export) {
        case 'sales_closed':
            return array_sum(array_map(static fn (array $o): int => max(1, count($o['lines'])), $doc['orders']));
        case 'purchases_received':
            $n = 0;
            foreach ($doc['suppliers'] as $s) { foreach (array_merge($s['receipts'], $s['dropships']) as $d) { $n += count($d['lines']); } }
            return $n;
        case 'stock_valuation':
            return count($doc['rows']);
        default:
            return (int) $doc['count'];
    }
}
