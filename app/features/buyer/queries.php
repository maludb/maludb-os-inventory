<?php
declare(strict_types=1);

/**
 * The Buyer agent's data (returns-worker.md "The Buyer agent's data"): the morning note's seven headings composed from the schema's seven functions (db/014), and the Buyer's
 * proposals (buyer_proposals, db/013 — through mcp_buyer_proposals, which strips the cost from anyone who may not see it). The morning note is the tool surface's `morning_note`.
 */

const PROPOSAL_KINDS = ['reorder' => 'Reorder', 'match' => 'Match', 'price' => 'Price', 'at_risk' => 'At risk', 'source' => 'Source', 'po_overdue' => 'Purchase order overdue', 'return' => 'Return'];
const PROPOSAL_AGENT_KINDS = ['reorder', 'match', 'price', 'at_risk'];             // the manifest's four; the schema admits seven (accepted when sent)
const PROPOSAL_STATUSES = ['proposed' => 'Proposed', 'accepted' => 'Accepted', 'dismissed' => 'Dismissed'];
const PROPOSAL_PAGE = 50;
const MORNING_HEADINGS = ['lines_at_risk' => 'At risk', 'reorder' => 'Reorder', 'prices' => 'Prices', 'unmatched' => 'Unmatched', 'sources' => 'Sources', 'purchase_orders' => 'Purchase orders', 'returns' => 'Returns'];

