#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

php artisan migrate --force

# Idempotent (DemoUserSeeder/DemoDataSeeder use updateOrCreate and skip
# links that already have clicks) - safe to run on every deploy, not just
# the first one.
php artisan db:seed --force

# php's built-in server handles one request at a time, which stalls anything
# that has this app call itself: POST /api/domains/{id}/check probes
# https://<customer domain>/up, and once that domain points here the probe
# needs a second worker to answer it - with one it just sits out its timeout
# and reports "no HTTPS". It also means a single slow redirect no longer
# blocks every other visitor. Laravel only honours the worker count together
# with --no-reload (there is nothing to hot-reload in a container anyway).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}" --no-reload
