<?php /** The sizes as a read-only table (`admin-settings-sizes-table`); admin.js re-renders it from the textarea as it changes. Data: sizes, inUse */ ?>
<div class="table-responsive"><table class="table table-sm fs-12 mb-0" id="admin-settings-sizes-table">
    <thead class="thead-light"><tr><th>Key</th><th>Name</th><th>Synonyms</th><th class="text-end">Variants</th></tr></thead>
    <tbody><?php foreach ($sizes as $s): ?><tr><td><code><?= e($s['key']) ?></code></td><td><?= e($s['name']) ?></td><td class="text-muted"><?= e(implode(', ', $s['synonyms'])) ?></td><td class="text-end"><?= (int) ($inUse[$s['key']] ?? 0) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