/** Today in the business's time zone (inv_settings.timezone) as Y-m-d. */
function business_today(PDO $pdo): string
{
    $tz = (string) (one_value($pdo, 'SELECT timezone FROM inv_settings WHERE id = 1') ?? 'UTC');
    try { return (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d'); } catch (Throwable) { return gmdate('Y-m-d'); }
}

/** A function the caller's rights refuse (insufficient_privilege) makes its heading withheld rather than failing the note. */
function note_heading(PDO $pdo, string $sql, array $args, int $rows): array
{
    $savepoint = $pdo->inTransaction();
    try {
        if ($savepoint) { $pdo->exec('SAVEPOINT note_heading'); }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $all = $st->fetchAll();
        if ($savepoint) { $pdo->exec('RELEASE SAVEPOINT note_heading'); }
        return ['count' => count($all), 'rows' => array_slice($all, 0, $rows), 'withheld' => false, 'all' => $all];
    } catch (PDOException $e) {
        if ($savepoint) { $pdo->exec('ROLLBACK TO SAVEPOINT note_heading'); }
        if ((string) $e->getCode() === '42501') { return ['count' => null, 'rows' => [], 'withheld' => true, 'all' => []]; }
        throw $e;
    }
}

/**
 * The morning note's data as the caller may see it: {date, headings: {lines_at_risk, reorder, prices, unmatched, sources, purchase_orders (+ awaiting_ack, overdue, untracked), returns},
 * proposals, drafted, markdown}. Each heading {count, rows (the first $rowsPerHeading), withheld}.
 */
function morning_note(PDO $pdo, ?string $date = null, int $rowsPerHeading = 5): array
{
    $date = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : business_today($pdo);
    $n = max(1, min(50, $rowsPerHeading));
    $h = [];
    $h['lines_at_risk'] = note_heading($pdo, 'SELECT * FROM inv_lines_at_risk()', [], $n);
    $h['reorder'] = note_heading($pdo, 'SELECT * FROM inv_reorder_candidates()', [], $n);
    $h['prices'] = note_heading($pdo, 'SELECT * FROM inv_price_exceptions()', [], $n);
    $h['unmatched'] = note_heading($pdo, 'SELECT * FROM inv_unmatched_listings(NULL)', [], $n);
    $h['sources'] = note_heading($pdo, "SELECT * FROM inv_source_health() WHERE health NOT IN ('ok', 'manual', 'inactive')", [], $n);
    $po = note_heading($pdo, 'SELECT * FROM inv_purchase_orders_open() WHERE awaiting_ack OR overdue OR untracked_past_expected', [], $n);
    $po['awaiting_ack'] = $po['withheld'] ? null : count(array_filter($po['all'], static fn (array $r): bool => (bool) $r['awaiting_ack']));
    $po['overdue'] = $po['withheld'] ? null : count(array_filter($po['all'], static fn (array $r): bool => (bool) $r['overdue']));
    $po['untracked'] = $po['withheld'] ? null : count(array_filter($po['all'], static fn (array $r): bool => (bool) $r['untracked_past_expected']));
    $h['purchase_orders'] = $po;
    $h['returns'] = note_heading($pdo, 'SELECT * FROM inv_returns_open()', [], $n);
    foreach ($h as $k => $v) { unset($h[$k]['all']); }
    $prop = $pdo->prepare(PROPOSAL_SELECT . ' WHERE b.note_date = CAST(:d AS date) ORDER BY b.proposal_id');
    $prop->execute(['d' => $date]);
    $proposals = array_map('proposal_decode', $prop->fetchAll());
    $drafted = [];
    foreach ($proposals as $p) {
        if ($p['drafted_record_type'] !== null) {
            $drafted[] = ['kind' => $p['kind'], 'drafted_record_type' => $p['drafted_record_type'], 'drafted_record_id' => (int) $p['drafted_record_id'], 'status' => $p['status']];
        }
    }
    $note = ['date' => $date, 'headings' => $h, 'proposals' => $proposals, 'drafted' => array_values(array_map('unserialize', array_unique(array_map('serialize', $drafted))))];
    $note['markdown'] = morning_note_markdown($note);
    return $note;
}

/** The seven counts, null for a heading the caller may not see. */
function morning_note_counts(array $note): array
{
    $out = [];
    foreach (array_keys(MORNING_HEADINGS) as $k) { $out[$k] = $note['headings'][$k]['count']; }
    return $out;
}

/**
 * The runbook's shape: the date line, the seven counts on one line, "Needs a hand today:" with up to five rows in heading order (at risk, reorder drafts, prices, sources blocked, unmatched
 * proposals), then the drafts and proposals by number. A cost is named only when the rows carry one (the functions null it for a caller who may not see cost); never a customer's address or
 * phone; never a supplier's name beside a customer's (an at-risk line is its order, SKU and risk alone).
 */
function morning_note_markdown(array $note): string
{
    $h = $note['headings'];
    $parts = [];
    foreach (MORNING_HEADINGS as $k => $label) { $parts[] = $label . ' ' . ($h[$k]['count'] === null ? '—' : $h[$k]['count']); }
    $md = "# Morning note — " . $note['date'] . "\n\n" . implode(' · ', $parts) . "\n";
    $needs = [];
    foreach ($h['lines_at_risk']['rows'] as $r) { $needs[] = $r['order_number'] . ' line ' . $r['line_no'] . ' (' . $r['sku'] . ') is at risk: ' . str_replace('_', ' ', (string) $r['risk']); }
    foreach ($h['reorder']['rows'] as $r) {
        $needs[] = 'Reorder ' . $r['sku'] . ': ' . $r['available'] . ' available' . ((int) $r['on_order'] > 0 ? ', ' . $r['on_order'] . ' on order' : '') . ', point ' . $r['reorder_point'] . ', ' . $r['reorder_qty'] . ' to buy'
            . ($r['best_cost'] !== null ? ' at cost ' . number_format((float) $r['best_cost'], 2) : '');
    }
    foreach ($h['prices']['rows'] as $r) { $needs[] = 'Price ' . $r['sku'] . ': ' . str_replace('_', ' ', (string) $r['kind']) . ($r['pct'] !== null ? ' by ' . $r['pct'] . '%' : ''); }
    foreach ($h['sources']['rows'] as $r) { $needs[] = 'Source ' . $r['name'] . ' is ' . $r['health']; }
    foreach ($h['unmatched']['rows'] as $r) { $needs[] = 'Unmatched ' . ($r['sku'] ?: ($r['title'] ?: 'listing')) . ' from ' . $r['source_name'] . ((int) $r['proposals'] > 0 ? ' (a match is proposed)' : ''); }
    $needs = array_slice($needs, 0, 5);
    $md .= "\nNeeds a hand today:\n" . ($needs === [] ? "- Nothing.\n" : implode('', array_map(static fn (string $t): string => '- ' . $t . "\n", $needs)));
    if ($note['proposals'] !== []) {
        $md .= "\nDrafts and proposals:\n";
        foreach ($note['proposals'] as $p) {
            $md .= '- #' . $p['proposal_id'] . ' ' . ($p['kind'] ?? '') . ': ' . $p['title'] . ' (' . $p['status'] . ')' . ($p['drafted_record_type'] !== null ? ' — drafted ' . str_replace('_', ' ', (string) $p['drafted_record_type']) . ' ' . $p['drafted_record_id'] : '') . "\n";
        }
    }
    return $md;
}

// ---- proposals --------------------------------------------------------------------------------------------------------------------------

function proposals_where(array $f, array &$args): string
{
    $sql = '';
    $kinds = array_values(array_filter((array) ($f['kind'] ?? []), static fn ($k): bool => isset(PROPOSAL_KINDS[(string) $k])));
    if ($kinds !== []) { $sql .= " AND b.kind IN ('" . implode("','", array_map('strval', $kinds)) . "')"; }
    $status = (string) ($f['status'] ?? '');
    if ($status !== '' && isset(PROPOSAL_STATUSES[$status])) { $sql .= ' AND b.status = :status'; $args['status'] = $status; }
    foreach (['date' => ['=', 'd1'], 'from' => ['>=', 'd2'], 'to' => ['<=', 'd3']] as $k => [$op, $p]) {
        if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k]) === 1) { $sql .= " AND b.note_date $op CAST(:$p AS date)"; $args[$p] = $f[$k]; }
    }
    return $sql;
}

