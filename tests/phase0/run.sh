#!/usr/bin/env bash
# Phase 0 proof, repeatable: the schema (db/proof/phase0_proof.sql, the schema's checks — skipped with a line when the file is
# not there yet) and the kit without a kernel (testing-without-a-kernel.md §4) — the directory fixture applied, the roles from
# access[], a hand-off minted the kernel's way and presented, its replay / another audience / an unknown member / a tampered
# signature / a member with no grant refused, every refusal logged, health answering, the sign-out notice ending sessions, the
# worker's empty pass. Scratch database only; the installed application (if any) is never touched. Prints ok/FAIL per check and
# a count; exits non-zero on any failure.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export INV_DEV_DB=${INV_DEV_DB:-inv_dev0}
export INV_DEV_ENV=${INV_DEV_ENV:-/tmp/inv-dev0.env}
PORT=8607
pass=0; fail=0
ok()   { echo "ok   $1"; pass=$((pass+1)); }
bad()  { echo "FAIL $1"; fail=$((fail+1)); }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got: $1, wanted: $2)"; fi; }
sql()  { sudo -n -u postgres psql -d "$INV_DEV_DB" -Atc "$1"; }

bash "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "scratch database failed"; exit 1; }
set -a; . "$INV_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$PORT"

echo "== the schema (db/proof/phase0_proof.sql)"
if [ -f "$ROOT/db/proof/phase0_proof.sql" ]; then
  out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$INV_DEV_DB" -f "$ROOT/db/proof/phase0_proof.sql" 2>&1)
  echo "$out" | grep -E '^ FAIL' || true
  summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
  failed=$(echo "$summary" | sed -E 's/.* ([0-9]+) failed.*/\1/')
  check "$failed" "0" "schema proof: $summary"
else
  echo "skip db/proof/phase0_proof.sql is not written yet (the schema builder's) — the kit's checks run on db/001–004 alone"
fi

echo "== the contract tables (db/001–004)"
roles=$(sql "select string_agg(role_key, ',' order by sort_order) from mcp_app_roles"); check "$roles" "viewer,user,warehouse,buyer,admin" "the five roles, in order (os.app-roles/1)"
admins=$(sql "select string_agg(role_key, ',') from inv_roles where is_admin"); check "$admins" "admin" "exactly one admin role"
rights=$(sql "select count(*) from inv_rights"); check "$rights" "30" "thirty rights"
feedsrc=$(sudo -n -u postgres psql -d "$INV_DEV_DB" -v ON_ERROR_STOP=1 -qAt -c "begin; insert into activity_log (source, action, after) values ('feed', 'feed.read', '{\"label\":\"SMOKE key\"}'); select source from activity_log; rollback;" 2>&1 | head -1); check "$feedsrc" "feed" "activity_log admits source feed (the contract's list widened)"
keys=$(sql "select string_agg(column_name, ',' order by ordinal_position) from information_schema.columns where table_name = 'activity_log' and column_name in ('source_id','sales_order_id','purchase_order_id','token_id')"); check "$keys" "source_id,sales_order_id,purchase_order_id,token_id" "the four audit keys appended to activity_log"

