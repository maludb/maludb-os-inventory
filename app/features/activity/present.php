<?php
declare(strict_types=1);

/**
 * The trail's sentences, links and JSON (sso-shell.md, completed by reports-admin.md "The trail"): one sentence per event of the manifest and of the worker, the doors, the feed and the kit; the record a row concerns as a link.
 * A sentence names the record by its number, SKU, name or label from `after` (the payload rules put them there) — never a value the rules forbid. An event outside the table reads "<actor> did <action> on <entity>"
 * and the proof lists it as a gap.
 */

/** A row's JSON: the actor, the sentence, the keys. */
function present_activity_row(array $r): array
{
    $link = activity_record_link($r);
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor' => ['member_id' => $r['actor_member_id'] === null ? null : (int) $r['actor_member_id'], 'display_name' => $r['actor_name'], 'is_agent' => (bool) $r['actor_is_agent']],
            'source' => $r['source'], 'action' => $r['action'], 'sentence' => activity_sentence($r), 'entity_type' => $r['entity_type'],
            'entity_id' => $r['entity_id'] === null ? null : (int) $r['entity_id'],
            'source_id' => $r['source_id'] === null ? null : (int) $r['source_id'], 'sales_order_id' => $r['sales_order_id'] === null ? null : (int) $r['sales_order_id'],
            'purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id'], 'location_id' => $r['location_id'] === null ? null : (int) $r['location_id'],
            'agent_run_id' => $r['agent_run_id'] === null ? null : (int) $r['agent_run_id'], 'url' => $link['href'] ?? null];
}

/** The record a row concerns, as a link: ['href', 'label'] (also at 0 and 1) or null. The record's own page first, then the audit keys (an order, a purchase order, a source, a location, a feed key). */
function activity_record_link(array $r): ?array
{
    $action = (string) ($r['action'] ?? '');
    if (str_ends_with($action, '.delete') || $action === 'screen.view') {
        return null;                                                  // the record is gone — or the row is only a screen that was opened
    }
    $mk = static fn (string $href, string $label): array => [0 => $href, 1 => $label, 'href' => $href, 'label' => $label];
    $type = (string) ($r['entity_type'] ?? '');
    $id = $r['entity_id'] ?? null;
    if ($id !== null) {
        $own = match ($type) {
            'tax_rate' => '/admin/tax-rates/' . (int) $id . '/edit',
            'reason_code' => '/admin/reason-codes/' . (int) $id . '/edit',
            'price_list' => '/admin/price-lists/' . (int) $id . '/edit',
            'listing_variant' => null,
            default => record_url($type, $id),
        };
        if ($own !== null) { return $mk($own, str_replace('_', ' ', $type)); }
    }
    if (in_array($type, ['sequence', 'settings'], true)) { return $mk($type === 'sequence' ? '/admin/sequences' : '/admin/settings', $type); }
    if (($r['sales_order_id'] ?? null) !== null) { return $mk('/orders/' . (int) $r['sales_order_id'], 'order'); }
    if (($r['purchase_order_id'] ?? null) !== null) { return $mk('/purchasing/' . (int) $r['purchase_order_id'], 'purchase order'); }
    if (($r['source_id'] ?? null) !== null) { return $mk('/sources/' . (int) $r['source_id'], 'source'); }
    if (($r['location_id'] ?? null) !== null) { return $mk('/locations/' . (int) $r['location_id'], 'location'); }
    if (($r['token_id'] ?? null) !== null) { return $mk('/admin/feed-keys/?key=' . (int) $r['token_id'], 'feed key'); }
    return null;
}

/** The row's `after` as an array. */
function activity_after(array $r): array
{
    return is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
}

/** Who acted, in words: a person's name, an agent chipped "(agent)", the worker, the feed by its key's label, a door ("the customer" / "the supplier"). */
function activity_actor(array $r): string
{
    $after = activity_after($r);
    $action = (string) ($r['action'] ?? '');
    if (($r['source'] ?? '') === 'cron' && ($r['actor_name'] ?? null) === null) { return 'the worker'; }
    if (($r['source'] ?? '') === 'feed') { return 'the feed' . (isset($after['label']) ? ' (' . mb_substr((string) $after['label'], 0, 40) . ')' : ''); }
    if (($r['source'] ?? '') === 'mcp' && ($r['actor_name'] ?? null) === null) { return 'a sibling application'; }
    if (($r['source'] ?? '') === 'portal') {
        return str_starts_with($action, 'purchase_order.') || str_starts_with($action, 'supplier') ? 'the supplier' : 'the customer';
    }
    $who = (string) ($r['actor_name'] ?? app_name());
    return !empty($r['actor_is_agent']) ? $who . ' (agent)' : $who;
}

