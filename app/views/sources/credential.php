<?php /** Set or rotate a source's credential (screen `source-credential`; `source-credential-form`). A secret is never pre-filled. Data: s, credential, kinds */
$id = (int) $s['source_id'];
$fieldsOf = ['bearer' => [['token', 'Token', 'password']], 'basic' => [['username', 'Username', 'text'], ['password', 'Password', 'password']], 'sftp_password' => [['username', 'Username', 'text'], ['password', 'Password', 'password']],
    'sftp_key' => [['username', 'Username', 'text'], ['private_key', 'Private key', 'textarea'], ['public_key', 'Public key (for ssh2)', 'textarea'], ['passphrase', 'Passphrase', 'password']],
    'api_key' => [['api_key', 'API key', 'password']], 'oauth_client' => [['client_id', 'Client id', 'text'], ['client_secret', 'Client secret', 'password']], 'rsa_signing' => [['key_version', 'Key version', 'text'], ['private_key', 'Private key', 'textarea']]];
$confirm = $credential ? 'Replace the credential "' . $credential['label'] . '"?' : '';
?>
<?= view('shared/header.php', ['id' => 'source-credential-page', 'title' => 'Credential of ' . $s['name'], 'crumbs' => [['Home', '/'], ['Sources', '/sources/'], [$s['name'], '/sources/' . $id], ['Credential', null]], 'back' => back_link() ?? ['/sources/' . $id, $s['name']]]) ?>
<div class="main-content" id="source-credential-content">
    <?php if ($kinds === []): ?><div class="card"><div class="card-body text-muted" id="source-credential-none">This connector takes no credential.</div></div>
    <?php else: ?>
    <?php if ($credential): ?><div class="fs-12 mb-2" id="source-credential-current">Now: <span class="fw-semibold"><?= e($credential['label']) ?></span> · <?= e($credential['kind']) ?> · <code>…<?= e($credential['last4']) ?></code> — saving replaces it; the old one is deleted.</div><?php endif; ?>
    <form method="post" action="/sources/credential.php" hx-post="/sources/credential.php" hx-target="#flash" id="source-credential-form" class="card" autocomplete="off" <?= $confirm !== '' ? 'hx-confirm="' . e($confirm) . '"' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="source" value="<?= $id ?>">
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="source-credential-form-field-kind">Kind</label>
            <select name="kind" id="source-credential-form-field-kind" class="form-select btn-touch" data-credential-kind><?php foreach ($kinds as $k): ?><option value="<?= e($k) ?>"><?= e($k === 'bearer' ? 'bearer token' : str_replace('_', ' ', $k)) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted mt-3" for="source-credential-form-field-label">Label</label>
            <input type="text" name="label" id="source-credential-form-field-label" class="form-control btn-touch" maxlength="120" required placeholder="Storefront token (dealer)" value="">
            <?php $union = []; foreach ($kinds as $k) { foreach ($fieldsOf[$k] ?? [] as [$name, $label, $type]) { $union[$name] ??= ['label' => $label, 'type' => $type, 'kinds' => []]; $union[$name]['kinds'][] = $k; } } ?>
            <?php foreach ($union as $name => $f): $fid = 'source-credential-form-field-' . $name; ?>
            <div class="credential-field" data-kinds="<?= e(implode(' ', $f['kinds'])) ?>" id="<?= e($fid) ?>-wrap">
                <label class="form-label fs-12 text-muted mt-2" for="<?= e($fid) ?>"><?= e($f['label']) ?><?= count($kinds) > 1 ? ' <span class="fs-11">(' . e(implode(', ', array_map(static fn ($k) => str_replace('_', ' ', $k), $f['kinds']))) . ')</span>' : '' ?></label>
                <?php if ($f['type'] === 'textarea'): ?><textarea name="<?= e($name) ?>" id="<?= e($fid) ?>" class="form-control font-monospace" rows="4" autocomplete="off" spellcheck="false"></textarea>
                <?php else: ?><input type="<?= $f['type'] ?>" name="<?= e($name) ?>" id="<?= e($fid) ?>" class="form-control btn-touch" autocomplete="<?= $f['type'] === 'password' ? 'new-password' : 'off' ?>" value=""><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div class="fs-11 text-muted mt-3">Sealed on save. Afterwards only the label and the last four characters are shown — never the value.</div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="source-credential-form-save-btn">Save</button><?= hx_link('/sources/' . $id, 'Cancel', 'btn btn-light btn-touch', 'id="source-credential-form-cancel"') ?></div>
    </form>
    <?php endif; ?>
</div>
