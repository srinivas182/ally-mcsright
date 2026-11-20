#!/bin/sh
# RightAlly container start (DP1): cache the settings, routes and views for speed, then run the given command
# (php-fpm, a queue worker or the scheduler). Database updates are run by the deploy script, never here, so that
# several containers starting at once never run them twice.
set -e

if [ "${APP_KEY:-}" = "" ]; then
    echo "APP_KEY is not set. Copy .env.production.example to .env and fill it in (see docs/ops/01-vps-whm-deploy.md)." >&2
    exit 1
fi

php artisan optimize >/dev/null

exec "$@"
