#!/usr/bin/env bash
# Inventory — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0 (2026-10-05);
# later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database (tests/setup_dev.sh),
# php -S and fixtures, and never touch an installed application, Apache or systemd.
#   bash deploy/ROOT_STEPS.sh                      prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply [buyer@email]   runs the steps with no decision in them (0); the Buyer's email seeds INV_BUYER_EMAIL (D12)
set -euo pipefail
APP=/srv/apps/inventory
KERNEL=/var/www
MODE=${1:-plan}
BUYER=${2:-}
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (design §13.16; Help Desk's lesson of 2026-10-02). A config/.env holding
#    ONLY the three ports, the secrets key and the Buyer's email: the installer reads them, fills every other required key itself,
#    and sets fresh role passwords because DB_PASSWORD is absent. INV_SECRETS_KEY (32 random bytes, hex) seals every source
#    credential (app/sources/credentials.php) — generated here if absent, NEVER replaced afterwards (rotation is a later step).
#    INV_BUYER_EMAIL is the Buyer the morning note goes to (D12) — the settings screen changes it later. Never put anything else
#    in this file by hand.
echo "== 0. Pin the ports and the secrets key (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  KEY=$(openssl rand -hex 32)
  if [ -n "$BUYER" ]; then BUYER_LINE="INV_BUYER_EMAIL=$BUYER"; else BUYER_LINE="# INV_BUYER_EMAIL=buyer@your-domain   # the Buyer the morning note goes to (D12); set it here or on the settings screen"; fi
  run bash -c "mkdir -p '$APP/config' && printf 'APP_INTERNAL_PORT=8188\nMCP_RECORDS_PORT=8837\nMCP_ACTIVITY_PORT=8838\nINV_SECRETS_KEY=%s\n%s\n' '$KEY' '$BUYER_LINE' > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
else
  echo "   (config/.env exists — leaving it alone)"
  if ! grep -q '^INV_SECRETS_KEY=.\+' "$APP/config/.env"; then
    run bash -c "printf 'INV_SECRETS_KEY=%s\n' \"\$(openssl rand -hex 32)\" >> '$APP/config/.env'"
  fi
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost)'   -> ports done, no {{…}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 0b. STORAGE (ROOT) — storage/ and its three directories www-data-owned, mode 0770, before apply (connectors.md §6.7): the HTTP
#     client creates storage/http-cache on first use and keeps the per-host rate lock there, shared by the worker (www-data) and
#     Apache's probes and searches; attachments and exports are the kit's. Without this the worker's first client cannot create the
#     cache and the rate lock falls back to "no lock". Idempotent: an existing directory is left in place, its owner and mode set.
echo "== 0b. storage/ (ROOT) — www-data-owned, 0770"
run bash -c "mkdir -p '$APP/storage/http-cache' '$APP/storage/attachments' '$APP/storage/exports' && chown -R www-data:www-data '$APP/storage' && chmod 0770 '$APP/storage' '$APP/storage/http-cache' '$APP/storage/attachments' '$APP/storage/exports'"

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost,
#    the units, the registration (catalog kind ours — the row K27 seeded, db/172 —, the application with /sso and /sso/logout,
#    scope_kind none), the application token into config/.env, skills imported, approval policies, the installing
#    super-admin's admin grant, the MaluMail key from ~/.malumail (the `mail` step). NO --grant-standing-departments (design §13.3:
#    a retailer grants Inventory person by person). --hire-agents hires the expert and the Buyer agent (both hired_on_install).
#    Run it twice: the roles are read from app_roles only once the records MCP answers (Phase 4).
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 3. The owner's keys and decisions (any time after 2):
echo "== 3. The owner's"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME in $APP/config/.env (the installer's mail step writes them from ~/.malumail; the order confirmation and the purchase order's email need them)"
echo "   - the K6 text sender, if texts to members are wanted: php $KERNEL/bin/notify_endpoint_set.php (already set when another application uses it)"
echo "   - DNS and TLS for inventory.subello.com (until then /etc/hosts maps it to this host); INV_PUBLIC_BASE_URL for the doors' absolute links"
echo "   - the grants, person by person: the Buyer (buyer), the salespeople (user), the warehouse (warehouse), a viewer (viewer) — super-admins hold admin"
echo "   - the Buyer agent's morning duty in Agent HR until the installer reads agents[].duty; INV_BUYER_EMAIL on the settings screen if it changed"
echo "   - the first sources from the templates (source_templates — Phase 5), each with its credential on the Sources screen (sealed under INV_SECRETS_KEY)"
echo "   - the feed's first key for the website (Feed keys, admin); the connections a super-admin approves (bin/app_connection.php) for the ledger's and HR's reads of our shares"
