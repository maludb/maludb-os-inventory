<?php /** One watch (`watch-row-{id}`). Data: w (watch_decode()), all, tz, here */
$id = $w['watch_id'];
?>
<tr id="watch-row-<?= $id ?>" class="<?= $w['active'] ? '' : 'text-muted' ?>">
    <td><?= hx_link(with_back($w['target_url'], $here), e($w['target_label']), '', 'id="watch-row-' . $id . '-target"') ?><?= $w['note'] ? '<div class="text-muted">' . e($w['note']) . '</div>' : '' ?></td>
    <td><?= watch_kind_chip($w['kind']) ?><?= $w['active'] ? '' : ' <span class="badge bg-soft-dark text-dark">cleared</span>' ?></td>
    <td class="text-end text-nowrap"><?= watch_threshold_words($w) ?></td>
    <td class="text-nowrap"><?= watch_now_dot($w['last_state'], 'watch-row-' . $id . '-now') ?></td>
    <td class="text-nowrap"><?= $w['fired_at'] ? e(as_of_words($w['fired_at'], $tz)) . ' × ' . $w['fire_count'] : '<span class="text-muted">never</span>' ?></td>
    <td><?= $w['text_me'] ? '<i class="feather-message-square" title="a text when it fires" aria-label="text me"></i>' : '' ?></td>
    <td><?= $w['agent_name'] ? '<span class="badge bg-soft-primary text-primary">' . e($w['agent_name']) . '</span>' : '' ?></td>
    <?php if ($all): ?><td><?= e($w['member_name'] ?? '') ?></td><?php endif; ?>
    <td class="text-end"><?php if ($w['active']): ?><form method="post" action="/watches/clear.php" hx-post="/watches/clear.php" hx-target="#flash" hx-confirm="Clear this watch?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="watch" value="<?= $id ?>"><button type="submit" class="btn btn-outline-secondary btn-sm btn-touch" id="watch-row-<?= $id ?>-clear">Clear</button></form><?php endif; ?></td>
</tr>
