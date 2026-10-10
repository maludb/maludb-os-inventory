<?php /** The downloads (screen `export-list`, `export-card-{export}`). Data: cards, from, to, asOf, sources, seesCost */ ?>
<?= view('shared/header.php', ['id' => 'export-list', 'title' => 'Exports', 'crumbs' => [['Home', '/'], ['Exports', null]], 'back' => back_link()]) ?>
<div class="main-content" id="export-list-content">
    <div class="fs-12 text-muted mb-2">The accounting system's three files carry cost; the catalog and the listings carry cost only if your role sees cost. Nothing here carries a credential, a feed key or a customer's contact details.</div>
    <div class="row g-3" id="export-list-cards">
    <?php foreach ($cards as $c): ?>
        <div class="col-12 col-md-6 col-xl-4"><?= view('exports/partials/card.php', ['c' => $c, 'from' => $from, 'to' => $to, 'asOf' => $asOf, 'sources' => $sources]) ?></div>
    <?php endforeach; ?>
    </div>
</div>
