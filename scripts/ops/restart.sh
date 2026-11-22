#!/usr/bin/env bash
# RightAlly restart (DP1, docs/ops/01-vps-whm-deploy.md). Run on the server from the RightAlly folder:
#
#   scripts/ops/restart.sh           restart app, web, queue and scheduler on the release that is live now
#
# Restarts every part on the SAME release, so they can never end up on different builds (which shows as a 502).
# It never builds, pulls or changes the database: to put out new code, use scripts/ops/deploy.sh instead.
set -euo pipefail
cd "$(dirname "$0")/../.."

COMPOSE=(docker compose -f compose.prod.yaml)
PORT="$(grep -E '^RIGHTALLY_PORT=' .env | cut -d= -f2 || true)"; PORT="${PORT:-8100}"
HQ="$(grep -E '^CORE_HQ_HOST=' .env | cut -d= -f2 || true)"; HQ="${HQ:-app.rightally.app}"
say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

# The live release: the one the last good deploy marked as current, else the one the website container runs.
RELEASE="$(docker image inspect rightally-app:current --format '{{ index .Config.Labels "rightally.release" }}' 2>/dev/null || true)"
if [ -z "${RELEASE}" ]; then
    RELEASE="$("${COMPOSE[@]}" ps --format '{{.Image}}' web 2>/dev/null | head -n1 | sed -n 's/^rightally-web://p')"
fi
if [ -z "${RELEASE}" ] || ! docker image inspect "rightally-app:${RELEASE}" >/dev/null 2>&1; then
    echo "Could not find the live release's image. Run scripts/ops/deploy.sh instead." >&2
    exit 1
fi
export APP_RELEASE="${RELEASE}"

say "Restarting ${APP_RELEASE}"
"${COMPOSE[@]}" up -d --no-build --force-recreate --wait app web
"${COMPOSE[@]}" up -d --no-build --force-recreate queue scheduler

say "Checking it answers"
for _ in $(seq 1 20); do
    if curl -fsS -o /dev/null -H "Host: ${HQ}" "http://127.0.0.1:${PORT}/up"; then
        say "Done: ${APP_RELEASE} is running."
        exit 0
    fi
    sleep 3
done
say "It did not answer. Recent logs:"
"${COMPOSE[@]}" logs --tail=80 app web || true
exit 1
