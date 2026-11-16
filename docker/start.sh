#!/bin/sh
set -e
php artisan migrate --force
php artisan storage:link --force >/dev/null 2>&1 || true   # serves uploaded reference images
php artisan optimize   # config, routes, views and events cached

# Background workers. If one dies the container restarts (exit trap below).
php artisan queue:work --sleep=1 --max-time=3600 &
php artisan schedule:work &
trap 'kill 0' EXIT

exec frankenphp run --config docker/Caddyfile
