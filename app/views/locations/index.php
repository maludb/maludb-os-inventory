<?php /** The locations (screen `location-list`): cards. Data: locations, q, kind, archived, mayWrite, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'location-list', 'title' => 'Locations', 'crumbs' => [['Home', '/'], ['Locations', null]], 'back' => back_link(),
    'action' => $mayWrite ? hx_link('/locations/new', '<i class="feather-plus me-1"></i>New location', 'btn btn-primary btn-touch', 'id="location-list-new-btn"') : '']) ?>
<div class="main-content" id="location-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/locations/" hx-get="/locations/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="location-list-filters">
        <div class="col-12 col-md-5"><input type="search" name="q" class="form-control btn-touch" placeholder="Search locations" value="<?= e($q) ?>" id="location-list-filter-q" aria-label="Search locations"></div>
        <div class="col-6 col-md-3"><select name="kind" class="form-select btn-touch" id="location-list-filter-kind" aria-label="Kind"><option value="">Every kind</option><?php foreach (LOCATION_KINDS as $k => $w): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="location-list-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="archived" value="1" id="location-list-filter-archived" <?= $archived ? 'checked' : '' ?>>Archived too</label></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="location-list-filter-btn">Filter</button></div>
    </form>
    <div class="row g-3" id="location-list">
        <?php if ($locations === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted" id="location-list-empty"><?= $q !== '' || $kind !== '' ? 'No location matches.' : 'No locations yet' . ($mayWrite ? ' — make the first one.' : '.') ?></div></div></div><?php endif; ?>
        <?php foreach ($locations as $l): ?><div class="col-12 col-md-6 col-xl-4"><?= view('locations/partials/location-card.php', ['l' => $l, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
</div>
