<?php
declare(strict_types=1);

/**
 * The admin's reads and writes (reports-admin.md "The settings", "Sequences, tax rates, reason codes"): the one `inv_settings` row read as the writer (the view lacks `id` — DECISION 12), the document sequences, the tax rates and the reason
 * codes. The database is the referee: a setting's bounds are the table's CHECKs (the handler mirrors each so a person reads a field's name, never a constraint's); a sequence only rises; one default tax rate (the partial unique index);
 * a reason's code is unique. Writes run inside the handler's transaction and return what changed; the handler logs.
 */

/** The seven groups of design §9, in order: key => [title, one line, columns]. */
function settings_groups(): array
{
    return [
        'business' => ['The business', 'Named on the doors, the feed and the crawler\'s user-agent.', ['business_name', 'business_contact_email', 'business_phone', 'business_address', 'currency', 'units', 'timezone']],
        'doors' => ['Who sees what, and the doors', 'The cost wall for Sales, whose phone the supplier sees, how long a link lives.', ['sales_sees_cost', 'supplier_sees_phone', 'feed_shows_quantity', 'order_link_days', 'supplier_link_days']],
        'feed' => ['The feed', 'The availability API\'s limits for every key that has none of its own.', ['feed_rate_per_minute', 'feed_rate_per_day', 'key_rotation_overlap_hours']],
        'vocabulary' => ['The catalog\'s vocabulary', 'Sizes with their synonyms and the attribute keys a product may carry.', ['sizes', 'attribute_keys']],
        'buying' => ['Buying and the Buyer', 'Reorder defaults, what moves a cost or undercuts a price, and who gets the morning note.', ['reorder_point_default', 'reorder_qty_default', 'cost_source', 'cost_move_pct', 'reference_undercut_pct', 'ack_days', 'buyer']],
        'crawl' => ['The crawl policy', 'How politely sources are read: the user-agent, the pace, the backoff, the schedules.', ['crawl_user_agent', 'crawl_rate_per_second', 'crawl_backoff_minutes', 'crawl_max_pages', 'schedule_supplier_minutes', 'schedule_reference_minutes', 'schedule_jsonld_minutes', 'removed_after_pulls', 'snapshot_heartbeat_days', 'raw_max_bytes']],
        'files' => ['Files', 'The largest file a person may attach to a record.', ['max_attachment_bytes']],
    ];
}

/** The columns of inv_settings a person changes (everything but id and updated_at), with the form's name where it differs (`buyer` is `buyer_member_id`). */
function settings_columns(): array
{
    $cols = [];
    foreach (settings_groups() as [, , $c]) { $cols = array_merge($cols, $c); }
    return $cols;
}

/** The one row of inv_settings as the writer reads it, normalised: ints, bools, lists, decoded JSON; `buyer` the member id; `updated_at` as stored. */
function find_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT * FROM inv_settings WHERE id = 1')->fetch();
    if ($r === false) { throw new RuntimeException('The settings row is missing.'); }
    return settings_normalize($r);
}

function settings_normalize(array $r): array
{
    $o = [];
    foreach (['business_name', 'business_contact_email', 'business_phone', 'business_address', 'currency', 'units', 'timezone', 'cost_source', 'crawl_user_agent'] as $k) { $o[$k] = $r[$k] === null ? null : (string) $r[$k]; }
    foreach (['sales_sees_cost', 'feed_shows_quantity'] as $k) { $o[$k] = (bool) $r[$k]; }
    foreach (['order_link_days', 'supplier_link_days', 'feed_rate_per_minute', 'feed_rate_per_day', 'key_rotation_overlap_hours', 'reorder_point_default', 'reorder_qty_default', 'ack_days', 'crawl_max_pages',
              'schedule_supplier_minutes', 'schedule_reference_minutes', 'schedule_jsonld_minutes', 'removed_after_pulls', 'snapshot_heartbeat_days', 'raw_max_bytes', 'max_attachment_bytes'] as $k) { $o[$k] = (int) $r[$k]; }
    foreach (['cost_move_pct', 'reference_undercut_pct', 'crawl_rate_per_second'] as $k) { $o[$k] = number_format((float) $r[$k], 2, '.', ''); }
    $o['supplier_sees_phone'] = pg_text_array((string) $r['supplier_sees_phone']);
    $o['crawl_backoff_minutes'] = pg_int_array((string) $r['crawl_backoff_minutes']);
    $o['sizes'] = canon_sizes(json_decode((string) $r['sizes'], true) ?? []);
    $o['attribute_keys'] = canon_attribute_keys(json_decode((string) $r['attribute_keys'], true) ?? []);
    $o['buyer'] = $r['buyer_member_id'] === null ? null : (int) $r['buyer_member_id'];
    $o['updated_at'] = $r['updated_at'];
    return $o;
}

