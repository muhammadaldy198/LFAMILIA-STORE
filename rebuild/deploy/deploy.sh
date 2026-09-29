#!/usr/bin/env bash
set -euo pipefail

OPS_ENV_FILE="${OPS_ENV_FILE:-/etc/lfamilia/ops.env}"
[[ -r "${OPS_ENV_FILE}" ]] || { echo "Missing readable ${OPS_ENV_FILE}" >&2; exit 1; }
# shellcheck disable=SC1090
source "${OPS_ENV_FILE}"

: "${REPO_DIR:?REPO_DIR is required}"
: "${APP_SUBDIR:?APP_SUBDIR is required}"
: "${REPO_REF:?REPO_REF is required}"
: "${PHP_BIN:?PHP_BIN is required}"
: "${COMPOSER_BIN:?COMPOSER_BIN is required}"
: "${NPM_BIN:?NPM_BIN is required}"

APP_DIR="${REPO_DIR%/}/${APP_SUBDIR}"
DEPLOY_DIR="${APP_DIR}/deploy"

"${DEPLOY_DIR}/preflight.sh"

cd "${REPO_DIR}"
[[ -z "$(git status --porcelain)" ]] || { echo "Repository has uncommitted changes" >&2; exit 1; }
previous_sha="$(git rev-parse HEAD)"
git fetch --prune origin "${REPO_REF}"
git checkout "${REPO_REF}"
git merge --ff-only "origin/${REPO_REF}"
target_sha="$(git rev-parse HEAD)"

cd "${APP_DIR}"
"${PHP_BIN}" artisan down --retry=60 --refresh=15
maintenance=1
trap 'if [[ "${maintenance:-0}" == 1 ]]; then echo "Deployment failed; application remains in maintenance mode." >&2; echo "Previous SHA: ${previous_sha:-unknown}" >&2; fi' EXIT

"${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
"${NPM_BIN}" ci
"${NPM_BIN}" run build

"${PHP_BIN}" artisan config:clear
"${PHP_BIN}" artisan migrate --force --no-interaction
"${PHP_BIN}" artisan storage:link --no-interaction || true
"${PHP_BIN}" artisan config:cache
"${PHP_BIN}" artisan view:cache
"${PHP_BIN}" artisan queue:restart

systemctl restart lfamilia-queue.service
systemctl restart lfamilia-scheduler.service

"${PHP_BIN}" artisan up
maintenance=0
trap - EXIT

"${DEPLOY_DIR}/healthcheck.sh"
echo "M12 deployment complete: ${previous_sha} -> ${target_sha}"
