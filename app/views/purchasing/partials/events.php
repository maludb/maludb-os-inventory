<?php /** The supplier's events (`po-events`), oldest first. Data: o, tz */ ?>
<div class="card mb-3" id="po-events"><div class="card-header fw-semibold">What the supplier said, and what we did</div><div class="card-body p-0"><div class="table-responsive">
    <table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>When</th><th>What</th><th>Source</th><th>Line</th><th>Detail</th><th class="d-none d-md-table-cell">By</th></tr></thead><tbody>
        <?php if ($o['events'] === []): ?><tr><td colspan="6" class="text-center text-muted py-3" id="po-events-empty">Nothing yet.</td></tr><?php endif; ?>
        <?php foreach ($o['events'] as $e): ?><tr id="po-event-<?= (int) $e['event_id'] ?>">
            <td class="text-nowrap"><?= e(format_ts($e['created_at'], $tz, 'M j, g:i A')) ?></td>
            <td><span class="badge bg-soft-secondary text-dark"><?= e(str_replace('_', ' ', $e['kind'])) ?></span></td>
            <td><?= po_source_chip($e['source']) ?></td>
            <td><?= $e['line_no'] !== null ? (int) $e['line_no'] : '<span class="text-muted">all</span>' ?></td>
            <td><?= e(implode(' · ', array_filter([$e['supplier_order_ref'] ? 'ref ' . $e['supplier_order_ref'] : null, $e['expected_on'] ? 'expected ' . format_date($e['expected_on']) : null,
                    $e['tracking_number'] ? trim(($e['carrier'] ?? '') . ' ' . $e['tracking_number']) : null, $e['reason'], $e['note']]))) ?></td>
            <td class="d-none d-md-table-cell"><?= $e['member_id'] === null ? '<span class="text-muted">the supplier</span>' : e((string) $e['member_name']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
</div></div></div>