/** A size list in one key order (jsonb sorts an object's keys by length, then alphabetically — a comparison must not see a reorder as a change). */
function canon_sizes(array $sizes): array
{
    return array_values(array_map(static fn ($s): array => ['key' => (string) ($s['key'] ?? ''), 'name' => (string) ($s['name'] ?? ''), 'synonyms' => array_values(array_map('strval', (array) ($s['synonyms'] ?? [])))], $sizes));
}

function canon_attribute_keys(array $attrs): array
{
    return array_values(array_map(static function ($a): array {
        $o = ['key' => (string) ($a['key'] ?? ''), 'name' => (string) ($a['name'] ?? ''), 'kind' => (string) ($a['kind'] ?? '')];
        if (isset($a['choices'])) { $o['choices'] = array_values(array_map('strval', (array) $a['choices'])); }
        return $o;
    }, $attrs));
}

/** size_key => how many variants use it. */
function size_keys_in_use(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT size_key, count(*) AS n FROM product_variants WHERE size_key IS NOT NULL GROUP BY size_key')->fetchAll() as $r) { $out[(string) $r['size_key']] = (int) $r['n']; }
    return $out;
}

/** The ships-how kinds supplier_sees_phone may hold. */
function ships_how_kinds(PDO $pdo): array
{
    return pg_text_array((string) one_value($pdo, 'SELECT inv_ships_how_kinds()'));
}

/** The active people who hold a role here (the morning note's recipient). [{member_id, display_name}] */
function buyer_candidates(PDO $pdo): array
{
    return $pdo->query("SELECT m.id AS member_id, m.display_name FROM members m WHERE m.member_kind = 'human' AND m.status = 'active' AND m.capability IS NOT NULL AND inv_member_roles(m.id) <> '{}' ORDER BY m.display_name")->fetchAll();
}

/**
 * Apply $fields (column name → normalised value; `buyer` for buyer_member_id; only what changes) to the one row. Returns ['changed' => [columns], 'before' => [...], 'after' => [...]] with the same normalised values.
 * The caller has validated; the CHECKs are the backstop (inv_guard makes a violation a 422).
 */
