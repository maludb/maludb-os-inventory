<?php
/** The morning note (`#home-note`): the seven headings, each a count that opens its list; a heading the person's rights withhold is not shown. Data: s */
$n = $s['note'];
$links = ['lines_at_risk' => '#home-at-risk', 'reorder' => '/stock/?below_reorder=1', 'prices' => '/reports/price-exceptions', 'unmatched' => '/matching/', 'sources' => '/sources/?health=failing', 'purchase_orders' => '/purchasing/?awaiting_ack=1', 'returns' => '/returns/?awaiting_disposition=1'];
$shown = array_filter($n['headings'], static fn (array $h): bool => !$h['withheld']);
?>
<div class="card" id="home-note">
    <div class="card-header"><h5 class="card-title mb-0"><i class="feather-sunrise me-2"></i>The morning note <span class="fs-12 text-muted fw-normal"><?= e($n['date']) ?></span></h5></div>
    <div class="card-body">
        <div class="row g-2" id="home-note-counts">
        <?php foreach ($shown as $k => $h): $c = (int) $h['count']; $href = $links[$k]; $danger = $c > 0 && in_array($k, ['lines_at_risk'], true); ?>
            <div class="col-6 col-md-4" id="home-note-<?= e($k) ?>">
                <?php if (str_starts_with($href, '#')): ?>
                    <a href="<?= e($href) ?>" class="d-flex align-items-center justify-content-between border rounded px-3 btn-touch text-dark text-decoration-none" id="home-note-<?= e($k) ?>-link"><span class="fs-12"><?= e(MORNING_HEADINGS[$k]) ?></span><span class="badge bg-soft-<?= $danger ? 'danger text-danger' : ($c > 0 ? 'warning text-warning' : 'secondary text-dark') ?> fs-14"><?= $c ?></span></a>
                <?php else: ?>
                    <?= hx_link($href, '<span class="fs-12">' . e(MORNING_HEADINGS[$k]) . '</span><span class="badge bg-soft-' . ($c > 0 ? 'warning text-warning' : 'secondary text-dark') . ' fs-14">' . $c . '</span>', 'd-flex align-items-center justify-content-between border rounded px-3 btn-touch text-dark text-decoration-none', 'id="home-note-' . e($k) . '-link"') ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php $po = $n['headings']['purchase_orders'] ?? null; if ($po !== null && !$po['withheld'] && ((int) ($po['untracked'] ?? 0) > 0 || (int) ($po['overdue'] ?? 0) > 0)): ?>
            <div class="fs-12 mt-2" id="home-note-po-detail"><?= (int) $po['awaiting_ack'] ?> awaiting acknowledgment ·
                <?= hx_link('/purchasing/?no_tracking=1', (int) $po['untracked'] . ' without tracking', 'fw-semibold') ?> · <?= (int) $po['overdue'] ?> overdue</div>
        <?php endif; ?>
        <?php if ($shown === []): ?><div class="text-muted fs-12" id="home-note-empty">The note's headings appear here when your role may read them.</div><?php endif; ?>
    </div>
</div>