/** The words of every event: the verb phrase (the record's number, name or SKU follows). */
const ACTIVITY_WORDS = [
    // the kit and the shell
    'member.sign_on' => 'signed on', 'member.sign_on.refused' => 'was refused at sign-on', 'member.sign_out' => 'signed out', 'member.refused' => 'was refused', 'member.sign_out.refused' => 'sent a sign-out notice that was refused',
    'directory.sync' => 'refreshed the directory', 'directory.sync.failed' => 'could not refresh the directory', 'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token',
    'prefs.save' => 'changed how they are told', 'notification.read' => 'read their notifications', 'notification.send' => 'sent a notice', 'notification.skip' => 'skipped a notice', 'notification.fail' => 'could not send a notice',
    'assistant.ask' => 'asked the expert', 'worker.pass' => 'ran a pass', 'share.read' => 'read shared data', 'attachment.add' => 'attached a file', 'attachment.delete' => 'removed an attachment',
    'note.add' => 'added a note', 'note.delete' => 'removed a note',
    // the catalog
    'brand.save' => 'saved the brand', 'product_type.save' => 'saved the product type', 'product.create' => 'created the product', 'product.update' => 'changed the product', 'product.discontinue' => 'discontinued the product',
    'product.delete' => 'deleted the product', 'product.import' => 'imported products', 'product.image_add' => 'added a picture to the product', 'product.image_remove' => 'removed a picture from the product',
    'variant.create' => 'created the variant', 'variant.update' => 'changed the variant', 'variant.delete' => 'deleted the variant', 'variant.price_set' => 'set a price on the variant', 'variant.identifier_add' => 'added an identifier to the variant',
    'variant.identifier_remove' => 'removed an identifier from the variant', 'variant.bundle_set' => 'set the bundle\'s components of',
    // stock
    'location.create' => 'created the location', 'location.update' => 'changed the location', 'location.archive' => 'archived the location',
    'stock.adjust' => 'posted the adjustment', 'stock.adjustment_cancel' => 'cancelled the adjustment', 'stock.adjustment_draft' => 'drafted the adjustment', 'stock.adjustment_line' => 'set a line on the adjustment', 'stock.adjustment_line_remove' => 'removed a line from the adjustment',
    'stock.count_cancel' => 'cancelled the count', 'stock.count_line' => 'counted a line of', 'stock.count_post' => 'posted the count', 'stock.count_start' => 'started the count', 'stock.floor_model' => 'moved a floor model',
    'stock.receipt_cancel' => 'cancelled the receipt', 'stock.receipt_draft' => 'drafted the receipt', 'stock.receipt_line' => 'set a line on the receipt', 'stock.receipt_line_remove' => 'removed a line from the receipt', 'stock.receive' => 'received goods on',
    'stock.reverse' => 'reversed a movement of', 'stock.transfer_cancel' => 'cancelled the transfer', 'stock.transfer_draft' => 'drafted the transfer', 'stock.transfer_line' => 'set a line on the transfer', 'stock.transfer_line_remove' => 'removed a line from the transfer',
    'stock.transfer_receive' => 'received the transfer', 'stock.transfer_send' => 'sent the transfer',
    // sources, listings, offers, watches, suppliers
    'source.create' => 'added the source', 'source.update' => 'changed the source', 'source.delete' => 'removed the source', 'source.pause' => 'paused the source', 'source.resume' => 'resumed the source', 'source.probe' => 'probed the source',
    'source.schedule_set' => 'set the schedule of the source', 'source.credential_set' => 'set the credential of the source', 'source.search' => 'searched the source', 'source.pull_start' => 'started a pull of the source',
    'source.pull_done' => 'finished a pull of the source', 'source.pull_fail' => 'failed a pull of the source', 'source.pull_blocked' => 'was blocked pulling the source',
    'listing.new' => 'found a new listing', 'listing.changed' => 'saw a listing change', 'listing.removed' => 'saw a listing removed', 'listing.accept' => 'accepted a match for', 'listing.dismiss' => 'dismissed a match for', 'listing.forget' => 'forgot the listing',
    'listing.match' => 'matched the listing', 'listing.propose' => 'proposed a match for', 'listing.unmatch' => 'unmatched the listing', 'offer.change' => 'saw an offer change',
    'watch.set' => 'set a watch', 'watch.clear' => 'cleared a watch', 'watch.fire' => 'fired a watch',
    'supplier.create' => 'added the supplier', 'supplier.update' => 'changed the supplier', 'supplier.archive' => 'archived the supplier', 'supplier.item_save' => 'saved a price-sheet item of the supplier', 'supplier.item_remove' => 'removed a price-sheet item of the supplier',
    'supplier.message' => 'sent a message to the supplier',
    // orders
    'customer.create' => 'added the customer', 'customer.update' => 'changed the customer', 'customer.archive' => 'archived the customer', 'customer.delete' => 'deleted the customer',
    'order.quote' => 'quoted order', 'order.update' => 'changed order', 'order.line_add' => 'added a line to order', 'order.line_update' => 'changed a line of order', 'order.line_cancel' => 'cancelled a line of order',
    'order.line_fulfilment_set' => 'set how a line is filled on order', 'order.confirm' => 'confirmed order', 'order.send' => 'sent order', 'order.payment' => 'recorded a payment on order', 'order.refund' => 'recorded a refund on order',
    'order.ship' => 'shipped order', 'order.deliver' => 'delivered order', 'order.close' => 'closed order', 'order.cancel' => 'cancelled order', 'order.link_rotate' => 'renewed the customer\'s link for order', 'order.notify' => 'told the customer about order',
    'order.customer_view' => 'opened their page for order',
    // purchasing
    'purchase_order.draft' => 'drafted purchase order', 'purchase_order.update' => 'changed purchase order', 'purchase_order.line_add' => 'added a line to purchase order', 'purchase_order.line_update' => 'changed a line of purchase order',
    'purchase_order.line_remove' => 'removed a line from purchase order', 'purchase_order.place' => 'placed purchase order', 'purchase_order.send' => 'sent purchase order', 'purchase_order.receive' => 'received goods on purchase order',
    'purchase_order.close' => 'closed purchase order', 'purchase_order.cancel' => 'cancelled purchase order', 'purchase_order.link_rotate' => 'renewed the supplier\'s link for purchase order', 'purchase_order.supplier_view' => 'opened their page for purchase order',
    'purchase_order.supplier_ack' => 'acknowledged purchase order', 'purchase_order.supplier_decline' => 'declined purchase order', 'purchase_order.supplier_tracking' => 'gave tracking for purchase order',
    // returns
    'return.request' => 'requested return', 'return.update' => 'changed return', 'return.line_add' => 'added a line to return', 'return.line_remove' => 'removed a line from return', 'return.approve' => 'approved return', 'return.deny' => 'denied return',
    'return.receive' => 'received return', 'return.disposition' => 'dispositioned a line of return', 'return.close' => 'closed return',
    // agents, the feed, reports and the admin
    'agent.dispatch' => 'handed a task to an agent', 'agent.reply' => 'received an agent\'s reply', 'agent.fail' => 'could not reach an agent', 'buyer.accept' => 'accepted a proposal', 'buyer.dismiss' => 'dismissed a proposal',
    'buyer.note' => 'sent the morning note', 'buyer.propose' => 'made a proposal',
    'feed.key_mint' => 'made the feed key', 'feed.key_revoke' => 'revoked the feed key', 'feed.key_rotate' => 'rotated the feed key', 'feed.read' => 'answered an availability call', 'feed.rate_limited' => 'refused an availability call (too many)',
    'price_list.save' => 'saved the price list', 'settings.save' => 'saved the settings', 'sequence.set' => 'set the sequence', 'tax_rate.save' => 'saved the tax rate', 'tax_rate.archive' => 'archived the tax rate', 'reason_code.save' => 'saved the reason code',
    'report.run' => 'ran the report', 'export.download' => 'downloaded the export',
];

