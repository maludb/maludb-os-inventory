<?php /** The day's morning note above the cards (collapsed): the seven counts and the note's markdown. Data: note */
$counts = morning_note_counts($note);
?>
<details class="card mb-3" id="proposal-list-note"><summary class="card-body py-2 fw-semibold d-flex flex-wrap align-items-center gap-2" style="cursor: pointer"><i class="feather-sunrise"></i> Morning note — <?= e(format_date($note['date'])) ?>
    <span class="d-inline-flex flex-wrap gap-1 fw-normal"><?php foreach (MORNING_HEADINGS as $k => $label): ?><span class="badge bg-soft-<?= $counts[$k] === null ? 'secondary' : ((int) $counts[$k] > 0 ? 'warning' : 'success') ?> text-<?= $counts[$k] === null ? 'secondary' : ((int) $counts[$k] > 0 ? 'warning' : 'success') ?>" id="proposal-note-count-<?= e($k) ?>"><?= e($label) ?> <?= $counts[$k] === null ? '—' : (int) $counts[$k] ?></span><?php endforeach; ?></span></summary>
    <div class="card-body pt-0"><pre class="fs-12 mb-0" style="white-space: pre-wrap" id="proposal-list-note-text"><?= e($note['markdown']) ?></pre></div></details>
