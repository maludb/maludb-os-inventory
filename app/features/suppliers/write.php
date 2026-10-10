<?php
declare(strict_types=1);

/** Suppliers' writes: INSERT / UPDATE, archive / restore, and the one message to a supplier (MaluMail inline, a `note` event on the purchase order it names). A duplicate live name (23505) is inv_guard()'s sentence. */

const SUPPLIER_COLUMN_MAP = ['name' => 'name', 'kind' => 'kind', 'contact_name' => 'contact_name', 'email' => 'email', 'phone' => 'phone', 'address' => 'address', 'website' => 'website',
                             'account_number' => 'account_number', 'terms' => 'terms', 'dropships' => 'dropships', 'lead_time_days' => 'lead_time_days', 'order_method' => 'order_method',
                             'order_email' => 'order_email', 'portal_url' => 'portal_url', 'min_order' => 'min_order', 'notes' => 'notes'];

/** INSERT (id null) or UPDATE every column of the supplier's form. Returns the id. */
function save_supplier(PDO $pdo, ?int $id, array $f, int $by): int
{
    $args = [];
    foreach (SUPPLIER_COLUMN_MAP as $k => $col) { $args[$k] = $f[$k] ?? null; }
    $args['dropships'] = !empty($args['dropships']) ? 'true' : 'false';
    if ($id === null) {
        $cols = array_keys(SUPPLIER_COLUMN_MAP);
        $st = $pdo->prepare('INSERT INTO suppliers (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(static fn (string $c): string => $c === 'dropships' ? 'CAST(:dropships AS boolean)' : ':' . $c, $cols)) . ') RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $set = [];
    foreach (array_keys(SUPPLIER_COLUMN_MAP) as $c) { $set[] = $c . ' = ' . ($c === 'dropships' ? 'CAST(:dropships AS boolean)' : ':' . $c); }
    $pdo->prepare('UPDATE suppliers SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

function archive_supplier(PDO $pdo, int $id, bool $active): void
{
    $pdo->prepare('UPDATE suppliers SET active = CAST(:a AS boolean) WHERE id = :id')->execute(['a' => $active ? 'true' : 'false', 'id' => $id]);
}

/**
 * One email to the supplier's order address (else its email) through MaluMail, inline; with a purchase order named, the number is prefixed to the subject and a `note` event
 * (source `email`, "emailed: <subject>") is written on it. Returns ['message_id', 'to_kind' => 'order_email'|'email', 'subject', 'purchase_order_id'].
 * A refused address is a DomainException (422), a transport failure OrderMailUnavailable (503); no address at all is a 422.
 */
function message_supplier(PDO $pdo, int $supplierId, string $subject, string $body, ?int $purchaseOrderId, int $by): array
{
    $s = one_row($pdo, 'SELECT name, email, order_email FROM suppliers WHERE id = :id', ['id' => $supplierId]);
    if ($s === null) { throw new DomainException('Not found.'); }
    $to = (string) ($s['order_email'] ?? '') !== '' ? (string) $s['order_email'] : (string) ($s['email'] ?? '');
    if ($to === '') { throw new DomainException('The supplier has no email address.'); }
    $po = null;
    if ($purchaseOrderId !== null) {
        $po = one_row($pdo, 'SELECT id, number, supplier_id FROM purchase_orders WHERE id = :id', ['id' => $purchaseOrderId]);
        if ($po === null || (int) $po['supplier_id'] !== $supplierId) { throw new DomainException('That purchase order is not this supplier\'s.'); }
    }
    $settings = order_mail_settings($pdo);
    $mail = supplier_message_mail(['name' => $s['name']], $settings, $subject, $body, $po);
    $messageId = order_mail_send(order_mail_payload($mail, $to, $settings));
    if ($po !== null) {
        $pdo->prepare("INSERT INTO purchase_order_events (purchase_order_id, kind, source, note, member_id) VALUES (:po, 'note', 'email', :n, :by)")
            ->execute(['po' => $po['id'], 'n' => mb_substr('emailed: ' . $subject, 0, 300), 'by' => $by]);
    }
    return ['message_id' => $messageId, 'to_kind' => (string) ($s['order_email'] ?? '') !== '' ? 'order_email' : 'email', 'subject' => $mail['subject'], 'purchase_order_id' => $po === null ? null : (int) $po['id']];
}
