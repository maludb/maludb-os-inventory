#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres`; recreates $INV_DEV_DB, default inv_dev0)
# 1. drops and creates the scratch database; 2. applies db/0*.sql in order as postgres (the same files the installer applies —
#    db/001–004 alone are enough for the kit's proof; the schema's files follow as they are written); 3. leaves the cluster roles'
#    passwords ALONE when the app is installed (reads them from config/.env); else gives them a scratch password (the kernel's
#    installer sets fresh ones at `apply`); 4. writes the proofs' environment to $INV_DEV_ENV (default /tmp/inv-dev0.env, mode 600) —
#    app/bootstrap.php reads that file instead of config/.env when INV_DEV_ENV is set (never in production). Nothing here is
#    committed or installed. INV_SECRETS_KEY is a random 32-byte key for the proofs' sealed credentials; INV_BUYER_EMAIL the fixture's Buyer.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${INV_DEV_DB:-inv_dev0}
ENVF=${INV_DEV_ENV:-/tmp/inv-dev0.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
for f in "$ROOT"/db/0*.sql; do $PSQL -d "$DB" -f "$f" >/dev/null; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in inventory_rw inventory_records_ro inventory_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Inventory"
APP_KEY=inventory
APP_URL=http://127.0.0.1:8601
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=inventory_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=inventory_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=inventory_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=8601
MCP_RECORDS_PORT=8604
MCP_ACTIVITY_PORT=8605
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:8602
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:8603
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:8606
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=inventory@example.invalid
MAIL_FROM_NAME="Inventory"
ATTACHMENT_MAX_BYTES=26214400
INV_PUBLIC_BASE_URL=http://127.0.0.1:8601
INV_SECRETS_KEY=$(openssl rand -hex 32)
INV_BUYER_EMAIL=nora@example.invalid
INV_CRAWL_USER_AGENT=
INV_HTTP_PROXY=
ENV
echo "scratch database $DB ready; environment in $ENVF"
