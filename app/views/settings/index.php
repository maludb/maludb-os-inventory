<?php
/**
 * My settings (screen `my-settings`, sso-shell.md): the prefs form (email on/off, text on/off, the kinds, the text kinds), the time zone
 * read-only (the directory's), the role badge and the roles held. Data: prefs, refusal, tz, roles, badge, osChannels, osProfile, notice, may
 */
$may = $may ?? ['settings' => false];
?>
<?= view('shared/header.php', ['id' => 'my-settings', 'title' => 'My settings', 'crumbs' => [['Home', '/'], ['My settings', null]]]) ?>
<div class="main-content" id="my-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="settings-tabs">
        <?= hx_link('/settings/', 'How I am told', 'btn btn-touch btn-primary', 'id="settings-tab-notify"') ?>
        <?= hx_link('/settings/tokens/', 'Tokens', 'btn btn-touch btn-light', 'id="settings-tab-tokens"') ?>
        <?= hx_link('/trail', 'My trail', 'btn btn-touch btn-light', 'id="settings-tab-trail"') ?>
        <?php if ($may['settings']): ?><?= hx_link('/admin/settings', "The business's settings", 'btn btn-touch btn-light', 'id="settings-tab-admin"') ?><?php endif; ?>
    </div>
    <div class="row g-3">
        <div class="col-lg-7">
            <form method="post" action="/settings/prefs.php" hx-post="/settings/prefs.php" hx-target="#flash" id="prefs-form">
                <?= csrf_field() ?><input type="hidden" name="return_to" value="/settings/">
                <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How you are told</h5></div><div class="card-body">
                    <input type="hidden" name="email_enabled" value="no"><input type="hidden" name="text_enabled" value="no">
                    <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-form-field-email-enabled"><input type="checkbox" class="form-check-input mt-0" name="email_enabled" value="yes" id="prefs-form-field-email-enabled" <?= $prefs['email_enabled'] ? 'checked' : '' ?>><i class="feather-mail"></i> Email</label>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-form-field-text-enabled"><input type="checkbox" class="form-check-input mt-0" name="text_enabled" value="yes" id="prefs-form-field-text-enabled" <?= $prefs['text_enabled'] ? 'checked' : '' ?>><i class="feather-message-square"></i> Text message</label>
                    <?php if ($refusal === 'no_verified_phone'): ?>
                        <div class="alert alert-warning fs-12 mb-2" id="prefs-text-note">Texts need a phone number verified in the operating system. <a href="<?= e($osChannels) ?>" class="alert-link" id="prefs-text-link">Add or verify your phone</a>. Until then you are emailed.</div>
                    <?php elseif ($refusal === 'opted_out'): ?>
                        <div class="alert alert-warning fs-12 mb-2" id="prefs-text-note">You turned texts off (or replied STOP). You can turn them back on in the operating system: <a href="<?= e($osChannels) ?>" class="alert-link" id="prefs-text-link">your channels</a>. Until then you are emailed.</div>
                    <?php elseif ($refusal === 'no_sender'): ?>
                        <div class="alert alert-secondary fs-12 mb-2" id="prefs-text-note">This business has not set up texting yet, so you are emailed.</div>
                    <?php else: ?>
                        <div class="fs-12 text-muted mb-2" id="prefs-text-hint">Texts go to the phone you verified in the operating system — Inventory never sees the number. <a href="<?= e($osChannels) ?>" id="prefs-text-link">Your channels</a></div>
                    <?php endif; ?>
                </div></div>
                <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Tell me when</h5></div><div class="card-body" id="prefs-kinds">
                    <input type="hidden" name="kinds[]" value="">
                    <?php foreach (NOTICE_KINDS as $k => $label): ?>
                        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-form-field-kinds-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="kinds[]" value="<?= e($k) ?>" id="prefs-form-field-kinds-<?= e($k) ?>" <?= in_array($k, $prefs['kinds'], true) ? 'checked' : '' ?>><?= e($label) ?></label>
                    <?php endforeach; ?>
                </div></div>
                <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Text me when</h5></div><div class="card-body" id="prefs-text-kinds">
                    <div class="fs-12 text-muted mb-2">Only when texts are on. Fewer is better: a text should mean "look now".</div>
                    <input type="hidden" name="text_kinds[]" value="">
                    <?php foreach (NOTICE_KINDS as $k => $label): ?>
                        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-form-field-text-kinds-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="text_kinds[]" value="<?= e($k) ?>" id="prefs-form-field-text-kinds-<?= e($k) ?>" <?= in_array($k, $prefs['text_kinds'], true) ? 'checked' : '' ?>><?= e($label) ?></label>
                    <?php endforeach; ?>
                </div></div>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="prefs-form-save-btn">Save</button>
            </form>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="settings-roles"><div class="card-header"><h5 class="card-title mb-0">Who you are here</h5></div><div class="card-body">
                <div class="mb-2"><span class="badge bg-soft-<?= e(role_badge_kind($badge)) ?> text-<?= role_badge_kind($badge) === 'light' ? 'dark' : e(role_badge_kind($badge)) ?>" id="settings-role-badge"><?= e($badge) ?></span></div>
                <div class="fs-12 text-muted" id="settings-roles-held"><?= $roles === [] ? 'No role here yet.' : 'Roles: ' . e(implode(', ', array_column($roles, 'name'))) ?>. A super-admin grants and changes roles in the operating system.</div>
                <div class="fs-12 text-muted mt-1" id="settings-cost-note"><?= $seesCost ? 'You see cost and margin.' : 'Cost and margin are withheld from you (the Buyer and the admin see them).' ?></div>
            </div></div>
            <div class="card mb-3" id="settings-timezone"><div class="card-header"><h5 class="card-title mb-0">Your time zone</h5></div><div class="card-body">
                <div class="fw-semibold" id="settings-timezone-value"><?= e($tz) ?></div>
                <div class="fs-12 text-muted">Times are shown in it. It comes from your profile in the operating system: <a href="<?= e($osProfile) ?>" id="settings-timezone-link">changed in the operating system</a>, it follows within a minute.</div>
            </div></div>
        </div>
    </div>
</div>
