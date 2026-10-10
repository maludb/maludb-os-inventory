<?php
declare(strict_types=1);
/**
 * Action `purchase_order_link_rotate` (log `purchase_order.link_rotate`: number, link_id, mailed; confirm): the supplier's current link stops at once. On a DRAFT the new link's token is discarded (the next send mints again); on a sent, acknowledged or
 * partly received order the new link is minted and MAILED AT ONCE ("Updated link for PO-…") in the same transaction — a supplier with no email address is refused before anything changes. purchasing.write. Location /purchasing/{id}#po-link.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$o = po_or_404($pdo, request_po_id());
try {
    $r = inv_guard($pdo, static function () use ($pdo, $o): array {
        $r = rotate_supplier_link($pdo, (int) $o['purchase_order_id'], (int) current_member_id());
        po_log($pdo, 'purchase_order.link_rotate', $o, ['number' => $o['number'], 'link_id' => $r['link_id'], 'mailed' => $r['mailed']]);
        return $r;
    });
} catch (Throwable $e) { po_refused($e); }
inv_done('Stopped the supplier\'s link for ' . $o['number'], (int) $o['purchase_order_id'], inv_land('/purchasing/' . (int) $o['purchase_order_id'], $r['mailed'] ? 'link_mailed' : 'link_rotated', 'po-link'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $o['purchase_order_id'], 'link_id' => $r['link_id'], 'mailed' => $r['mailed']]);
