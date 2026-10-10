<?php
declare(strict_types=1);

/**
 * Notes on any record (returns-worker.md "Notes and attachments"): the manifest's word for a record (`order`, `return`, `variant`…) mapped to the table's record_type, the view that decides
 * whether the caller may see the record, and its id column. A note is the record's own words, kept in `notes`; the log row says that one was written and how long it is — never what it says.
 */

/** word => [notes.record_type, view, id column]. Eleven records take notes. */
const NOTE_RECORD_TYPES = [
    'product'        => ['product', 'mcp_products', 'product_id'],
    'variant'        => ['product_variant', 'mcp_product_variants', 'variant_id'],
    'supplier'       => ['supplier', 'mcp_suppliers', 'supplier_id'],
    'source'         => ['source', 'mcp_sources', 'source_id'],
    'listing'        => ['listing', 'mcp_listings', 'listing_id'],
    'customer'       => ['customer', 'mcp_customers', 'customer_id'],
    'order'          => ['sales_order', 'mcp_sales_orders', 'sales_order_id'],
    'purchase_order' => ['purchase_order', 'mcp_purchase_orders', 'purchase_order_id'],
    'receipt'        => ['goods_receipt', 'mcp_goods_receipts', 'goods_receipt_id'],
    'return'         => ['return_authorization', 'mcp_return_authorizations', 'return_id'],
    'location'       => ['location', 'mcp_locations', 'location_id'],
];

/** The manifest's word (or the table's own record_type) → ['word', 'type', 'view', 'id_column']; a word that takes no notes is a 422. */
function note_record(string $word): array
{
    $word = strtolower(trim($word));
    if (!isset(NOTE_RECORD_TYPES[$word])) {
        foreach (NOTE_RECORD_TYPES as $w => $spec) { if ($spec[0] === $word) { $word = $w; break; } }
    }
    if (!isset(NOTE_RECORD_TYPES[$word])) { throw new DomainException('That is not a record that takes notes.'); }
    [$type, $view, $col] = NOTE_RECORD_TYPES[$word];
    return ['word' => $word, 'type' => $type, 'view' => $view, 'id_column' => $col];
}

/** The notes of a record, oldest first. [{note_id, member_id, member_name, body, created_at}] */
function find_notes(PDO $pdo, string $recordType, int $recordId): array
{
    $st = $pdo->prepare('SELECT note_id, member_id, member_name, body, created_at FROM mcp_notes WHERE record_type = :t AND record_id = :r ORDER BY created_at, note_id');
    $st->execute(['t' => $recordType, 'r' => $recordId]);
    return $st->fetchAll();
}

function find_note(PDO $pdo, int $noteId): ?array
{
    return one_row($pdo, 'SELECT note_id, record_type, record_id, member_id, member_name, body, created_at FROM mcp_notes WHERE note_id = :id', ['id' => $noteId]);
}

/** Add a note. Returns its id. */
function add_note(PDO $pdo, string $recordType, int $recordId, string $body, int $by): int
{
    $st = $pdo->prepare('INSERT INTO notes (record_type, record_id, member_id, body) VALUES (:t, :r, :m, :b) RETURNING id');
    $st->execute(['t' => $recordType, 'r' => $recordId, 'm' => $by, 'b' => $body]);
    return (int) $st->fetchColumn();
}

/** Delete a note; the record it was on, for the landing and the log. ['record_type', 'record_id', 'length']. */
function delete_note(PDO $pdo, int $noteId, int $by): array
{
    $n = $pdo->prepare('DELETE FROM notes WHERE id = :id RETURNING record_type, record_id, length(body) AS length');
    $n->execute(['id' => $noteId]);
    $r = $n->fetch();
    if ($r === false) { throw new DomainException('Not found.'); }
    return ['record_type' => $r['record_type'], 'record_id' => (int) $r['record_id'], 'length' => (int) $r['length']];
}
