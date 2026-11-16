#!/bin/sh
set -e
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Background workers. If one dies the container restarts (exit trap below).
php artisan queue:work --sleep=1 --max-time=3600 &
php artisan schedule:work &
trap 'kill 0' EXIT

exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
