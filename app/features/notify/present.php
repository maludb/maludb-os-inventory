<?php
declare(strict_types=1);

/** What a notice says and where it points (returns-worker.md "The links"). */

/** The record a notice names, as a local URL — null when it names none or one we have no page for. */
function notification_record_url(array $n, ?bool $admin = null): ?string
{
    $type = (string) ($n['record_type'] ?? '');
    $id = $n['record_id'] ?? null;
    $id = $id === null || $id === '' ? null : (int) $id;
    if ($type === '') { return null; }
    switch ($type) {
        case 'watch': return '/watches/';
        case 'morning_note':
            if ($id !== null && $id >= 10000101) {
                $s = sprintf('%08d', $id);
                return '/proposals/?date=' . substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
            }
            return '/proposals/';
        case 'buyer_proposal': return '/proposals/';
        case 'agent_dispatch': return ($admin ?? has_right('agents.settings')) ? '/admin/dispatches' : '/watches/';
        case 'source_pull':
            $sid = $id === null ? null : one_value(db(), 'SELECT source_id FROM source_pulls WHERE id = :id', ['id' => $id]);
            return $sid === null ? null : '/sources/' . (int) $sid . '/pulls';
        case 'listing_variant':
            if ($id === null) { return null; }
            $l = one_value(db(), 'SELECT listing_id FROM listing_variants WHERE id = :id', ['id' => $id]);
            return $l === null ? null : '/listings/' . (int) $l . '?listing_variant=' . $id;
    }
    if ($id === null) { return null; }
    if ($type === 'sales_order_line') {
        $o = one_value(db(), 'SELECT sales_order_id FROM sales_order_lines WHERE id = :id', ['id' => $id]);
        return $o === null ? null : '/orders/' . (int) $o . '#line-' . $id;
    }
    return record_url($type, $id);
}

/** The e-mail a notice becomes when the notice brought none of its own: the title, the body as paragraphs, the record's link as an absolute URL, the business's name — nothing of anybody else. */
function render_notice_mail(array $row, ?string $url): string
{
    return view('mail/notice.php', ['title' => (string) ($row['subject'] ?? $row['title'] ?? ''), 'body' => (string) ($row['body'] ?? ''), 'url' => $url,
        'business' => (string) (one_value(db(), 'SELECT business_name FROM inv_settings WHERE id = 1') ?? app_name())]);
}

/** The outbox row's status as a chip. */
function outbox_status_chip(string $status): string
{
    $c = ['queued' => 'secondary', 'sent' => 'success', 'skipped' => 'warning', 'failed' => 'danger'][$status] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e($status) . '</span>';
}
