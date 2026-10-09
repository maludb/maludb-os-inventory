<?php /** The catalog import (screen `catalog-import`): step 1 the file, step 2 the preview and the mapping, step 3 the result. Data: step, preview (columns, rows, upload), result, seesCost, notice */
$fields = ['name' => 'Product name (required)', 'sku' => 'SKU (required)', 'brand' => 'Brand', 'type' => 'Product type', 'size' => 'Size', 'gtin' => 'GTIN / UPC / EAN', 'mpn' => 'MPN', 'retail_price' => 'Retail price', 'map_price' => 'MAP', 'cost_price' => 'Cost', 'description' => 'Description', 'tags' => 'Tags', 'ships_how' => 'How it ships'];
if (!$seesCost) { unset($fields['cost_price']); }
$guess = static function (string $field, array $columns): string {
    $syn = ['name' => ['name', 'product', 'title', 'model'], 'sku' => ['sku', 'item', 'code'], 'brand' => ['brand', 'vendor', 'manufacturer'], 'type' => ['type', 'category'], 'size' => ['size'], 'gtin' => ['gtin', 'upc', 'ean', 'barcode'], 'mpn' => ['mpn', 'part'],
            'retail_price' => ['retail', 'price', 'msrp'], 'map_price' => ['map'], 'cost_price' => ['cost', 'wholesale'], 'description' => ['description', 'desc'], 'tags' => ['tags'], 'ships_how' => ['ships', 'shipping']];
    foreach ($columns as $i => $c) { foreach ($syn[$field] ?? [] as $w) { if (stripos($c, $w) !== false) { return (string) $i; } } }
    return '';
};
?>
<?= view('shared/header.php', ['id' => 'catalog-import', 'title' => 'Import the catalog', 'crumbs' => [['Home', '/'], ['Products', '/products/'], ['Import', null]], 'back' => back_link() ?? ['/products/', 'Products']]) ?>
<div class="main-content" id="catalog-import-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($step === 3): ?>
        <?= view('catalog/partials/import-result.php', ['result' => $result]) ?>
    <?php elseif ($step === 2): ?>
        <?= view('catalog/partials/import-preview.php', ['preview' => $preview, 'fields' => $fields, 'guess' => $guess]) ?>
    <?php else: ?>
        <form method="post" action="/products/import.php" enctype="multipart/form-data" class="card" id="import-form">
            <?= csrf_field() ?><input type="hidden" name="preview" value="1">
            <div class="card-header"><h5 class="card-title mb-0">Step 1 — the file</h5></div>
            <div class="card-body">
                <p class="fs-12 text-muted">A CSV of the business's SKUs: one row per sellable size with the product's name, brand, type, size, SKU, GTIN, MPN and prices. Up to 10 MB and 10,000 rows. Rows sharing a brand, name and type become one product.</p>
                <label class="form-label fs-12 text-muted" for="import-form-field-file">CSV file</label>
                <input type="file" name="file" id="import-form-field-file" class="form-control btn-touch" accept=".csv,text/csv" required>
            </div>
            <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch" id="import-form-read-btn">Read the file</button></div>
        </form>
    <?php endif; ?>
</div>
