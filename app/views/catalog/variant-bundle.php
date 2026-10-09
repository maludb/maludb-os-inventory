<?php /** The bundle editor (screen `variant-bundle`): the component rows with the record picker, the sets each gives, Save posts the whole list. Data: variant, product, components, availability, here, notice */
$vid = $variant['variant_id'];
$rows = $components;
$comps = [];
foreach ($availability['components'] ?? [] as $c) { $comps[(int) $c['variant_id']] = $c; }
?>
<?= view('shared/header.php', ['id' => 'variant-bundle', 'title' => 'Components of ' . $variant['sku'], 'crumbs' => [['Home', '/'], ['Products', '/products/'], [$product['name'], '/products/' . $variant['product_id']], [$variant['sku'], '/variants/' . $vid], ['Bundle', null]], 'back' => back_link() ?? ['/variants/' . $vid, $variant['sku']]]) ?>
<div class="main-content" id="variant-bundle-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/variants/bundle.php" hx-post="/variants/bundle.php" hx-target="#flash" class="card" id="bundle-editor">
        <?= csrf_field() ?><input type="hidden" name="bundle" value="<?= $vid ?>">
        <div class="card-header"><h5 class="card-title mb-0">What the set holds</h5></div>
        <div class="card-body">
            <div class="fs-12 text-muted mb-2">A component is a variant of a single product (never another bundle); the quantity is how many of it one set holds. The whole list is saved.</div>
            <div id="bundle-rows">
                <?php foreach ($rows as $n => $c): ?><?= view('catalog/partials/bundle-row.php', ['n' => $n, 'variantId' => (int) $c['component_variant_id'], 'label' => $c['component_sku'] . ' — ' . $c['component_product'] . ($c['component_size'] ? ', ' . $c['component_size'] : ''), 'qty' => (int) $c['qty'], 'sets' => $comps[(int) $c['component_variant_id']] ?? null]) ?><?php endforeach; ?>
                <?php if ($rows === []): ?><?= view('catalog/partials/bundle-row.php', ['n' => 0, 'variantId' => null, 'label' => null, 'qty' => 1, 'sets' => null]) ?><?php endif; ?>
            </div>
            <button type="button" class="btn btn-light btn-touch" id="bundle-add-row-btn"><i class="feather-plus me-1"></i>Add a component</button>
            <template id="bundle-row-template"><?= view('catalog/partials/bundle-row.php', ['n' => '__N__', 'variantId' => null, 'label' => null, 'qty' => 1, 'sets' => null]) ?></template>
            <div class="fs-12 mt-3" id="bundle-sets">Sets available now: <strong><?= (int) ($availability['sets_available'] ?? 0) ?></strong> <?= state_chip($availability['state'] ?? null) ?></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="bundle-save-btn">Save the components</button><?= hx_link('/variants/' . $vid, 'Cancel', 'btn btn-light btn-touch', 'id="bundle-cancel-link"') ?></div>
    </form>
</div>
<script>
(function () {
    var btn = document.getElementById('bundle-add-row-btn'), rows = document.getElementById('bundle-rows'), tpl = document.getElementById('bundle-row-template');
    if (!btn || !rows || !tpl) return;
    btn.addEventListener('click', function () {
        var n = rows.querySelectorAll('.bundle-row').length;
        var html = tpl.innerHTML.split('__N__').join(String(n));
        rows.insertAdjacentHTML('beforeend', html);
        if (window.htmx) { htmx.process(rows.lastElementChild); }
    });
    rows.addEventListener('click', function (e) { var r = e.target.closest('.bundle-row-remove'); if (r) { e.preventDefault(); r.closest('.bundle-row').remove(); } });
})();
</script>
