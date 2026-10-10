<?php /** Connections — who outside reads us (screen `connection-list`, read-only). Data: shares, reads (declared), readers, rows, tz, osUrl */ ?>
<?= view('shared/header.php', ['id' => 'connection-list', 'title' => 'Connections', 'crumbs' => [['Home', '/'], ['Connections', null]], 'back' => back_link()]) ?>
<div class="main-content" id="connection-list-content">
    <div class="card mb-3" id="connections-shares"><div class="card-header"><h5 class="card-title mb-0">What siblings may read of ours</h5></div>
        <div class="card-body fs-12">
            <p class="text-muted">Five reads Inventory shares with the applications beside it, through the operating system and only over a connection a super-admin approved. They answer without cost, quantities, addresses or phone numbers except where the document says so.</p>
            <?php foreach ($shares as $i => $s): ?>
            <div class="border-top pt-2 mt-2" id="connections-share-<?= e($s['tool']) ?>">
                <div class="d-flex justify-content-between gap-2 flex-wrap"><span class="fw-semibold"><code><?= e($s['tool']) ?></code></span><span class="text-muted"><?= e($s['document']) ?></span></div>
                <div><?= e($s['description']) ?></div>
            </div>
            <?php endforeach; ?>
        </div></div>

    <div class="card mb-3" id="connections-readers"><div class="card-header"><h5 class="card-title mb-0">Who has read them</h5></div>
        <div class="card-body p-0">
            <?php if ($readers === []): ?><div class="p-3 text-muted fs-12" id="connections-readers-empty">Nothing read yet.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table mb-0 fs-12" id="connections-readers-table">
                <thead class="thead-light"><tr><th>Application</th><th>Read</th><th class="text-end">Calls</th><th class="text-end">Rows</th><th>Last read</th></tr></thead>
                <tbody><?php foreach ($readers as $n => $r): ?><?= view('admin/partials/reader-row.php', ['r' => $r, 'n' => $n + 1, 'tz' => $tz]) ?><?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div></div>

    <div class="card mb-3" id="share-reads"><div class="card-header"><h5 class="card-title mb-0">The last reads</h5></div>
        <div class="card-body p-0">
            <?php if ($rows === []): ?><div class="p-3 text-muted fs-12" id="share-reads-empty">Nothing read yet.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table mb-0 fs-12" id="share-reads-table">
                <thead class="thead-light"><tr><th>When</th><th>Application</th><th>Read</th><th class="text-end">Rows</th><th class="d-none d-md-table-cell">Request</th></tr></thead>
                <tbody><?php foreach ($rows as $r): ?><?= view('admin/partials/share-read-row.php', ['r' => $r, 'tz' => $tz]) ?><?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div></div>

    <div class="card mb-3" id="connections-reads"><div class="card-header"><h5 class="card-title mb-0">What Inventory reads of theirs</h5></div>
        <div class="card-body fs-12">
            <?php if ($reads === []): ?><span id="connections-reads-none">Inventory reads nothing of another application in version 1.</span>
            <?php else: ?><?php foreach ($reads as $r): ?><div class="border-top pt-2 mt-2"><code><?= e((string) ($r['tool'] ?? '')) ?></code> <?= e((string) ($r['provider'] ?? '')) ?></div><?php endforeach; ?><?php endif; ?>
        </div></div>

    <div class="alert alert-light border fs-12" id="connections-os-note">Approving a connection between applications is a super-admin's decision in the operating system (<code>bin/app_connection.php</code>).
        <?php if ($osUrl !== null): ?><a href="<?= e($osUrl) ?>" class="alert-link" id="connections-os-link" rel="noopener" target="_blank">Open the operating system's applications</a><?php endif; ?></div>
</div>