echo "== the kit without a kernel"
cd "$ROOT"
php bin/directory_sync.php --from-file bin/dev_directory.json >/dev/null 2>&1; check "$?" "0" "the directory fixture applies"
members=$(sql "select count(*) from members where capability is not null"); check "$members" "7" "seven members admitted from the fixture (six people and the expert; Omar holds no grant)"
nograft=$(sql "select capability is null from members where id = 46"); check "$nograft" "t" "Omar is in the directory with no grant (capability NULL)"
roles=$(sql "select roles::text from members where id = 40"); check "$roles" "{buyer,user,warehouse}" "Nora holds buyer, user and warehouse from access[]"
agent=$(sql "select is_agent::int || ',' || roles::text from members where id = 45"); check "$agent" "1,{user}" "the expert is an agent (is_agent generated) holding user"
ext=$(sql "select is_external::int || ',' || roles::text from members where id = 44"); check "$ext" "1,{viewer}" "Ann is external and a Viewer"
as() { sql "select set_config('app.member_id', '$1', false); select $2" | tail -1; }
check "$(as 41 "inv_has_right('orders.write')")" "t" "Sam (Sales) may take orders"
check "$(as 41 "inv_has_right('cost.read')")" "f" "…but cost is the wall: Sales has no cost.read"
check "$(as 40 "inv_has_right('cost.read')")" "t" "Nora (Buyer) sees cost"
check "$(as 40 "inv_has_right('stock.receive')")" "t" "…and holds Warehouse's rights through buyer"
check "$(as 42 "inv_has_right('stock.receive') and not inv_has_right('orders.write')")" "t" "Wes (Warehouse) receives but does not sell"
check "$(as 43 "inv_has_right('inventory.read') and not inv_has_right('orders.write')")" "t" "Vera (Viewer) reads and nothing more"
check "$(as 1 "inv_is_admin() and inv_has_right('feed.keys')")" "t" "the super-admin runs Inventory (admin, every right)"
check "$(as 46 "inv_member_roles(46)::text")" "{}" "Omar (no grant) holds no role"
check "$(as 45 "inv_is_member_here()")" "t" "the expert belongs here (an agent granted user)"
check "$(as '' "inv_has_right('inventory.read')")" "f" "nobody (no app.member_id) holds nothing"
php -S 127.0.0.1:$PORT -t html tests/dev_router.php >/tmp/inv-phase0-server.log 2>&1 &
SRV=$!; sleep 1
health=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/api/v1/health"); check "$health" "200" "health answers 200"
hbody=$(curl -s "http://127.0.0.1:$PORT/api/v1/health" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["application"] ?? "";'); check "$hbody" "inventory" "…naming the application inventory"
url=$(php bin/dev_handoff.php 40)
first=$(curl -s -o /dev/null -w '%{http_code}' -c /tmp/inv-phase0-jar "$url"); check "$first" "302" "a hand-off opens a session (302 to /)"
home=$(curl -s -b /tmp/inv-phase0-jar "http://127.0.0.1:$PORT/"); grep -q 'id="header-user-name">SMOKE Nora<' <<<"$home" && hm=yes || hm=no; check "$hm" "yes" "the home (the shell, Phase 2) names the signed-in member"
grep -q 'id="header-role-badge">Buyer<' <<<"$home" && hr=yes || hr=no; check "$hr" "yes" "…and wears the badge of the highest role the kernel sent (Buyer)"
again=$(curl -s -o /dev/null -w '%{http_code}' "$url"); check "$again" "403" "the same token a second time is refused (replay)"
bad_url=$(APP_KEY=other php bin/dev_handoff.php 40 | sed "s#http://127.0.0.1:$PORT##")
aud=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT$bad_url"); check "$aud" "403" "a token for another application is refused (audience)"
unk=$(curl -s -o /dev/null -w '%{http_code}' "$(php bin/dev_handoff.php 999)"); check "$unk" "403" "an unknown member is refused"
nogrant=$(curl -s -o /dev/null -w '%{http_code}' "$(php bin/dev_handoff.php 46)"); check "$nogrant" "403" "a member with no grant here (Omar) is refused"
pyld=$(echo "$url" | sed -E 's/.*token=([^.]+\.[^.]+\.[^.]+\.[^.]+)\.[a-f0-9]+.*/\1/')
tamper=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/sso?token=$pyld.$(printf '0%.0s' $(seq 1 64))&claims=x.y"); check "$tamper" "403" "a tampered signature is refused"
reasons=$(sql "select string_agg(after->>'reason', ',' order by id) from activity_log where action = 'member.sign_on.refused'")
check "$reasons" "replay,token,capability,capability,token" "every refusal is logged with its reason, none shown to the visitor"
anon=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/"); check "$anon" "302" "an anonymous visit to / is sent to the launcher (never a login form)"
sess=$(sql "select count(*) from member_sessions where member_id = 40 and ended_at is null"); check "$sess" "1" "the session is listed for the kernel's sign-out notice"
signon=$(sql "select (after->'roles')::text from activity_log where action = 'member.sign_on' and entity_id = 40"); check "$signon" '["buyer", "user", "warehouse"]' "member.sign_on logged with the roles"
# the kernel's sign-out notice ends it
notice="40.$(date +%s).inventory"; sig=$(printf 'sso-logout:%s' "$notice" | openssl dgst -sha256 -hmac "$ACTION_TOKEN_KEY" | sed 's/.* //')
lo=$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=$notice.$sig" "http://127.0.0.1:$PORT/sso/logout"); check "$lo" "204" "the sign-out notice answers 204"
ended=$(sql "select ended_by from member_sessions where member_id = 40"); check "$ended" "kernel" "…and ends the member's session (ended_by kernel)"
gone=$(curl -s -o /dev/null -w '%{http_code}' -b /tmp/inv-phase0-jar "http://127.0.0.1:$PORT/"); check "$gone" "302" "…so the browser's next request is sent back to the launcher"
badlo=$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=40.1.inventory.deadbeef" "http://127.0.0.1:$PORT/sso/logout"); check "$badlo" "204" "a bad notice also answers 204 and changes nothing"
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null
echo "== the worker's empty pass"
wout=$(php bin/worker.php 2>&1); check "$?" "0" "the worker runs a pass and exits 0 (every pass a stub until its slice)"
wlog=$(sql "select count(*) from activity_log where action = 'worker.pass' and source = 'cron'"); check "$wlog" "1" "…logged once as worker.pass (source cron)"
echo "== $pass ok, $fail failed"
[ "$fail" -eq 0 ]
