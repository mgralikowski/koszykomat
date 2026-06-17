#!/usr/bin/env bash
#
# Koszykomat release activation — runs ON THE SERVER, invoked by GitHub Actions
# after the release has been rsynced to releases/<name>/.
#
# Order is deliberate (see context/deployment/deploy-plan.md): a failed
# migration aborts before the symlink swap, so a broken release never goes live.
#
# Layout expected (created by deploy/setup-server.sh):
#   $BASE_DIR/releases/<name>/   — this script lives in <name>/deploy/
#   $BASE_DIR/shared/.env        — production env, never in the repo
#   $BASE_DIR/shared/storage/    — persistent storage shared across releases
#   $BASE_DIR/current            — symlink to the live release
set -euo pipefail

# --- Configuration (override via environment) --------------------------------
PHP_BIN="${PHP_BIN:-/usr/local/php85/bin/php}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
# Opcache strategy after the symlink swap:
#   realpath   — nginx passes $realpath_root to FastCGI (custom DA template);
#                nothing to do per deploy. Preferred.
#   fpm-reload — run $FPM_RELOAD_CMD (requires a sudoers entry for the deploy user).
OPCACHE_STRATEGY="${OPCACHE_STRATEGY:-realpath}"
FPM_RELOAD_CMD="${FPM_RELOAD_CMD:-sudo /usr/bin/systemctl reload php-fpm85}"
# ------------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RELEASE_DIR="$(dirname "$SCRIPT_DIR")"                 # .../releases/<name>
BASE_DIR="$(dirname "$(dirname "$RELEASE_DIR")")"      # .../<domain root>

echo "==> Activating release: $RELEASE_DIR"

[ -f "$BASE_DIR/shared/.env" ] || { echo "ERROR: $BASE_DIR/shared/.env missing — run deploy/setup-server.sh first."; exit 1; }
[ -d "$BASE_DIR/shared/storage" ] || { echo "ERROR: $BASE_DIR/shared/storage missing — run deploy/setup-server.sh first."; exit 1; }

cd "$RELEASE_DIR"

echo "==> Linking shared state (.env, storage/)"
ln -sfn "$BASE_DIR/shared/.env" .env
rm -rf storage
ln -sfn "$BASE_DIR/shared/storage" storage
mkdir -p bootstrap/cache

echo "==> Running migrations"
"$PHP_BIN" artisan migrate --force

echo "==> Caching config/routes/views"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan storage:link --force

echo "==> Swapping current symlink (atomic)"
ln -sfn "$RELEASE_DIR" "$BASE_DIR/current_new"
mv -T "$BASE_DIR/current_new" "$BASE_DIR/current"

echo "==> Signalling queue workers to restart"
"$PHP_BIN" artisan queue:restart

case "$OPCACHE_STRATEGY" in
    fpm-reload)
        echo "==> Reloading PHP-FPM (opcache flush)"
        $FPM_RELOAD_CMD
        ;;
    realpath)
        echo "==> Opcache: nginx \$realpath_root strategy — no action needed"
        ;;
    *)
        echo "ERROR: unknown OPCACHE_STRATEGY '$OPCACHE_STRATEGY'"; exit 1
        ;;
esac

echo "==> Pruning old releases (keeping $KEEP_RELEASES)"
cd "$BASE_DIR/releases"
ls -1dt -- */ | tail -n "+$((KEEP_RELEASES + 1))" | xargs -r rm -rf --

echo "==> Release activated: $(readlink "$BASE_DIR/current")"
