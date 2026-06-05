#!/usr/bin/env bash
#
# One-time server setup for Koszykomat — run ON THE SERVER as the DirectAdmin
# user that owns the koszykomat.pl domain. Safe to re-run (idempotent; never
# overwrites an existing .env).
#
# Usage: bash setup-server.sh [base-dir]   (default: ~/domains/koszykomat.pl)
#
# Panel/root steps that this script cannot do are listed in deploy/SERVER-SETUP.md.
set -euo pipefail

BASE_DIR="${1:-$HOME/domains/koszykomat.pl}"

echo "==> Creating release layout under $BASE_DIR"
mkdir -p "$BASE_DIR/releases"
mkdir -p "$BASE_DIR/shared/storage/app/public"
mkdir -p "$BASE_DIR/shared/storage/framework/cache/data"
mkdir -p "$BASE_DIR/shared/storage/framework/sessions"
mkdir -p "$BASE_DIR/shared/storage/framework/views"
mkdir -p "$BASE_DIR/shared/storage/logs"
chmod -R u+rwX "$BASE_DIR/shared/storage"

if [ -f "$BASE_DIR/shared/.env" ]; then
    echo "==> $BASE_DIR/shared/.env already exists — leaving it untouched."
else
    echo "==> Writing production .env template (fill in the Supabase credentials!)"
    APP_KEY="base64:$(openssl rand -base64 32)"
    cat > "$BASE_DIR/shared/.env" <<ENV
APP_NAME=Koszykomat
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://koszykomat.pl

APP_LOCALE=pl
APP_FALLBACK_LOCALE=pl
APP_FAKER_LOCALE=pl_PL

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

# --- Supabase (Frankfurt) -----------------------------------------------------
# Use the POOLER host (*.pooler.supabase.com) — the direct db.<ref>.supabase.co
# host is IPv6-only on the free tier and unreachable from an IPv4 VPS.
# Web requests: transaction pooler (DB_PORT=6543).
# Migrations / long jobs: session pooler (DB_SESSION_PORT=5432) — used by the
# \`pgsql_session\` connection (php artisan migrate --database=pgsql_session).
DB_CONNECTION=pgsql
DB_HOST=aws-0-eu-central-1.pooler.supabase.com
DB_PORT=6543
DB_SESSION_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.CHANGE-ME-project-ref
DB_PASSWORD=CHANGE-ME
DB_SSLMODE=require
# ------------------------------------------------------------------------------

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="kontakt@koszykomat.pl"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"
ENV
    chmod 600 "$BASE_DIR/shared/.env"
    echo "    APP_KEY generated automatically."
    echo "    EDIT $BASE_DIR/shared/.env and set DB_USERNAME / DB_PASSWORD from the Supabase dashboard."
fi

echo
echo "==> Done. Layout:"
echo "    $BASE_DIR/releases/        (CI rsyncs releases here)"
echo "    $BASE_DIR/shared/.env      (production secrets — chmod 600)"
echo "    $BASE_DIR/shared/storage/  (persistent storage)"
echo
echo "Next: follow deploy/SERVER-SETUP.md for the DirectAdmin / Cloudflare /"
echo "GitHub steps (SSH key, docroot symlink, opcache strategy, cron, TLS, secrets)."
