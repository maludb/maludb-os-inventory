#!/usr/bin/env bash
# Phase 3, slice 3 — sources, connectors, listings and matching (THE EXEMPLAR): tests/phase3/slice3/run.sh
# A fresh SCRATCH database (inv_dev3 — never the installed one), the application on :8601 (php -S, or INV_APP=apache for a real Apache with the
# rendered deploy vhost), a fake kernel (:8602), a fake MaluDB (:8603) and the FIXTURE SERVER on :8606 (tests/fixtures/sources/router.php — no fake
# MaluMail in this suite: the outbox is never sent here). The HTTP cache is a scratch directory (INV_HTTP_CACHE_DIR, cleared each run). Needs
# `sudo -n -u postgres`, php, the kernel's web/node_modules for the browser proof. Screenshots go to $SHOTS (default /tmp/inv-shots-s3).
#   PROOFS="world sources" tests/phase3/slice3/run.sh     runs a subset (the world first, always)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export INV_DEV_DB=${INV_DEV_DB:-inv_dev3} INV_DEV_ENV=${INV_DEV_ENV:-/tmp/inv-dev3.env} INV_DEV_STATE=${INV_DEV_STATE:-/tmp/inv-dev3-state} SHOTS=${SHOTS:-/tmp/inv-shots-s3}
mkdir -p "$SHOTS" "$INV_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
export INV_HTTP_CACHE_DIR="$INV_DEV_STATE/http-cache" INV_FIX_TMP="$INV_DEV_STATE/fix" INV_FIX_LOG="$INV_DEV_STATE/fix/requests.log"
rm -rf "$INV_HTTP_CACHE_DIR" "$INV_FIX_TMP"; mkdir -p "$INV_HTTP_CACHE_DIR" "$INV_FIX_TMP"; : > "$INV_FIX_LOG"; chmod 777 "$INV_HTTP_CACHE_DIR" "$INV_FIX_TMP"
echo "INV_HTTP_CACHE_DIR=$INV_HTTP_CACHE_DIR" >> "$INV_DEV_ENV"
"$ROOT/tests/phase2/servers.sh" start --no-malumail >/dev/null || { echo "servers failed"; exit 1; }
( INV_FIX_TMP="$INV_FIX_TMP" INV_FIX_LOG="$INV_FIX_LOG" php -S 127.0.0.1:8606 -t "$ROOT/tests/fixtures/sources" "$ROOT/tests/fixtures/sources/router.php" >"$INV_DEV_STATE/fixtures.log" 2>&1 & echo $! > "$INV_DEV_STATE/fixtures.pid" )
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8606/robots.txt && break; sleep 0.2; done
trap '"$ROOT/tests/phase2/servers.sh" stop; kill "$(cat "$INV_DEV_STATE/fixtures.pid" 2>/dev/null)" 2>/dev/null' EXIT
set -a; . "$INV_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$INV_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$INV_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-world sources credentials probe pulls removal matching queue listing sheets health survey json browser}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice3/$p.php" || status=1
done
if [[ " $proofs " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice3/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   maludb-os.json approvals[] matches the manifest" || { echo "  FAIL approvals[] is stale"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 3 PROOFS PASSED" || echo "SOME SLICE 3 PROOFS FAILED"
exit $status
