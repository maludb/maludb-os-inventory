#!/usr/bin/env bash
# The CONNECTORS proof (Phase 0): app/sources/ against fixtures served by PHP's built-in server — no database, no
# kernel, no network beyond 127.0.0.1. Re-run:  bash tests/phase0/connectors.sh   (exit 0 = every check passed)
#
# What it does: starts `php -S 127.0.0.1:8606 -t tests/fixtures/sources router.php` with a request log in a scratch
# directory, runs tests/phase0/connectors.php (every connector's probe / pull / search / lookup, the normalizer, the
# crawl policy of design §0.2, credentials), prints ok/FAIL lines and a count, stops the server whatever happened.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PORT=8606
SCRATCH="$(mktemp -d /tmp/inv-connectors.XXXXXX)"
export INV_FIX_LOG="$SCRATCH/requests.log"
export INV_FIX_TMP="$SCRATCH"
export INV_FIX_PORT="$PORT"
PID=""
cleanup() {
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then kill "$PID" 2>/dev/null; wait "$PID" 2>/dev/null; fi
    rm -rf "$SCRATCH"
}
trap cleanup EXIT

if (echo > "/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then
    echo "FAIL  port $PORT is taken; stop whatever holds it"; exit 2
fi
php -S "127.0.0.1:$PORT" -t "$ROOT/tests/fixtures/sources" "$ROOT/tests/fixtures/sources/router.php" >"$SCRATCH/server.log" 2>&1 &
PID=$!
for i in $(seq 1 50); do
    if (echo > "/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then break; fi
    sleep 0.1
done
if ! (echo > "/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then
    echo "FAIL  the fixture server did not start"; cat "$SCRATCH/server.log"; exit 2
fi
php "$HERE/connectors.php"
STATUS=$?
exit $STATUS
