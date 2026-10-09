#!/usr/bin/env bash
# Phase 3, slice 2 — locations and stock (the ledger's screens): tests/phase3/slice2/run.sh
# A fresh SCRATCH database (inv_dev2b — never the installed one), the application on :8601 (php -S, or INV_APP=apache for a real Apache with the
# rendered deploy vhost), a fake kernel (:8602), a fake MaluDB (:8603), a fake MaluMail (:8606). Needs `sudo -n -u postgres`, php, the kernel's
# web/node_modules (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/inv-shots-s2). One line per check; exit 0 only if all passed.
#   PROOFS="world receipts" tests/phase3/slice2/run.sh     runs a subset (the world first, always)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export INV_DEV_DB=${INV_DEV_DB:-inv_dev2b} INV_DEV_ENV=${INV_DEV_ENV:-/tmp/inv-dev2b.env} INV_DEV_STATE=${INV_DEV_STATE:-/tmp/inv-dev2b-state} SHOTS=${SHOTS:-/tmp/inv-shots-s2}
mkdir -p "$SHOTS" "$INV_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$INV_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$INV_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$INV_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-world locations receipts adjustments transfers counts floor reverse levels visibility json browser}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice2/$p.php" || status=1
done
if [[ " $proofs " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice2/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   maludb-os.json approvals[] matches the manifest" || { echo "  FAIL approvals[] is stale"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 2 PROOFS PASSED" || echo "SOME SLICE 2 PROOFS FAILED"
exit $status
