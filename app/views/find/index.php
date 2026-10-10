<?php /** Find (screen `find`). Data: params, rows (null = no query ran), locations (best_stock_locations()), types, sizes, firmness, may (sell, watch, ask), seesCost, tz, here, notice */
$count = $rows === null ? null : count($rows);
?>
<?= view('shared/header.php', ['id' => 'find', 'title' => 'Find', 'crumbs' => [['Home', '/'], ['Find', null]]]) ?>
<div class="main-content" id="find-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/find" hx-get="/find" hx-target="#page-content" hx-swap="innerHTML" class="mb-2" id="find-form" role="search">
        <?php foreach (['size', 'type', 'firmness', 'price_min', 'price_max', 'in_stock', 'ships_within'] as $k): if (isset($params[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= e($params[$k]) ?>"><?php endif; endforeach; ?>
        <div class="d-flex gap-2">
            <input type="search" name="q" class="form-control btn-touch flex-grow-1" id="find-q" inputmode="search" autocomplete="off" autofocus maxlength="120"
                   placeholder="Product, brand, SKU, GTIN or MPN" aria-label="Find" value="<?= e($params['q'] ?? '') ?>">
            <button type="submit" class="btn btn-primary btn-touch" id="find-go"><i class="feather-search"></i><span class="d-none d-md-inline ms-1">Find</span></button>
        </div>
    </form>
    <?= view('find/partials/chips.php', ['params' => $params, 'types' => $types, 'sizes' => $sizes, 'firmness' => $firmness]) ?>
    <?php if ($rows === null): ?>
        <div class="text-muted py-4 text-center" id="find-hint">Type a product, brand, SKU, GTIN or MPN — or pick a size</div>
    <?php else: ?>
        <div class="fs-12 text-muted mb-2" id="find-count"><?= $count === 0 ? 'Nothing found — try fewer words or another chip.' : ($count >= FIND_LIMIT ? 'the first ' . FIND_LIMIT . ' — narrow it' : $count . ' variant' . ($count === 1 ? '' : 's')) ?></div>
        <?php if ($may['ask']): $aq = http_build_query(array_filter(['q' => $params['q'] ?? null, 'size' => $params['size'] ?? null])); ?>
            <div class="mb-3"><a href="/find/sources?<?= e($aq) ?>" hx-get="/find/sources?<?= e($aq) ?>" hx-target="#find-live" hx-swap="innerHTML" class="btn btn-outline-primary btn-touch w-100" id="find-ask-sources"><i class="feather-radio me-1"></i>Ask the sources now</a></div>
        <?php endif; ?>
        <div class="row g-3" id="find-results">
            <?php foreach ($rows as $r): ?><div class="col-12 col-md-6 col-xl-4"><?= view('find/partials/find-card.php', ['r' => $r, 'location' => $locations[$r['variant_id']] ?? null, 'may' => $may, 'seesCost' => $seesCost, 'here' => $here]) ?></div><?php endforeach; ?>
        </div>
        <div class="mt-3" id="find-live"></div>
    <?php endif; ?>
</div>
