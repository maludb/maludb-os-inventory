<?php /** For the admin (`#home-admin`): the feed's usage today and the dispatches. Data: s */
$a = $s['admin'];
$d = $a['dispatches'];
?>
<div class="card" id="home-admin">
    <div class="card-header"><h5 class="card-title mb-0"><i class="feather-shield me-2"></i>For the admin</h5></div>
    <div class="list-group list-group-flush">
        <div class="list-group-item" id="home-admin-feed">
            <div class="fw-semibold"><?= hx_link('/admin/feed-keys/', 'The feed', 'text-dark') ?></div>
            <div class="fs-12 text-muted"><span id="home-admin-feed-calls"><?= (int) $a['feed']['calls_today'] ?></span> call<?= (int) $a['feed']['calls_today'] === 1 ? '' : 's' ?> today on <span id="home-admin-feed-keys"><?= (int) $a['feed']['live_keys'] ?></span> live key<?= (int) $a['feed']['live_keys'] === 1 ? '' : 's' ?></div>
        </div>
        <div class="list-group-item" id="home-admin-dispatches">
            <div class="fw-semibold"><?= hx_link('/admin/dispatches', 'Dispatches to agents', 'text-dark') ?></div>
            <div class="d-flex flex-wrap gap-2 mt-1">
                <?= hx_link('/admin/dispatches?status=sent', 'pending <b>' . (int) $d['sent'] . '</b>', 'badge bg-soft-secondary text-dark', 'id="home-admin-dispatch-sent"') ?>
                <span class="badge bg-soft-info text-info" id="home-admin-dispatch-running">running <b><?= (int) $d['running'] ?></b></span>
                <?= hx_link('/admin/dispatches?status=awaiting_approval', 'awaiting approval <b>' . (int) $d['awaiting_approval'] . '</b>', 'badge bg-soft-warning text-warning', 'id="home-admin-dispatch-awaiting"') ?>
                <?= hx_link('/admin/dispatches?status=failed', 'failed <b>' . (int) $d['failed'] . '</b>', 'badge bg-soft-' . ((int) $d['failed'] > 0 ? 'danger text-danger' : 'secondary text-dark'), 'id="home-admin-dispatch-failed"') ?>
            </div>
        </div>
    </div>
</div>
