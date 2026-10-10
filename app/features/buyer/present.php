<?php
declare(strict_types=1);

/** The Buyer's proposals as chips and as JSON (the view already strips the cost of a caller who may not see it). */

const PROPOSAL_ICONS = ['reorder' => 'feather-shopping-cart', 'match' => 'feather-link', 'price' => 'feather-tag', 'at_risk' => 'feather-alert-triangle', 'source' => 'feather-rss', 'po_overdue' => 'feather-clock', 'return' => 'feather-rotate-ccw'];

function proposal_kind_chip(string $kind): string
{
    return '<span class="badge bg-soft-primary text-primary"><i class="' . e(PROPOSAL_ICONS[$kind] ?? 'feather-inbox') . ' me-1"></i>' . e(PROPOSAL_KINDS[$kind] ?? $kind) . '</span>';
}

function proposal_status_chip(string $status): string
{
    $c = ['proposed' => 'info', 'accepted' => 'success', 'dismissed' => 'secondary'][$status] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e(PROPOSAL_STATUSES[$status] ?? $status) . '</span>';
}

/** Where a proposal's subject lives. */
function proposal_subject_url(array $p): ?string
{
    return match ($p['subject_type']) {
        'product_variant' => '/variants/' . $p['subject_id'], 'purchase_order' => '/purchasing/' . $p['subject_id'], 'source' => '/sources/' . $p['subject_id'], 'return' => '/returns/' . $p['subject_id'],
        'sales_order_line' => ($o = one_value(db(), 'SELECT sales_order_id FROM mcp_sales_order_lines WHERE line_id = :l', ['l' => $p['subject_id']])) === null ? null : '/orders/' . (int) $o,
        'listing_variant' => ($l = one_value(db(), 'SELECT listing_id FROM mcp_listing_variants WHERE listing_variant_id = :l', ['l' => $p['subject_id']])) === null ? null : '/listings/' . (int) $l . '?listing_variant=' . $p['subject_id'],
        default => null,
    };
}

/** Where the drafted record lives: a purchase order's page, the match queue for a proposed match. */
function proposal_drafted_url(array $p): ?string
{
    return match ($p['drafted_record_type']) { 'purchase_order' => '/purchasing/' . $p['drafted_record_id'], 'match_proposal' => '/matching/', default => null };
}

function present_buyer_proposal(array $p): array
{
    return ['proposal_id' => $p['proposal_id'], 'kind' => $p['kind'], 'status' => $p['status'], 'title' => $p['title'], 'subject_type' => $p['subject_type'], 'subject_id' => $p['subject_id'], 'subject' => $p['subject_label'],
            'detail' => $p['detail'], 'drafted_record_type' => $p['drafted_record_type'], 'drafted_record_id' => $p['drafted_record_id'], 'drafted' => $p['drafted_label'],
            'proposed_by' => $p['proposed_by'] === null ? null : (int) $p['proposed_by'], 'proposed_by_name' => $p['proposed_by_name'], 'note_date' => $p['note_date'],
            'decided_by' => $p['decided_by'] === null ? null : (int) $p['decided_by'], 'decided_at' => json_ts($p['decided_at']), 'created_at' => json_ts($p['created_at'])];
}

/** A heading of the morning note as JSON: counts, rows trimmed to the facts the screen shows (no contact detail ever reaches a row). */
function present_note(array $note): array
{
    $h = [];
    foreach ($note['headings'] as $k => $v) {
        $h[$k] = ['count' => $v['count'], 'withheld' => $v['withheld'], 'rows' => $v['rows']] + (isset($v['awaiting_ack']) ? ['awaiting_ack' => $v['awaiting_ack'], 'overdue' => $v['overdue'], 'untracked' => $v['untracked']] : []);
    }
    return ['date' => $note['date'], 'headings' => $h, 'counts' => morning_note_counts($note), 'proposals' => array_map('present_buyer_proposal', $note['proposals']),
            'drafted' => $note['drafted'], 'markdown' => $note['markdown']];
}
