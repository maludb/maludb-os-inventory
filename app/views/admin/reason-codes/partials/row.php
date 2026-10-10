<?php /** One reason code: a table row (`reason-code-row-{id}`) or a card (`reason-code-card-{id}`). Data: r, as */
$id = (int) $r['reason_code_id'];
$base = $as === 'row' ? 'reason-code-row-' . $id : 'reason-code-card-' . $id;
$edit = hx_link('/admin/reason-codes/' . $id . '/edit', 'Edit', 'btn btn-light btn-touch', 'id="' . $base . '-edit-btn"');
$chips = implode(' ', array_map(static fn (string $a): string => '<span class="badge bg-soft-info text-info">' . e($a) . '</span>', $r['applies_to']));
$active = $r['active'] ? '<span class="badge bg-soft-success text-success">active</span>' : '<span class="badge bg-soft-secondary text-secondary">inactive</span>';
?>
<?php if ($as === 'row'): ?>
<tr id="<?= $base ?>" class="<?= $r['active'] ? '' : 'text-muted' ?>">
    <td><code><?= e($r['code']) ?></code></td>
    <td class="fw-semibold"><?= e($r['name']) ?></td>
    <td><?= $chips ?></td>
    <td><?= $r['affects_qty'] ? 'yes' : 'no — a flag only' ?></td>
    <td><?= $active ?></td>
    <td class="text-end text-nowrap"><?= $edit ?></td>
</tr>
<?php else: ?>
<div class="card mb-2<?= $r['active'] ? '' : ' bg-soft-dark' ?>" id="<?= $base ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-semibold"><?= e($r['name']) ?></div><div><?= $active ?></div></div>
    <div class="fs-12 text-muted mt-1"><code><?= e($r['code']) ?></code> · <?= $r['affects_qty'] ? 'moves quantity' : 'a flag only' ?></div>
    <div class="mt-1"><?= $chips ?></div>
    <div class="mt-2"><?= $edit ?></div>
</div></div>
<?php endif; ?>
