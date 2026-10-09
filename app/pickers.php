<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; the design system's reference is
 * record-picker.md). One entry per kind of record a form chooses; every key is a bigint here (Inventory has no UUID records).
 *
 *   brand      the active brands (a product's brand)                                  slice 1
 *   supplier   the active suppliers (a brand's dealer program; slice 6's forms)       slice 1
 *   variant    the active variants — SKU, product, size (a bundle's component)        slice 1   param single=1: single products' variants only
 *   product    the products (a variant's product, a filter)                           slice 1
 *
 * Every `gate` is the authorization the screens that use the field apply (null = allowed, else the refusal's words); every row comes from
 * the mcp_* views. `search` reuses the feature's own query function and only filters and slices what it returns; `label` names one record
 * for a form's first render and a 422 re-render (null when the caller may not see it: the form then shows the placeholder).
 */
return [
    'brand' => [
        'title' => 'Brands', 'noun' => 'brand', 'params' => [],
        'gate' => static fn (array $p): ?string => has_right('inventory.read') ? null : 'You may not see the catalog.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/catalog/queries.php';
            $rows = array_map(static fn (array $b): array => ['id' => $b['brand_id'], 'label' => $b['name'], 'detail' => trim(($b['supplier_name'] ? 'via ' . $b['supplier_name'] . ' · ' : '') . $b['product_count'] . ' product' . ($b['product_count'] === 1 ? '' : 's'))],
                find_brands($pdo, null, false, 500));
            return picker_result_from_list($rows, $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            require_once __DIR__ . '/features/catalog/queries.php';
            $b = is_int($id) ? find_brand($pdo, $id) : null;
            return $b === null ? null : $b['name'];
        },
    ],
    'supplier' => [
        'title' => 'Suppliers', 'noun' => 'supplier', 'params' => [],
        'gate' => static fn (array $p): ?string => has_right('inventory.read') ? null : 'You may not see the catalog.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/catalog/queries.php';
            $rows = array_map(static fn (array $s): array => ['id' => (int) $s['supplier_id'], 'label' => $s['name'], 'detail' => trim(str_replace('_', ' ', (string) $s['kind']) . ($s['website'] ? ' · ' . $s['website'] : ''))], suppliers_for_pick($pdo, '', 500));
            return picker_result_from_list($rows, $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            if (!is_int($id)) { return null; }
            $st = $pdo->prepare('SELECT name FROM mcp_suppliers WHERE supplier_id = :id');
            $st->execute(['id' => $id]);
            $n = $st->fetchColumn();
            return $n === false ? null : (string) $n;
        },
    ],
    'variant' => [
        'title' => 'Variants', 'noun' => 'variant', 'params' => ['single' => 'int'],
        'gate' => static fn (array $p): ?string => has_right('inventory.read') ? null : 'You may not see the catalog.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/catalog/queries.php';
            $rows = array_map(static fn (array $v): array => ['id' => $v['variant_id'], 'label' => $v['label'], 'detail' => $v['detail']], variant_pick($pdo, $q, 100, (int) ($params['single'] ?? 0) === 1));
            return picker_result_from_list($rows, '', $page);    // the query already filtered; the list is sliced
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            require_once __DIR__ . '/features/catalog/queries.php';
            $v = is_int($id) ? find_variant($pdo, $id) : null;
            return $v === null ? null : $v['sku'] . ' — ' . $v['product_name'] . ($v['size_name'] ? ', ' . $v['size_name'] : '');
        },
    ],
    'product' => [
        'title' => 'Products', 'noun' => 'product', 'params' => [],
        'gate' => static fn (array $p): ?string => has_right('inventory.read') ? null : 'You may not see the catalog.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/catalog/queries.php';
            $r = find_products($pdo, ['q' => $q, 'status' => 'all'], 25, ($page - 1) * 25);
            $rows = array_map(static fn (array $p): array => ['id' => $p['product_id'], 'label' => $p['name'], 'detail' => trim(($p['brand'] ?? '') . ' · ' . $p['product_type'] . ' · ' . $p['variant_count'] . ' variant' . ($p['variant_count'] === 1 ? '' : 's'), ' ·'), 'badge' => $p['status'] === 'active' ? null : $p['status'], 'color' => 'secondary'], $r['rows']);
            return ['rows' => $rows, 'total' => $r['total'], 'page' => $page, 'page_size' => 25, 'pages' => max(1, (int) ceil($r['total'] / 25))];
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            require_once __DIR__ . '/features/catalog/queries.php';
            $p = is_int($id) ? find_product($pdo, $id) : null;
            return $p === null ? null : $p['name'];
        },
    ],
];