function save_settings(PDO $pdo, array $fields, int $by): array
{
    $cur = find_settings($pdo);
    $sets = [];
    $args = [];
    $changed = [];
    foreach ($fields as $k => $v) {
        if (!array_key_exists($k, $cur) || $k === 'updated_at' || $cur[$k] === $v) { continue; }
        $col = $k === 'buyer' ? 'buyer_member_id' : $k;
        $p = 'p_' . $k;
        switch ($k) {
            case 'supplier_sees_phone': $sets[] = "$col = CAST(:$p AS text[])"; $args[$p] = pg_array_literal(array_values($v)); break;
            case 'crawl_backoff_minutes': $sets[] = "$col = CAST(:$p AS integer[])"; $args[$p] = '{' . implode(',', array_map('intval', $v)) . '}'; break;
            case 'sizes': case 'attribute_keys': $sets[] = "$col = CAST(:$p AS jsonb)"; $args[$p] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); break;
            case 'sales_sees_cost': case 'feed_shows_quantity': $sets[] = "$col = CAST(:$p AS boolean)"; $args[$p] = $v ? 'true' : 'false'; break;
            case 'business_contact_email': $sets[] = "$col = CAST(:$p AS citext)"; $args[$p] = $v; break;
            default: $sets[] = "$col = :$p"; $args[$p] = $v;
        }
        $changed[] = $k;
    }
    if ($sets !== []) { $pdo->prepare('UPDATE inv_settings SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($args); }
    $after = $sets === [] ? $cur : find_settings($pdo);
    return ['changed' => $changed, 'before' => array_intersect_key($cur, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
}

// ---- sequences ---------------------------------------------------------------------------------------------------------------------------------

const SEQUENCE_KINDS = ['sales_order' => 'Sales orders', 'purchase_order' => 'Purchase orders', 'goods_receipt' => 'Goods receipts', 'return' => 'Returns', 'adjustment' => 'Adjustments', 'transfer' => 'Transfers', 'count' => 'Counts'];

/** The seven sequences: kind, prefix, next_value, padding, updated_at, and `next_number` (what the next document will read). */
function find_sequences(PDO $pdo): array
{
    $rows = $pdo->query('SELECT kind, prefix, next_value, padding, updated_at FROM mcp_document_sequences')->fetchAll();
    $by = [];
    foreach ($rows as $r) {
        $r['next_value'] = (int) $r['next_value'];
        $r['padding'] = (int) $r['padding'];
        $r['next_number'] = $r['prefix'] . str_pad((string) $r['next_value'], $r['padding'], '0', STR_PAD_LEFT);
        $r['label'] = SEQUENCE_KINDS[$r['kind']] ?? $r['kind'];
        $by[$r['kind']] = $r;
    }
    $out = [];
    foreach (array_keys(SEQUENCE_KINDS) as $k) { if (isset($by[$k])) { $out[] = $by[$k]; } }
    return $out;
}

/** Set a sequence: prefix, next_value (never below what was issued), padding. Returns ['before' => …, 'after' => …] of the three. */
function set_sequence(PDO $pdo, string $kind, array $fields, int $by): array
{
    $st = $pdo->prepare('SELECT prefix, next_value, padding FROM document_sequences WHERE kind = :k FOR UPDATE');
    $st->execute(['k' => $kind]);
    $cur = $st->fetch();
    if ($cur === false) { throw new DomainException('Not found.'); }
    $cur = ['prefix' => $cur['prefix'], 'next_value' => (int) $cur['next_value'], 'padding' => (int) $cur['padding']];
    if ($fields['next_value'] < $cur['next_value']) { throw new DomainException('The next value cannot go below ' . $cur['next_value'] . ' — a number is never reused.'); }
    $pdo->prepare('UPDATE document_sequences SET prefix = :p, next_value = :n, padding = :d, updated_at = now() WHERE kind = :k')
        ->execute(['p' => $fields['prefix'], 'n' => $fields['next_value'], 'd' => $fields['padding'], 'k' => $kind]);
    $new = ['prefix' => $fields['prefix'], 'next_value' => $fields['next_value'], 'padding' => $fields['padding']];
    return inv_diff($cur, $new);
}

// ---- tax rates ---------------------------------------------------------------------------------------------------------------------------------

/** The tax rates (live first, by name, then archived) with the orders and customers that name each (`used_by`). */
function find_tax_rates(PDO $pdo, bool $includeArchived = true): array
{
    $st = $pdo->query('SELECT t.tax_rate_id, t.name, t.rate, t.is_default, t.archived_at, t.created_at, t.updated_at,
                              (SELECT count(*) FROM mcp_sales_orders o WHERE o.tax_rate_id = t.tax_rate_id) AS orders_using, (SELECT count(*) FROM mcp_customers c WHERE c.tax_rate_id = t.tax_rate_id) AS customers_using
                         FROM mcp_tax_rates t' . ($includeArchived ? '' : ' WHERE t.archived_at IS NULL') . ' ORDER BY (t.archived_at IS NOT NULL), lower(t.name), t.tax_rate_id');
    return array_map('tax_rate_decode', $st->fetchAll());
}

function find_tax_rate(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT t.tax_rate_id, t.name, t.rate, t.is_default, t.archived_at, t.created_at, t.updated_at,
                                (SELECT count(*) FROM mcp_sales_orders o WHERE o.tax_rate_id = t.tax_rate_id) AS orders_using, (SELECT count(*) FROM mcp_customers c WHERE c.tax_rate_id = t.tax_rate_id) AS customers_using
                           FROM mcp_tax_rates t WHERE t.tax_rate_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : tax_rate_decode($r);
}

function tax_rate_decode(array $t): array
{
    $t['tax_rate_id'] = (int) $t['tax_rate_id'];
    $t['rate'] = number_format((float) $t['rate'], 4, '.', '');
    $t['is_default'] = (bool) $t['is_default'];
    $t['orders_using'] = (int) ($t['orders_using'] ?? 0);
    $t['customers_using'] = (int) ($t['customers_using'] ?? 0);
    $t['used_by'] = $t['orders_using'] + $t['customers_using'];
    return $t;
}

/**
 * INSERT (id null) or UPDATE a tax rate; returns its id. $fields: name, rate, is_default. Making a rate the default clears the previous default IN THE SAME TRANSACTION, BEFORE the write (the partial unique index would otherwise refuse
 * with a 23505 the guard words as a name clash — DECISION 13). An archived rate cannot be edited. The name's uniqueness among live rates is the index (23505 → "already taken").
 */
function save_tax_rate(PDO $pdo, ?int $id, array $fields, int $by): int
{
    if ($id !== null) {
        $cur = one_row($pdo, 'SELECT archived_at, is_default FROM tax_rates WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($cur === null) { throw new DomainException('Not found.'); }
        if ($cur['archived_at'] !== null) { throw new DomainException('An archived tax rate cannot be changed.'); }
        if ($cur['is_default'] && !$fields['is_default']) { throw new DomainException('Make another rate the default instead — the default cannot simply be switched off.'); }
    }
    if ($fields['is_default']) { $pdo->prepare('UPDATE tax_rates SET is_default = false WHERE is_default AND archived_at IS NULL AND id IS DISTINCT FROM :id')->execute(['id' => $id]); }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO tax_rates (name, rate, is_default) VALUES (:n, :r, CAST(:d AS boolean)) RETURNING id');
        $st->execute(['n' => $fields['name'], 'r' => $fields['rate'], 'd' => $fields['is_default'] ? 'true' : 'false']);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE tax_rates SET name = :n, rate = :r, is_default = CAST(:d AS boolean) WHERE id = :id')->execute(['n' => $fields['name'], 'r' => $fields['rate'], 'd' => $fields['is_default'] ? 'true' : 'false', 'id' => $id]);
    return $id;
}

/** Archive a rate (orders and customers that name it keep it). Not the default one; not twice. */
function archive_tax_rate(PDO $pdo, int $id, int $by): void
{
    $cur = one_row($pdo, 'SELECT archived_at, is_default FROM tax_rates WHERE id = :id FOR UPDATE', ['id' => $id]);
    if ($cur === null) { throw new DomainException('Not found.'); }
    if ($cur['archived_at'] !== null) { throw new DomainException('That tax rate is already archived.'); }
    if ($cur['is_default']) { throw new DomainException('Make another rate the default first.'); }
    $pdo->prepare('UPDATE tax_rates SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
}

// ---- reason codes ------------------------------------------------------------------------------------------------------------------------------

/** The reason codes in sort order (inactive ones last when included). */
function find_reason_codes(PDO $pdo, bool $includeInactive = true): array
{
    $st = $pdo->query('SELECT reason_code_id, code, name, applies_to, affects_qty, sort_order, active FROM mcp_reason_codes' . ($includeInactive ? '' : ' WHERE active') . ' ORDER BY sort_order, lower(name), reason_code_id');
    return array_map('reason_code_decode', $st->fetchAll());
}

function find_reason_code(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT reason_code_id, code, name, applies_to, affects_qty, sort_order, active FROM mcp_reason_codes WHERE reason_code_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : reason_code_decode($r);
}

function reason_code_decode(array $r): array
{
    $r['reason_code_id'] = (int) $r['reason_code_id'];
    $r['applies_to'] = pg_text_array((string) $r['applies_to']);
    $r['affects_qty'] = (bool) $r['affects_qty'];
    $r['sort_order'] = (int) $r['sort_order'];
    $r['active'] = (bool) $r['active'];
    return $r;
}

/** A code from a name: lower-case letters and digits, spaces and the rest to "_", starting with a letter, at most 40. "Water damage" → water_damage. */
function code_from_name(string $name): string
{
    $c = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '_'));
    if ($c === '') { return ''; }
    if (!preg_match('/^[a-z]/', $c)) { $c = 'r_' . $c; }
    return substr($c, 0, 40);
}

/** INSERT (id null) or UPDATE a reason code; returns its id. $fields: name, code (new only — a code never changes), applies_to[], affects_qty, sort_order, active. A duplicate code is "That code is already used.". */
function save_reason_code(PDO $pdo, ?int $id, array $fields, int $by): int
{
    $applies = pg_array_literal(array_values($fields['applies_to']));
    if ($id === null) {
        if (one_value($pdo, 'SELECT 1 FROM reason_codes WHERE code = :c', ['c' => $fields['code']]) !== null) { throw new DomainException('That code is already used.'); }
        $st = $pdo->prepare('INSERT INTO reason_codes (code, name, applies_to, affects_qty, sort_order, active) VALUES (:c, :n, CAST(:a AS text[]), CAST(:q AS boolean), :s, CAST(:x AS boolean)) RETURNING id');
        $st->execute(['c' => $fields['code'], 'n' => $fields['name'], 'a' => $applies, 'q' => $fields['affects_qty'] ? 'true' : 'false', 's' => $fields['sort_order'], 'x' => $fields['active'] ? 'true' : 'false']);
        return (int) $st->fetchColumn();
    }
    if (one_value($pdo, 'SELECT 1 FROM reason_codes WHERE id = :id FOR UPDATE', ['id' => $id]) === null) { throw new DomainException('Not found.'); }
    $pdo->prepare('UPDATE reason_codes SET name = :n, applies_to = CAST(:a AS text[]), affects_qty = CAST(:q AS boolean), sort_order = :s, active = CAST(:x AS boolean) WHERE id = :id')
        ->execute(['n' => $fields['name'], 'a' => $applies, 'q' => $fields['affects_qty'] ? 'true' : 'false', 's' => $fields['sort_order'], 'x' => $fields['active'] ? 'true' : 'false', 'id' => $id]);
    return $id;
}
