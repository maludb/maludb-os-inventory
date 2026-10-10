#!/usr/bin/env bash
# Phase 3, slice 8 — returns, the Buyer agent's data, notifications, the worker's outbox and dispatches passes, notes and files: tests/phase3/slice8/run.sh
# A fresh SCRATCH database (inv_dev8 — never the installed one), the application on :8601 (php -S, or INV_APP=apache for a real Apache with the rendered
# deploy vhost — then :8607 is the internal vhost), a fake kernel (:8602), a fake MaluDB (:8603). :8606 is two things in turn: the FIXTURE SERVER while the world is
# built (the suppliers' offers are pulled from it), then the FAKE MALUMAIL the outbox sends to (FAKE_MALUMAIL_LOG is its record). The HTTP cache is a scratch
# directory. Needs `sudo -n -u postgres`, php, the kernel's web/node_modules for the browser proof. Screenshots go to $SHOTS (default /tmp/inv-shots-s8).
#   PROOFS="world customers" tests/phase3/slice8/run.sh     runs a subset (the world first, always)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export INV_DEV_DB=${INV_DEV_DB:-inv_dev8} INV_DEV_ENV=${INV_DEV_ENV:-/tmp/inv-dev8.env} INV_DEV_STATE=${INV_DEV_STATE:-/tmp/inv-dev8-state} SHOTS=${SHOTS:-/tmp/inv-shots-s8}
mkdir -p "$SHOTS" "$INV_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
export INV_HTTP_CACHE_DIR="$INV_DEV_STATE/http-cache" INV_FIX_TMP="$INV_DEV_STATE/fix" INV_FIX_LOG="$INV_DEV_STATE/fix/requests.log"
rm -rf "$INV_HTTP_CACHE_DIR" "$INV_FIX_TMP"; mkdir -p "$INV_HTTP_CACHE_DIR" "$INV_FIX_TMP"; : > "$INV_FIX_LOG"; chmod 777 "$INV_HTTP_CACHE_DIR" "$INV_FIX_TMP"
echo "INV_HTTP_CACHE_DIR=$INV_HTTP_CACHE_DIR" >> "$INV_DEV_ENV"
"$ROOT/tests/phase2/servers.sh" start --no-malumail >/dev/null || { echo "servers failed"; exit 1; }
( INV_FIX_TMP="$INV_FIX_TMP" INV_FIX_LOG="$INV_FIX_LOG" php -S 127.0.0.1:8606 -t "$ROOT/tests/fixtures/sources" "$ROOT/tests/fixtures/sources/router.php" >"$INV_DEV_STATE/fixtures.log" 2>&1 & echo $! > "$INV_DEV_STATE/fixtures.pid" )
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8606/robots.txt && break; sleep 0.2; done
trap '"$ROOT/tests/phase2/servers.sh" stop; kill "$(cat "$INV_DEV_STATE/fixtures.pid" 2>/dev/null)" "$(cat "$INV_DEV_STATE/malumail.pid" 2>/dev/null)" 2>/dev/null' EXIT
set -a; . "$INV_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$INV_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$INV_DEV_STATE/maludb.log" FAKE_MALUMAIL_STATE="$INV_DEV_STATE/malumail.json" FAKE_MALUMAIL_LOG="$INV_DEV_STATE/malumail.log"
echo '{"mailboxes":{}}' > "$FAKE_MALUMAIL_STATE"; : > "$FAKE_MALUMAIL_LOG"; chmod 666 "$FAKE_MALUMAIL_STATE" "$FAKE_MALUMAIL_LOG"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
echo "== world"; php tests/phase3/slice8/world.php || status=1
# the world is built: the fixture server hands :8606 to the fake MaluMail (the orders' e-mails)
kill "$(cat "$INV_DEV_STATE/fixtures.pid" 2>/dev/null)" 2>/dev/null; sleep 0.3
( php -S 127.0.0.1:8606 "$ROOT/tests/fake_malumail.php" >"$INV_DEV_STATE/malumail.srv" 2>&1 & echo $! > "$INV_DEV_STATE/malumail.pid" )
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8606/x && break; sleep 0.2; done
proofs=${PROOFS:-returns receive note proposals notify outbox dispatch passes notes_files json browser}
for p in $proofs; do
  [ "$p" = browser ] && continue
  [ "$p" = world ] && continue
  echo "== $p"; php "tests/phase3/slice8/$p.php" || status=1
done
if [[ " $proofs " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice8/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   maludb-os.json approvals[] matches the manifest" || { echo "  FAIL approvals[] is stale"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 8 PROOFS PASSED" || echo "SOME SLICE 8 PROOFS FAILED"
exit $status
