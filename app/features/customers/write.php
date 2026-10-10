<?php
declare(strict_types=1);

/** Customers' writes: INSERT / UPDATE, archive / restore, delete. A duplicate live name (23505) is the handler's sentence; the caller's transaction wraps them. */

function save_customer(PDO $pdo, ?int $id, array $f, int $by): int
{
    $args = ['name' => $f['name'], 'legal' => $f['legal_name'], 'email' => $f['email'], 'phone' => $f['phone'], 'alt' => $f['phone_alt'], 'bill' => $f['billing_address'], 'ship' => $f['shipping_address'],
             'src' => $f['source'], 'tax' => $f['tax_rate_id'], 'terms' => $f['terms_days'], 'taxid' => $f['tax_id'], 'opt' => $f['email_opt_in'] ? 1 : 0, 'notes' => $f['notes']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO customers (name, legal_name, email, phone, phone_alt, billing_address, shipping_address, source, tax_rate_id, terms_days, tax_id, email_opt_in, notes, created_by)
                             VALUES (:name, :legal, :email, :phone, :alt, :bill, :ship, :src, :tax, :terms, :taxid, CAST(:opt AS boolean), :notes, :by) RETURNING id');
        $st->execute($args + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE customers SET name = :name, legal_name = :legal, email = :email, phone = :phone, phone_alt = :alt, billing_address = :bill, shipping_address = :ship, source = :src,
                          tax_rate_id = :tax, terms_days = :terms, tax_id = :taxid, email_opt_in = CAST(:opt AS boolean), notes = :notes WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

function archive_customer(PDO $pdo, int $id, bool $archived): void
{
    $pdo->prepare('UPDATE customers SET archived_at = ' . ($archived ? 'now()' : 'NULL') . ' WHERE id = :id')->execute(['id' => $id]);
}

function delete_customer(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $id]);
}
