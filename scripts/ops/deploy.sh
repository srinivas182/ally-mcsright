#!/usr/bin/env bash
# RightAlly deploy (DP1, docs/ops/01-vps-whm-deploy.md). Run on the server from the RightAlly folder:
#
#   scripts/ops/deploy.sh            the latest main
#   scripts/ops/deploy.sh v1.0.0     a tagged release (or any branch or commit)
#
# Takes a backup, builds the new image while the old one keeps serving, runs the database updates once, restarts
# everything, checks it answers, and puts the old release back if it does not.
set -euo pipefail
cd "$(dirname "$0")/../.."

REF="${1:-main}"
COMPOSE=(docker compose -f compose.prod.yaml)
PORT="$(grep -E '^RIGHTALLY_PORT=' .env | cut -d= -f2 || true)"; PORT="${PORT:-8100}"
HQ="$(grep -E '^CORE_HQ_HOST=' .env | cut -d= -f2 || true)"; HQ="${HQ:-app.rightally.app}"
say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

[ -f .env ] || { echo "No .env here. Copy .env.production.example to .env and fill it in first." >&2; exit 1; }
grep -q '=CHANGE_ME' .env && { echo "The .env still has CHANGE_ME values. Fill them in first." >&2; exit 1; }

PREVIOUS="$(docker image inspect rightally-app:current --format '{{ index .Config.Labels "rightally.release" }}' 2>/dev/null || true)"

say "1. Getting ${REF}"
[ "${DEPLOY_SKIP_FETCH:-}" = 1 ] || git fetch --tags --quiet origin
if [ "${REF}" = "main" ]; then
    git checkout --quiet main && git reset --quiet --hard origin/main
else
    git checkout --quiet "${REF}"
fi
export APP_COMMIT="$(git rev-parse HEAD)"
export APP_RELEASE="$(git describe --tags --always 2>/dev/null || echo "${APP_COMMIT:0:12}")"
echo "Release ${APP_RELEASE} (${APP_COMMIT:0:12})"

if "${COMPOSE[@]}" ps --status running --services 2>/dev/null | grep -qx mysql; then
    say "2. Backing up before the change"
    scripts/ops/backup.sh
else
    say "2. First start: nothing to back up yet"
fi

say "3. Building (the running release keeps serving meanwhile)"
"${COMPOSE[@]}" build --pull --build-arg APP_RELEASE="${APP_RELEASE}" --build-arg APP_COMMIT="${APP_COMMIT}" app web

say "4. Database and Redis"
"${COMPOSE[@]}" up -d --wait mysql redis

say "5. Database updates (run once, before the new code serves anyone)"
"${COMPOSE[@]}" run --rm --no-deps app php artisan migrate --force

say "6. Starting the new release"
"${COMPOSE[@]}" up -d --wait --remove-orphans app web
"${COMPOSE[@]}" up -d --remove-orphans queue scheduler
"${COMPOSE[@]}" exec -T app php artisan queue:restart >/dev/null
# TP1: sample homes for websites without a live MLS board (photos download in the background; safe to re-run).
"${COMPOSE[@]}" exec -T app php artisan listings:sample-homes || echo "  Sample homes could not be refreshed; websites keep the ones they have."

say "7. Checking it answers"
ok=0
for _ in $(seq 1 20); do
    if curl -fsS -o /dev/null -H "Host: ${HQ}" "http://127.0.0.1:${PORT}/up"; then ok=1; break; fi
    sleep 3
done

if [ "${ok}" = 1 ]; then
    docker tag "rightally-app:${APP_RELEASE}" rightally-app:current
    docker tag "rightally-web:${APP_RELEASE}" rightally-web:current
    docker image prune -f --filter "until=168h" >/dev/null || true
    say "Done: ${APP_RELEASE} is live."
    echo "Run '${COMPOSE[*]} exec app php artisan launch:check' to see the launch checklist."
else
    say "The new release did not answer. Putting ${PREVIOUS:-the previous release} back."
    "${COMPOSE[@]}" logs --tail=80 app web || true
    if [ -n "${PREVIOUS}" ]; then
        export APP_RELEASE="${PREVIOUS}"
        "${COMPOSE[@]}" up -d --no-build app web queue scheduler
        echo "Rolled back to ${PREVIOUS}. Database updates are not undone automatically: if the new release added any, check docs/ops/01-vps-whm-deploy.md (Rolling back)."
    fi
    exit 1
fi