/** The record's name from the payload: its number, SKU, name, title or label; else an empty string. */
function activity_subject(array $after): string
{
    foreach (['number', 'order_number', 'purchase_order_number', 'return_number', 'sku', 'name', 'title', 'label', 'code', 'kind', 'filename'] as $k) {
        if (isset($after[$k]) && is_scalar($after[$k]) && trim((string) $after[$k]) !== '') {
            return mb_substr((string) $after[$k], 0, 80);
        }
    }
    return '';
}

/** One line for a row, in words: "Nora confirmed order SO-00001". */
function activity_sentence(array $r): string
{
    $who = activity_actor($r);
    $after = activity_after($r);
    $action = (string) ($r['action'] ?? '');
    if ($action === 'screen.view') {
        $screen = ($r['screen'] ?? '') !== '' ? (string) $r['screen'] : (string) ($after['screen'] ?? '');
        return $who . ' opened ' . ($screen !== '' ? str_replace('-', ' ', $screen) : 'a screen');
    }
    $subject = activity_subject($after);
    if ($action === 'report.run') { $subject = (string) ($after['report'] ?? $subject); }
    if ($action === 'export.download') { $subject = (string) ($after['export'] ?? $subject) . (isset($after['format']) ? ' (' . $after['format'] . ')' : ''); }
    if ($action === 'sequence.set') { $subject = (string) ($after['kind'] ?? $subject); }
    if ($action === 'worker.pass' || $action === 'directory.sync') { $subject = ''; }
    if ($action === 'share.read') { $subject = isset($after['tool']) ? (string) $after['tool'] : ''; }
    if ($action === 'settings.save') { $subject = isset($after['changed']) && is_array($after['changed']) ? implode(', ', array_slice(array_map('strval', $after['changed']), 0, 4)) : ''; }
    if (isset(ACTIVITY_WORDS[$action])) {
        $anon = ['worker.pass', 'directory.sync', 'prefs.save', 'notification.read', 'settings.save', 'share.read', 'member.sign_on', 'member.sign_out', 'member.refused', 'member.sign_on.refused', 'member.sign_out.refused'];
        $tail = $subject !== '' ? ' ' . $subject : (($r['entity_id'] ?? null) !== null && !in_array($action, $anon, true) ? ' #' . (int) $r['entity_id'] : '');
        return $who . ' ' . ACTIVITY_WORDS[$action] . $tail;
    }
    return $who . ' did ' . $action . ' on ' . (($r['entity_type'] ?? '') !== '' ? $r['entity_type'] : 'a record') . (($r['entity_id'] ?? null) !== null ? ' #' . (int) $r['entity_id'] : '');
}
