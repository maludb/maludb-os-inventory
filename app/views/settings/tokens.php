<?php /** The caller's tokens and the MCP URLs to connect their AI to (screen `tokens`). Data: rows, raw (just minted, once), tz */ $base = rtrim((string) env('APP_URL', ''), '/'); ?>
<?= view('shared/header.php', ['id' => 'tokens', 'title' => 'Tokens', 'crumbs' => [['Home', '/'], ['My settings', '/settings/'], ['Tokens', null]]]) ?>
<div class="main-content" id="tokens-content">
    <?php if ($raw): ?>
    <div class="row"><div class="col-lg-12"><div class="alert alert-warning" id="token-minted">
        <div class="fw-bold mb-1">Your new <?= e($raw['scope']) ?> token "<?= e($raw['label']) ?>" — copy it now; it is shown once.</div>
        <code class="user-select-all text-break" id="token-minted-value"><?= e($raw['raw']) ?></code>
    </div></div></div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-xl-5">
            <div class="card mb-0 h-100" id="tokens-connect"><div class="card-header"><h5 class="card-title mb-0">Connect your AI to Inventory</h5></div><div class="card-body fs-12">
                <p>Your own AI tools (Claude Desktop, Claude Code, an agent of yours) read Inventory's two memories with an <strong>mcp</strong> token as a Bearer token. They see exactly what you see — the same catalog, stock, sources and orders; cost only where you may read it. A token reads; nothing automated writes here but a hired agent through the operating system.</p>
                <div class="mb-2"><span class="text-muted">Records:</span> <code id="tokens-url-records" class="text-break"><?= e($base) ?>/mcp/records</code></div>
                <div class="mb-3"><span class="text-muted">Activity:</span> <code id="tokens-url-activity" class="text-break"><?= e($base) ?>/mcp/activity</code></div>
                <hr>
                <form method="post" action="/settings/tokens/mint.php" id="token-mint-form" class="row g-2 align-items-end" hx-post="/settings/tokens/mint.php" hx-target="#page-content" hx-swap="innerHTML">
                    <?= csrf_field() ?>
                    <div class="col-7 col-md-7"><label class="form-label fs-12 text-muted" for="token-mint-form-field-label">Label</label><input type="text" name="label" id="token-mint-form-field-label" class="form-control btn-touch" required maxlength="80" placeholder="Claude Desktop"></div>
                    <div class="col-3 col-md-3"><label class="form-label fs-12 text-muted" for="token-mint-form-field-scope">Scope</label><select name="scope" id="token-mint-form-field-scope" class="form-select btn-touch"><option value="mcp">mcp</option><option value="api">api</option></select></div>
                    <div class="col-2 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="token-mint-form-save-btn" aria-label="Mint"><i class="feather-key"></i></button></div>
                </form>
            </div></div>
        </div>
        <div class="col-xl-7">
            <div class="card mb-0 h-100" id="tokens-card">
                <div class="card-header"><h5 class="card-title mb-0">Your tokens</h5></div>
                <div class="card-body custom-card-action p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="tokens-table">
                    <thead class="thead-light"><tr><th>Label</th><th>Scope</th><th>Created</th><th class="d-none d-md-table-cell">Last used</th><th class="d-none d-md-table-cell">Expires</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="tokens-empty">No tokens yet.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $t): $id = (int) $t['id']; $state = token_state($t); $live = $state === 'live'; $dot = ['live' => 'success', 'revoked' => 'dark', 'expired' => 'secondary'][$state]; ?>
                        <tr id="token-row-<?= $id ?>" class="<?= $live ? '' : 'text-muted' ?>">
                            <td><span class="wd-10 ht-10 bg-<?= $dot ?> me-2 d-inline-block rounded-circle"></span><?= e($t['label']) ?><?= $live ? '' : ' <span class="badge bg-soft-' . $dot . ' text-' . $dot . '">' . $state . '</span>' ?></td>
                            <td><?= e($t['scope']) ?></td><td><?= e(format_ts($t['created_at'], $tz, 'M j, Y')) ?></td>
                            <td class="d-none d-md-table-cell"><?= e(format_ts($t['last_used_at'], $tz) ?: 'never') ?></td>
                            <td class="d-none d-md-table-cell"><?= e(format_ts($t['expires_at'], $tz, 'M j, Y') ?: 'never') ?></td>
                            <td class="text-end"><?php if ($t['revoked_at'] === null): ?><form method="post" action="/settings/tokens/revoke.php" hx-post="/settings/tokens/revoke.php" hx-confirm="Revoke the token <?= e($t['label']) ?>? Anything using it stops working." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="token" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" title="Revoke" id="token-row-<?= $id ?>-revoke" aria-label="Revoke <?= e($t['label']) ?>"><i class="feather-x"></i></button></form><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div></div>
            </div>
        </div>
    </div>
</div>
