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

# Webhook deliveries (and anything else queued) are sent by a worker beside
# the web server, never inside a request: a slow receiver must not slow down
# a redirect. The loop restarts the worker if it dies, and --max-time recycles
# it hourly so a long-lived PHP process cannot creep in memory. Raise
# QUEUE_WORKERS if deliveries queue up faster than one worker sends them.
for _ in $(seq 1 "${QUEUE_WORKERS:-1}"); do
    (
        while true; do
            php artisan geoip:update --if-stale > /dev/null 2>&1 || true
            php artisan queue:work --sleep=2 --max-time=3600 || true
            sleep 2
        done
    ) &
done

# The country database behind the geography chart is fetched in the
# background so boot never waits on someone else's server, and a failure only
# means clicks are recorded without a country. --if-stale makes this a no-op
# unless the file is missing or over 35 days old; the worker loop below checks
# again every hour, so a long-running container still picks up each month's file.
( php artisan geoip:update --if-stale || true ) &

# php's built-in server handles one request at a time, which stalls anything
# that has this app call itself: POST /api/domains/{id}/check probes
# https://<customer domain>/up, and once that domain points here the probe
# needs a second worker to answer it - with one it just sits out its timeout
# and reports "no HTTPS". It also means a single slow redirect no longer
# blocks every other visitor. Laravel only honours the worker count together
# with --no-reload (there is nothing to hot-reload in a container anyway).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}" --no-reload
