<?php /** The attribute keys as a read-only table (`admin-settings-attributes-table`); admin.js re-renders it from the textarea. Data: attrs */ ?>
<div class="table-responsive"><table class="table table-sm fs-12 mb-0" id="admin-settings-attributes-table">
    <thead class="thead-light"><tr><th>Key</th><th>Name</th><th>Kind</th><th>Choices</th></tr></thead>
    <tbody><?php foreach ($attrs as $a): ?><tr><td><code><?= e($a['key']) ?></code></td><td><?= e($a['name']) ?></td><td><?= e($a['kind']) ?></td><td class="text-muted"><?= e(implode(', ', $a['choices'] ?? [])) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
