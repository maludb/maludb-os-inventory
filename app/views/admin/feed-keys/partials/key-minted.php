<?php /** The copy-once box (`feed-key-minted`): the raw key once, read-only, with Copy. Data: minted [key_id, raw, old_expires_at], label, tz */ ?>
<div class="alert alert-success" id="feed-key-minted" role="status">
    <div class="fw-bold mb-1"><?= $label !== '' ? 'The key "' . e($label) . '"' : 'The new key' ?></div>
    <div class="input-group mb-2">
        <input type="text" readonly class="form-control btn-touch user-select-all font-monospace" id="feed-key-minted-value" value="<?= e($minted['raw']) ?>" aria-label="The new key" autocomplete="off">
        <button type="button" class="btn btn-primary btn-touch" id="feed-key-minted-copy-btn" data-copy-from="feed-key-minted-value"><i class="feather-copy me-1"></i>Copy</button>
    </div>
    <div class="fs-12">Shown once — store it now; it cannot be shown again.</div>
    <?php if (!empty($minted['old_expires_at'])): ?><div class="fs-12 mt-1" id="feed-key-minted-old">The old key stops working at <?= e(format_ts((string) $minted['old_expires_at'], $tz, 'M j, Y g:i A')) ?>.</div><?php endif; ?>
</div>
