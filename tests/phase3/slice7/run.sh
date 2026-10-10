#!/usr/bin/env bash
# Phase 3, slice 7 — the availability feed, its keys, the price lists and the five shares' readers: tests/phase3/slice7/run.sh
# A fresh SCRATCH database (inv_dev7 — never the installed one), the application on :8601 (php -S, or INV_APP=apache for a real Apache with the rendered deploy vhost —
# then :8601 is the PUBLIC vhost, which carries the feed on its allow-list, and :8607 the internal one), a fake kernel (:8602), a fake MaluDB (:8603). :8606 is the
# FIXTURE SERVER while the world is built (the suppliers' offers are pulled from it); the feed needs nothing else. The HTTP cache is a scratch directory. Needs
# `sudo -n -u postgres`, php, the kernel's web/node_modules for the browser proof. Screenshots go to $SHOTS (default /tmp/inv-shots-s7).
#   PROOFS="keys api" tests/phase3/slice7/run.sh     runs a subset (the world first, always)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export INV_DEV_DB=${INV_DEV_DB:-inv_dev7} INV_DEV_ENV=${INV_DEV_ENV:-/tmp/inv-dev7.env} INV_DEV_STATE=${INV_DEV_STATE:-/tmp/inv-dev7-state} SHOTS=${SHOTS:-/tmp/inv-shots-s7}
mkdir -p "$SHOTS" "$INV_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
export INV_HTTP_CACHE_DIR="$INV_DEV_STATE/http-cache" INV_FIX_TMP="$INV_DEV_STATE/fix" INV_FIX_LOG="$INV_DEV_STATE/fix/requests.log" INV_FEED_KEYS="$INV_DEV_STATE/feed-keys.json"
rm -rf "$INV_HTTP_CACHE_DIR" "$INV_FIX_TMP" "$INV_FEED_KEYS"; mkdir -p "$INV_HTTP_CACHE_DIR" "$INV_FIX_TMP"; : > "$INV_FIX_LOG"; chmod 777 "$INV_HTTP_CACHE_DIR" "$INV_FIX_TMP"
echo "INV_HTTP_CACHE_DIR=$INV_HTTP_CACHE_DIR" >> "$INV_DEV_ENV"
echo "API_CORS_ORIGINS=https://shop.example.invalid" >> "$INV_DEV_ENV"
"$ROOT/tests/phase2/servers.sh" start --no-malumail >/dev/null || { echo "servers failed"; exit 1; }
( INV_FIX_TMP="$INV_FIX_TMP" INV_FIX_LOG="$INV_FIX_LOG" php -S 127.0.0.1:8606 -t "$ROOT/tests/fixtures/sources" "$ROOT/tests/fixtures/sources/router.php" >"$INV_DEV_STATE/fixtures.log" 2>&1 & echo $! > "$INV_DEV_STATE/fixtures.pid" )
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8606/robots.txt && break; sleep 0.2; done
trap '"$ROOT/tests/phase2/servers.sh" stop; kill "$(cat "$INV_DEV_STATE/fixtures.pid" 2>/dev/null)" 2>/dev/null' EXIT
set -a; . "$INV_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$INV_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$INV_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
echo "== world"; php tests/phase3/slice7/world.php || status=1
proofs=${PROOFS:-keys api rate rotate partner shares connections json browser}
for p in $proofs; do
  [ "$p" = browser ] && continue
  [ "$p" = world ] && continue
  echo "== $p"; php "tests/phase3/slice7/$p.php" || status=1
done
if [[ " $proofs " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice7/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   maludb-os.json approvals[] matches the manifest" || { echo "  FAIL approvals[] is stale"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 7 PROOFS PASSED" || echo "SOME SLICE 7 PROOFS FAILED"
exit $status
