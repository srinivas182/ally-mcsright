#!/usr/bin/env bash
# RightAlly backup (DP1). Run nightly from the server's cron, and by deploy.sh before every change:
#
#   15 3 * * * cd /opt/rightally && scripts/ops/backup.sh >> /var/log/rightally-backup.log 2>&1
#
# Saves the database (a consistent snapshot, without locking anyone out) and the uploaded files into BACKUP_DIR
# (default /var/backups/rightally), keeps 14 days, and, when BACKUP_REMOTE is set in .env (an rclone remote such as
# "r2:rightally-backups"), copies them off the server too. A backup only on the same server is not a backup.
set -euo pipefail
cd "$(dirname "$0")/../.."

COMPOSE=(docker compose -f compose.prod.yaml)
env_value() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DIR="$(env_value BACKUP_DIR || true)"; DIR="${DIR:-/var/backups/rightally}"
KEEP_DAYS="$(env_value BACKUP_KEEP_DAYS || true)"; KEEP_DAYS="${KEEP_DAYS:-14}"
REMOTE="$(env_value BACKUP_REMOTE || true)"
DB="$(env_value DB_DATABASE)"; DB="${DB:-rightally}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"

mkdir -p "${DIR}"
chmod 700 "${DIR}"

echo "Database → ${DIR}/db-${STAMP}.sql.gz"
"${COMPOSE[@]}" exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --routines --triggers --events --hex-blob "$0"' "${DB}" \
    | gzip -9 > "${DIR}/db-${STAMP}.sql.gz"
gzip -t "${DIR}/db-${STAMP}.sql.gz"

echo "Files → ${DIR}/files-${STAMP}.tar.gz"
"${COMPOSE[@]}" exec -T app tar -C /var/www/html/storage -czf - app > "${DIR}/files-${STAMP}.tar.gz"

chmod 600 "${DIR}"/*-"${STAMP}".*
find "${DIR}" -name '*.gz' -mtime +"${KEEP_DAYS}" -delete

if [ -n "${REMOTE}" ]; then
    if command -v rclone >/dev/null; then
        rclone copy "${DIR}" "${REMOTE}" --include "*-${STAMP}.*" && echo "Copied off the server to ${REMOTE}."
    else
        echo "BACKUP_REMOTE is set but rclone is not installed: the backup is only on this server." >&2
    fi
else
    echo "Note: BACKUP_REMOTE is not set, so this backup is only on this server."
fi
echo "Backup ${STAMP} done."
