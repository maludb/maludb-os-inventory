<?php /** The templates (screen `source-template-list`). Data: templates, mayWrite, here */ ?>
<?= view('shared/header.php', ['id' => 'source-template-list', 'title' => 'Source templates', 'crumbs' => [['Home', '/'], ['Sources', '/sources/'], ['Templates', null]], 'back' => back_link() ?? ['/sources/', 'Sources']]) ?>
<div class="main-content" id="source-template-list-content">
    <div class="alert alert-info fs-12" id="template-finding"><strong>What the survey found.</strong> Of fourteen brand sites, thirteen run on Shopify and every one answers <code>products.json</code> with its own 429 to a non-browser agent — but every one keeps an open product sitemap, so the marked-up site connector over the sitemap, daily, is the realistic door (or a dealer's Storefront token). The owner re-runs <code>bin/source_survey.php --record</code> from a shell to refresh the verdicts below.</div>
    <div class="row g-3" id="template-list">
        <?php foreach ($templates as $t): ?><div class="col-12 col-md-6 col-xl-4"><?= view('sources/partials/template-card.php', ['t' => $t, 'mayWrite' => $mayWrite]) ?></div><?php endforeach; ?>
    </div>
</div>
