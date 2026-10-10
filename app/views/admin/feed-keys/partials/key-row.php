<?php /** One feed key: a table row (`feed-key-row-{id}`, as = row) or a card (`feed-key-card-{id}`, as = card). Data: k (a find_feed_keys row), tz, as, here, selected */
$id = (int) $k['key_id'];
$state = feed_key_state($k);
$dead = in_array($state, ['revoked', 'expired'], true);
$usageUrl = '/admin/feed-keys/?key=' . $id;
$base = $as === 'row' ? 'feed-key-row-' . $id : 'feed-key-card-' . $id;
$isSel = $selected !== null && (int) $selected['key_id'] === $id;
$btns = '';
if (!$dead) {                                       // a key that is revoked or expired is neither rotated nor revoked again
    $btns .= '<form method="post" action="/admin/feed-keys/rotate.php" hx-post="/admin/feed-keys/rotate.php" hx-target="#flash" hx-confirm="' . e('Rotate the key ' . $k['label'] . '? A new key is made and shown once; this one keeps working for the overlap and then stops.') . '" class="d-inline">'
          . csrf_field() . '<input type="hidden" name="key" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="' . $base . '-rotate-btn"><i class="feather-refresh-cw me-1"></i>Rotate</button></form> ';
}
if ($state !== 'revoked') {
    $btns .= '<form method="post" action="/admin/feed-keys/revoke.php" hx-post="/admin/feed-keys/revoke.php" hx-target="#flash" hx-confirm="' . e('Revoke the key ' . $k['label'] . '? Anything using it stops working at once.') . '" class="d-inline">'
          . csrf_field() . '<input type="hidden" name="key" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch text-danger" id="' . $base . '-revoke-btn"><i class="feather-x-circle me-1"></i>Revoke</button></form>';
}
$rotatedFrom = $k['rotated_from'] === null ? '' : 'rotated from ' . hx_link(with_back('/admin/feed-keys/?key=' . (int) $k['rotated_from'], $here), e((string) ($k['rotated_from_label'] ?? 'key ' . $k['rotated_from'])), '', 'id="' . $base . '-from"');
?>
<?php if ($as === 'row'): ?>
<tr id="<?= $base ?>" class="<?= $dead ? 'text-muted' : '' ?><?= $isSel ? ' table-active' : '' ?>">
    <td><?= hx_link($usageUrl, e($k['label']), 'fw-semibold', 'id="' . $base . '-label"') ?><?php if ($rotatedFrom !== ''): ?><div class="fs-11 text-muted"><?= $rotatedFrom ?></div><?php endif; ?></td>
    <td><?= feed_consumer_chip($k['consumer_kind']) ?></td>
    <td><?= $k['price_list_name'] !== null ? e($k['price_list_name']) . ($k['price_list_active'] ? '' : ' ' . feed_chip('inactive', 'secondary')) : '<span class="text-muted">—</span>' ?></td>
    <td class="text-end"><?= number_format((int) $k['rate_per_minute']) ?></td>
    <td class="text-end"><?= number_format((int) $k['rate_per_day']) ?></td>
    <td class="text-end text-nowrap" id="<?= $base ?>-today"><?= feed_calls_today_html($k) ?></td>
    <td class="text-nowrap"><?= $k['last_used_at'] ? e(format_ts($k['last_used_at'], $tz)) : '<span class="text-muted">never</span>' ?></td>
    <td><?= feed_key_status_chip($k, $tz, $base . '-status') ?></td>
    <td class="text-nowrap fs-11"><?= e((string) ($k['minter_name'] ?? 'someone who left')) ?><div class="text-muted"><?= e(format_ts($k['created_at'], $tz, 'M j, Y')) ?></div></td>
    <td class="text-end text-nowrap"><?= $btns ?></td>
</tr>
<?php else: ?>
<div class="card mb-2<?= $dead ? ' bg-soft-dark' : '' ?>" id="<?= $base ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-semibold"><?= hx_link($usageUrl, e($k['label']), 'text-dark', 'id="' . $base . '-label"') ?></div>
        <div><?= feed_key_status_chip($k, $tz, $base . '-status') ?></div>
    </div>
    <div class="chip-row mt-1"><?= feed_consumer_chip($k['consumer_kind']) ?><?php if ($k['price_list_name'] !== null): ?> <span class="fs-12"><?= e($k['price_list_name']) ?></span><?php endif; ?></div>
    <div class="fs-12 text-muted mt-2">
        <span id="<?= $base ?>-today"><?= feed_calls_today_html($k) ?></span> calls today · <?= number_format((int) $k['rate_per_minute']) ?> a minute<br>
        <?= $k['last_used_at'] ? 'last used ' . e(format_ts($k['last_used_at'], $tz)) : 'never used' ?><br>
        minted by <?= e((string) ($k['minter_name'] ?? 'someone who left')) ?>, <?= e(format_ts($k['created_at'], $tz, 'M j, Y')) ?><?php if ($rotatedFrom !== ''): ?><br><?= $rotatedFrom ?><?php endif; ?>
    </div>
    <?php if ($btns !== ''): ?><div class="d-flex gap-2 mt-2"><?= $btns ?></div><?php endif; ?>
</div></div>
<?php endif; ?>
