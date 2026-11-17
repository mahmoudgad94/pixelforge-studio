#!/bin/sh
set -e
php artisan migrate --force
php artisan storage:link --force >/dev/null 2>&1 || true   # serves uploaded reference images

# OAuth (Passport) signing keys. Prefer PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY env vars, so tokens
# survive redeploys; otherwise generate key files once (lost if storage/ is not on a volume).
if [ -z "$PASSPORT_PRIVATE_KEY" ] && [ ! -f storage/oauth-private.key ]; then
  php artisan passport:keys --no-interaction
fi
php artisan optimize   # config, routes, views and events cached

# Background workers. If one dies the container restarts (exit trap below).
php artisan queue:work --sleep=1 --max-time=3600 &
php artisan schedule:work &
trap 'kill 0' EXIT

exec frankenphp run --config docker/Caddyfile
