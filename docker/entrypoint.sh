#!/bin/sh
# Container bootstrap. Laravel is already installed in the image; this only makes the
# runtime directories writable and turns the two opt-in flags into artisan calls.
set -e

cd /var/www/cms/backend

if [ ! -f .env ] && [ -z "$APP_KEY" ]; then
  echo "cms: APP_KEY is empty. Put it in the compose .env on the host, or run" >&2
  echo "  docker compose exec app php artisan key:generate --force" >&2
  exit 1
fi

chown -R www-data:www-data storage bootstrap/cache || true

# Opt-in, because a cached config silently ignores every later .env edit.
if [ "$CMS_CACHE_CONFIG" = "1" ]; then
  php artisan config:cache && php artisan route:cache && php artisan view:cache
fi

# Run migrations when asked. Never automatically: an unexpected deploy should not be a
# schema change, and --force keeps it non-interactive.
if [ "$CMS_MIGRATE" = "1" ]; then
  php artisan migrate --force
fi

exec "$@"
