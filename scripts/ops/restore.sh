#!/usr/bin/env bash
# RightAlly restore (DP1): put a backup back, or load one on a new server (moving to DigitalOcean or AWS).
#
#   scripts/ops/restore.sh /var/backups/rightally/db-20261121-031500.sql.gz [/var/backups/rightally/files-20261121-031500.tar.gz]
#
# Stops the app and workers, loads the database (replacing what is there) and the files, then starts everything.
set -euo pipefail
cd "$(dirname "$0")/../.."

DB_FILE="${1:?Give the database backup (db-….sql.gz)}"
FILES="${2:-}"
COMPOSE=(docker compose -f compose.prod.yaml)
DB="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)"; DB="${DB:-rightally}"

read -r -p "This replaces the database \"${DB}\" with ${DB_FILE}. Type the database name to go on: " answer
[ "${answer}" = "${DB}" ] || { echo "Stopped: nothing changed."; exit 1; }

"${COMPOSE[@]}" stop web app queue scheduler || true
"${COMPOSE[@]}" up -d --wait mysql redis
echo "Loading the database…"
gunzip -c "${DB_FILE}" | "${COMPOSE[@]}" exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$0"' "${DB}"
if [ -n "${FILES}" ]; then
    echo "Loading the files…"
    "${COMPOSE[@]}" run --rm --no-deps -T app sh -c 'tar -C /var/www/html/storage -xzf -' < "${FILES}"
fi
"${COMPOSE[@]}" up -d --wait app web
"${COMPOSE[@]}" up -d queue scheduler
"${COMPOSE[@]}" exec -T app php artisan migrate --force
echo "Restored. Sign in and check, then run: ${COMPOSE[*]} exec app php artisan launch:check"
