<?php /** The manual source's listings editor (`manual-editor`, `manual-row-{n}`): one row = one listing with one variant. Data: listings, sizes */
$fields = [['title', 'Title', 'text'], ['brand', 'Brand', 'text'], ['product_type', 'Product type', 'text'], ['sku', 'SKU', 'text'], ['gtin', 'GTIN', 'text'], ['mpn', 'MPN', 'text'], ['size', 'Size', 'size'],
    ['price', 'Price', 'money'], ['compare_at_price', 'Compare-at', 'money'], ['cost', 'Cost', 'money'], ['qty', 'Qty', 'int'], ['availability', 'Availability', 'avail'], ['lead_time_days', 'Lead time (days)', 'int'],
    ['ships_how', 'Ships', 'text'], ['url', 'URL', 'url'], ['currency', 'Currency', 'text'], ['recorded_on', 'Recorded on', 'date'], ['note', 'Note', 'text']];
$rows = $listings === [] ? [[]] : $listings;
$row = static function (int $n, array $r) use ($fields, $sizes): string {
    $h = '<div class="border rounded p-2 mb-2 manual-row" id="manual-row-' . $n . '" data-manual-row><div class="row g-2">';
    foreach ($fields as [$k, $label, $type]) {
        $v = (string) ($r[$k] ?? ($k === 'gtin' ? ($r['barcode'] ?? '') : ($k === 'brand' ? ($r['vendor'] ?? '') : ($k === 'cost' ? ($r['cost_price'] ?? '') : ($k === 'title' ? ($r['name'] ?? '') : '')))));
        $name = 'settings_manual[listings][' . $n . '][' . $k . ']';
        $id = 'manual-row-' . $n . '-' . $k;
        $h .= '<div class="col-6 col-md-3 col-xl-2"><label class="form-label fs-11 text-muted mb-0" for="' . $id . '">' . e($label) . '</label>';
        if ($type === 'size') {
            $h .= '<select name="' . $name . '" id="' . $id . '" class="form-select btn-touch"><option value="">—</option>';
            foreach ($sizes as $s) { $h .= '<option value="' . e($s['name']) . '"' . (strcasecmp($v, (string) $s['name']) === 0 ? ' selected' : '') . '>' . e($s['name']) . '</option>'; }
            if ($v !== '' && !in_array(strtolower($v), array_map(static fn ($s) => strtolower((string) $s['name']), $sizes), true)) { $h .= '<option value="' . e($v) . '" selected>' . e($v) . '</option>'; }
            $h .= '</select>';
        } elseif ($type === 'avail') {
            $h .= '<select name="' . $name . '" id="' . $id . '" class="form-select btn-touch"><option value="">—</option>';
            foreach (['in_stock', 'limited', 'pre_order', 'back_order', 'out_of_stock', 'discontinued', 'unknown'] as $a) { $h .= '<option value="' . $a . '"' . ($v === $a ? ' selected' : '') . '>' . str_replace('_', ' ', $a) . '</option>'; }
            $h .= '</select>';
        } else {
            $it = ['money' => 'text" inputmode="decimal', 'int' => 'number" min="0', 'url' => 'url', 'date' => 'date', 'text' => 'text'][$type];
            $h .= '<input type="' . $it . '" name="' . $name . '" id="' . $id . '" class="form-control btn-touch" value="' . e($v) . '">';
        }
        $h .= '</div>';
    }
    return $h . '<div class="col-12 text-end"><button type="button" class="btn btn-light btn-touch" data-manual-remove id="manual-row-' . $n . '-remove">Remove this row</button></div></div></div>';
};
?>
<div id="manual-editor">
    <div class="fs-12 text-muted mb-2">One row is one listing with one variant — what a supplier said on the phone or in a price sheet, with the date. "Pull now" re-reads these rows.</div>
    <div id="manual-rows"><?php foreach (array_values($rows) as $n => $r): ?><?= $row($n, (array) $r) ?><?php endforeach; ?></div>
    <template id="manual-row-template"><?= $row(999999, []) ?></template>
    <button type="button" class="btn btn-light btn-touch" id="manual-add-row" data-manual-add>Add a row</button>
</div>
