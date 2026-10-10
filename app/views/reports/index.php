<?php /** The reports as cards (screen `report-list`, `report-card-{report}`). Data: cards [{report, title, blurb, who, href}] */ ?>
<?= view('shared/header.php', ['id' => 'report-list', 'title' => 'Reports', 'crumbs' => [['Home', '/'], ['Reports', null]], 'back' => back_link()]) ?>
<div class="main-content" id="report-list-content">
    <div class="fs-12 text-muted mb-2">Every report reads the database's own function as you: cost shows only where your role may see it. Each has filters and a CSV.</div>
    <div class="row g-3" id="report-list-cards">
    <?php foreach ($cards as $c): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100" id="report-card-<?= e($c['report']) ?>"><div class="card-body d-flex flex-column">
                <h5 class="card-title mb-1"><?= hx_link($c['href'], e($c['title']), 'text-dark', 'id="report-card-' . e($c['report']) . '-link"') ?></h5>
                <div class="fs-12 mb-2"><?= e($c['blurb']) ?></div>
                <div class="fs-11 text-muted mt-auto"><i class="feather-lock me-1"></i><?= e($c['who']) ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
