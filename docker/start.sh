#!/bin/sh
# One image, three roles. Run each as its own service so a slow job never slows the web server
# and each can restart or scale on its own:
#   ROLE=web        HTTP server (runs migrations first)
#   ROLE=worker     queue worker: submits generations to Pixelforge
#   ROLE=scheduler  every-minute reconciler for missed webhooks
#   ROLE=all        everything in one container (default, for single-service hosts)
set -e
ROLE="${ROLE:-all}"

# OAuth (Passport) signing keys. With separate services they must come from PASSPORT_PRIVATE_KEY /
# PASSPORT_PUBLIC_KEY env vars, so every service signs and checks tokens with the same pair.
if [ -z "$PASSPORT_PRIVATE_KEY" ] && [ ! -f storage/oauth-private.key ]; then
  if [ "$ROLE" = "all" ]; then
    php artisan passport:keys --no-interaction
  else
    echo "PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY must be set when ROLE=$ROLE" >&2
    exit 1
  fi
fi

if [ "$ROLE" = "web" ] || [ "$ROLE" = "all" ]; then
  php artisan migrate --force
  php artisan storage:link --force >/dev/null 2>&1 || true   # serves uploaded reference images
fi
php artisan optimize   # config, routes, views and events cached

case "$ROLE" in
  web)
    exec frankenphp run --config docker/Caddyfile
    ;;
  worker)
    exec php artisan queue:work --sleep=1 --max-time=3600
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  all)
    # If one background process dies the container restarts (exit trap below).
    php artisan queue:work --sleep=1 --max-time=3600 &
    php artisan schedule:work &
    trap 'kill 0' EXIT
    exec frankenphp run --config docker/Caddyfile
    ;;
  *)
    echo "Unknown ROLE=$ROLE (use web, worker, scheduler or all)" >&2
    exit 1
    ;;
esac
