<?php /** Find's chips (`find-chips`): one horizontal strip of links, each toggling its parameter. Data: params, types, sizes, firmness */
$chip = static function (string $id, string $label, array $set) use ($params): string {
    $active = true;
    foreach ($set as $k => $v) { if ((string) ($params[$k] ?? '') !== (string) ($v ?? '')) { $active = false; } }
    $url = find_toggle($params, $active ? array_map(static fn () => null, $set) : $set);
    return '<a href="' . e($url) . '" hx-get="' . e($url) . '" hx-target="#page-content" class="btn btn-sm text-nowrap btn-touch ' . ($active ? 'btn-primary' : 'btn-outline-secondary') . '" id="' . e($id) . '">' . e($label) . '</a>';
};
$bands = [1 => 'Under 500', 2 => '500–999', 3 => '1,000–1,999', 4 => '2,000 and over'];
?>
<div class="d-flex flex-nowrap overflow-auto gap-1 pb-2 mb-2 find-chips" id="find-chips">
    <?= $chip('find-chip-in-stock', 'In stock only', ['in_stock' => '1']) ?>
    <?php foreach (FIND_SHIPS_WITHIN as $d): ?><?= $chip('find-chip-ships-' . $d, 'Ships in ' . $d . ' days', ['ships_within' => (string) $d]) ?><?php endforeach; ?>
    <?php foreach ($sizes as $s): ?><?= $chip('find-chip-size-' . $s['key'], (string) $s['name'], ['size' => $s['key']]) ?><?php endforeach; ?>
    <?php foreach ($types as $t): ?><?= $chip('find-chip-type-' . $t['key'], (string) $t['name'], ['type' => $t['key']]) ?><?php endforeach; ?>
    <?php foreach ($firmness as $f): ?><?= $chip('find-chip-firmness-' . $f, ucfirst(str_replace('_', ' ', $f)), ['firmness' => $f]) ?><?php endforeach; ?>
    <?php foreach (FIND_PRICE_BANDS as $n => [$min, $max]): ?><?= $chip('find-chip-price-' . $n, $bands[$n], ['price_min' => $min, 'price_max' => $max]) ?><?php endforeach; ?>
</div>
