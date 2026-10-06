#!/bin/bash
# cPanel deploy, invoked from .cpanel.yml (cwd = the git checkout).
#
# Order: maintenance mode -> DB backup -> sync code -> composer --no-dev ->
# migrate -> caches -> storage link -> restart workers -> back online.
#
# If any step before "artisan up" fails the script exits and the site STAYS in
# maintenance mode on purpose: serving new code against a half-migrated schema
# is worse than a 503 (gateways retry webhooks on 503). Fix, then run
# `php artisan up` in $CORE.
set -euo pipefail

DEPLOYPATH="${DEPLOYPATH:-/home/xtraucom/public_html}"
CORE="$DEPLOYPATH/core"
BACKUPS="${BACKUPS:-/home/xtraucom/backups}"
KEEP_BACKUPS=14
PHP_BIN="${PHP_BIN:-php}"
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$CORE" "$BACKUPS"

artisan() { (cd "$CORE" && "$PHP_BIN" artisan "$@"); }

# 1. Maintenance mode (skipped on first deploy, when there is no app yet).
if [ -f "$CORE/artisan" ] && [ -d "$CORE/vendor" ]; then
  artisan down --retry=60 || true
fi

# 2. Database backup (MySQL only; credentials read from the server's .env).
if [ -f "$CORE/.env" ]; then
  env_val() { grep -E "^$1=" "$CORE/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
  if [ "$(env_val DB_CONNECTION)" = "mysql" ]; then
    command -v mysqldump >/dev/null || { echo "mysqldump not found; refusing to migrate without a backup" >&2; exit 1; }
    MYSQL_PWD="$(env_val DB_PASSWORD)" mysqldump \
      --single-transaction --no-tablespaces \
      -h "$(env_val DB_HOST)" -P "$(env_val DB_PORT)" -u "$(env_val DB_USERNAME)" \
      "$(env_val DB_DATABASE)" | gzip > "$BACKUPS/db-$STAMP.sql.gz"
    [ -s "$BACKUPS/db-$STAMP.sql.gz" ] || { echo "Empty DB backup" >&2; exit 1; }
    chmod 600 "$BACKUPS/db-$STAMP.sql.gz"
    ls -1t "$BACKUPS"/db-*.sql.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f
  fi
fi

# 3. Sync code. Code directories are replaced wholesale so deleted files
#    (views, migrations, classes) do not linger. storage/, .env and vendor/
#    are never removed; storage is overlaid.
for dir in app bootstrap config database resources routes; do
  rm -rf "$CORE/$dir"
done
cp -R app bootstrap config database resources routes artisan composer.json composer.lock "$CORE/"
mkdir -p "$CORE/storage" "$CORE/bootstrap/cache"
cp -R storage/. "$CORE/storage/"
rm -f "$CORE"/bootstrap/cache/*.php

# 4. Dependencies: production-only, optimized autoloader.
if command -v composer >/dev/null; then
  (cd "$CORE" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist)
elif [ -d vendor ]; then
  rm -rf "$CORE/vendor" && cp -R vendor "$CORE/vendor"
  echo "WARNING: composer not found; copied checkout vendor/. Ensure it was built with --no-dev." >&2
else
  echo "composer not available and no vendor/ in checkout" >&2
  exit 1
fi

# 5. Public assets. Never overwrite the storage symlink with a stray directory.
for item in public/*; do
  [ "$(basename "$item")" = "storage" ] && continue
  cp -R "$item" "$DEPLOYPATH/"
done

# 6. Permissions.
chmod -R 755 "$CORE"
chmod -R 775 "$CORE/storage" "$CORE/bootstrap/cache"

# 7. Public storage symlink (idempotent).
if [ ! -e "$DEPLOYPATH/storage" ] && [ ! -L "$DEPLOYPATH/storage" ]; then
  mkdir -p "$CORE/storage/app/public"
  ln -s "$CORE/storage/app/public" "$DEPLOYPATH/storage"
fi

# 8. Migrate, then rebuild caches (clear first so stale config never survives).
artisan optimize:clear
artisan migrate --force
artisan config:cache
artisan route:cache
artisan view:cache

# 9. Tell any long-running queue workers to restart on the new code. The cron
#    workers (--stop-when-empty) pick it up on their next run anyway.
artisan queue:restart || true

# 10. Back online.
artisan up
echo "Deploy $STAMP complete."
