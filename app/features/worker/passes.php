<?php
declare(strict_types=1);

/**
 * What slice 8's worker passes call (returns-worker.md "The worker's passes"): the three bodies stay in bin/worker.php as the kit laid them out — one function a pass — and this file loads what they
 * call: the sender (notify/send.php — `outbox_send_batch()`), the dispatch loop (agents/dispatch.php — `dispatches_pass()`) and the pruning of the feed keys' counters. Each is idempotent and its own try.
 */
require_once dirname(__DIR__) . '/notify/send.php';
require_once dirname(__DIR__) . '/agents/dispatch.php';

/** The feed keys' counters past their retention (db/013: minute buckets after a day, day buckets after 35): ['pruned' => n]. */
function key_usage_prune(PDO $pdo): array
{
    return ['pruned' => (int) $pdo->query('SELECT inv_prune_key_usage()')->fetchColumn()];
}