const PROPOSAL_SELECT = "SELECT b.proposal_id, b.kind, b.subject_type, b.subject_id, b.title, b.detail, b.drafted_record_type, b.drafted_record_id, b.status, b.proposed_by, m.display_name AS proposed_by_name,
        b.note_date, b.decided_by, d.display_name AS decided_by_name, b.decided_at, b.created_at,
        CASE b.subject_type
            WHEN 'product_variant' THEN (SELECT v.sku || ' — ' || v.product_name FROM mcp_product_variants v WHERE v.variant_id = b.subject_id)
            WHEN 'listing_variant' THEN (SELECT COALESCE(lv.sku, lv.title, lv.external_variant_id) || ' — ' || lv.source_name FROM mcp_listing_variants lv WHERE lv.listing_variant_id = b.subject_id)
            WHEN 'sales_order_line' THEN (SELECT l.order_number || ' line ' || l.line_no || ' — ' || l.sku FROM mcp_sales_order_lines l WHERE l.line_id = b.subject_id)
            WHEN 'purchase_order' THEN (SELECT po.number || ' — ' || po.supplier_name FROM mcp_purchase_orders po WHERE po.purchase_order_id = b.subject_id)
            WHEN 'source' THEN (SELECT s.name FROM mcp_sources s WHERE s.source_id = b.subject_id)
            WHEN 'return' THEN (SELECT r.number FROM mcp_return_authorizations r WHERE r.return_id = b.subject_id)
        END AS subject_label,
        CASE b.drafted_record_type
            WHEN 'purchase_order' THEN (SELECT po.number FROM mcp_purchase_orders po WHERE po.purchase_order_id = b.drafted_record_id)
            WHEN 'match_proposal' THEN 'match #' || b.drafted_record_id
        END AS drafted_label
    FROM mcp_buyer_proposals b LEFT JOIN mcp_members m ON m.member_id = b.proposed_by LEFT JOIN mcp_members d ON d.member_id = b.decided_by";

/** [rows, total, pages] of the proposals the caller may see, newest note first. f: kind[], status, date, from, to. */
function find_buyer_proposals(PDO $pdo, array $f, int $page = 1): array
{
    $args = [];
    $where = proposals_where($f, $args);
    $t = $pdo->prepare('SELECT count(*) FROM mcp_buyer_proposals b WHERE true' . $where);
    $t->execute($args);
    $total = (int) $t->fetchColumn();
    $st = $pdo->prepare(PROPOSAL_SELECT . ' WHERE true' . $where . ' ORDER BY b.note_date DESC, b.proposal_id DESC LIMIT ' . PROPOSAL_PAGE . ' OFFSET ' . (max(1, $page) - 1) * PROPOSAL_PAGE);
    $st->execute($args);
    $rows = array_map('proposal_decode', $st->fetchAll());
    return ['rows' => $rows, 'total' => $total, 'page' => max(1, $page), 'pages' => max(1, (int) ceil($total / PROPOSAL_PAGE))];
}

function proposal_decode(array $p): array
{
    $p['proposal_id'] = (int) $p['proposal_id'];
    $p['subject_id'] = (int) $p['subject_id'];
    $p['drafted_record_id'] = $p['drafted_record_id'] === null ? null : (int) $p['drafted_record_id'];
    $p['detail'] = is_array($p['detail']) ? $p['detail'] : (json_decode((string) $p['detail'], true) ?: []);
    return $p;
}

function find_buyer_proposal(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(PROPOSAL_SELECT . ' WHERE b.proposal_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : proposal_decode($r);
}

/** The counts of the three tabs for a date filter. */
function proposal_tab_counts(PDO $pdo, array $f): array
{
    $out = [];
    foreach (array_keys(PROPOSAL_STATUSES) as $s) {
        $args = [];
        $where = proposals_where(['status' => $s] + $f, $args);
        $st = $pdo->prepare('SELECT count(*) FROM mcp_buyer_proposals b WHERE true' . $where);
        $st->execute($args);
        $out[$s] = (int) $st->fetchColumn();
    }
    return $out;
}

/** Proposals dismissed within $days (the Buyer's rule "not made again"). */
function recently_dismissed(PDO $pdo, int $days = 30): array
{
    $st = $pdo->prepare("SELECT b.proposal_id, b.kind, b.subject_type, b.subject_id, b.title, b.decided_at, b.detail->>'dismiss_reason' AS reason FROM mcp_buyer_proposals b
                          WHERE b.status = 'dismissed' AND b.decided_at > now() - make_interval(days => :d) ORDER BY b.decided_at DESC");
    $st->execute(['d' => max(1, $days)]);
    return $st->fetchAll();
}

/** The morning notes' dates that have proposals (the list's date filter). */
function proposal_dates(PDO $pdo, int $limit = 14): array
{
    return $pdo->query('SELECT DISTINCT note_date FROM mcp_buyer_proposals ORDER BY note_date DESC LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
}
