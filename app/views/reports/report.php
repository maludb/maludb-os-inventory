<?php
/** One report (screen `report`, `report-{report}`): the filter form (an offcanvas on a phone) and the results. Data: report, spec, params, errors, formHtml, resultsHtml */
$offcanvasBtn = '<button type="button" class="btn btn-light btn-touch d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#report-filters" aria-controls="report-filters" id="report-filters-toggle"><i class="feather-sliders me-1"></i>Filters</button>';
?>
<?= view('shared/header.php', ['id' => 'report-' . $report, 'title' => $spec['title'], 'crumbs' => [['Home', '/'], ['Reports', '/reports/'], [$spec['title'], null]], 'back' => back_link(), 'action' => $offcanvasBtn]) ?>
<div class="main-content" id="report-<?= e($report) ?>-content">
    <div class="fs-12 text-muted mb-2"><?= e($spec['blurb']) ?></div>
    <div class="row g-3">
        <div class="col-12">
            <div class="offcanvas-lg offcanvas-start" tabindex="-1" id="report-filters" aria-labelledby="report-filters-title">
                <div class="offcanvas-header d-lg-none"><h5 class="offcanvas-title" id="report-filters-title">Filters</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>
                <div class="offcanvas-body"><?= $formHtml ?></div>
            </div>
        </div>
        <div class="col-12"><?= $resultsHtml ?></div>
    </div>
</div>
