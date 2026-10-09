<?php
declare(strict_types=1);

/**
 * Partial updates for the agents' door (mcp-and-api.md §4): under an action token with
 * `_partial=1`, the fields the caller did not send are filled from the record's BASE row, and only
 * when the caller can see the record through its mcp_* view. A browser can never trigger this.
 */

/** endpoint → [table, the form's id field, read view, the view's id column]. Each slice adds its save endpoints here (the manifest's
 * `any field of`). Every record of Inventory is bigint-keyed. Empty until Phase 2: the first entries are the catalog's (slice 1),
 * e.g. '/products/save.php' => ['products', 'product', 'mcp_products', 'product_id']. */
const PARTIAL_UPDATE_TARGETS = [
    '/products/save.php' => ['products', 'product', 'mcp_products', 'product_id'],
    '/variants/save.php' => ['product_variants', 'variant', 'mcp_product_variants', 'variant_id'],
    '/brands/save.php' => ['brands', 'brand', 'mcp_brands', 'brand_id'],
    '/locations/save.php' => ['locations', 'location', 'mcp_locations', 'location_id'],
    '/receipts/save.php' => ['goods_receipts', 'receipt', 'mcp_goods_receipts', 'goods_receipt_id'],
    '/adjustments/save.php' => ['inventory_adjustments', 'adjustment', 'mcp_inventory_adjustments', 'adjustment_id'],
    '/transfers/save.php' => ['inventory_transfers', 'transfer', 'mcp_inventory_transfers', 'transfer_id'],
    '/sources/save.php' => ['sources', 'source', 'mcp_sources', 'source_id'],
];

function partial_update_prefill(PDO $pdo): void
{
    if (empty($GLOBALS['__action_authed']) || ($_POST['_partial'] ?? '') !== '1' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    unset($_POST['_partial']);
    $target = PARTIAL_UPDATE_TARGETS[(string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH)] ?? null;
    if ($target === null) {
        return;
    }
    [$table, $idField, $view, $viewId] = $target;
    $raw = $_POST[$idField] ?? null;
    $id = filter_var($raw, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 1) {
        return;
    }
    $visible = $pdo->prepare("SELECT 1 FROM {$view} WHERE {$viewId} = :id");
    $visible->execute(['id' => $id]);
    if ($visible->fetchColumn() === false) {
        return;
    }
    $pk = 'id';
    $st = $pdo->prepare("SELECT * FROM {$table} WHERE {$pk} = :id");
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return;
    }
    foreach ($row as $column => $value) {
        if ($column === $pk || $value === null || array_key_exists($column, $_POST)) {
            continue;
        }
        $_POST[$column] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
