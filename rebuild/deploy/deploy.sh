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
: "${NPM_BIN:?NPM_BIN is required}"\n: "${PHP_FPM_SERVICE:?PHP_FPM_SERVICE is required}"

APP_DIR="${REPO_DIR%/}/${APP_SUBDIR}"
DEPLOY_DIR="${APP_DIR}/deploy"

bash "${DEPLOY_DIR}/preflight.sh"

cd "${REPO_DIR}"
git_repo() {
  git -c "safe.directory=${REPO_DIR}" "$@"
}
[[ -z "$(git_repo status --porcelain)" ]] || { echo "Repository has uncommitted changes" >&2; exit 1; }
previous_sha="$(git_repo rev-parse HEAD)"
git_repo fetch --prune origin "${REPO_REF}"
git_repo checkout "${REPO_REF}"
git_repo merge --ff-only "origin/${REPO_REF}"
target_sha="$(git_repo rev-parse HEAD)"

cd "${APP_DIR}"
"${PHP_BIN}" artisan down --retry=60 --refresh=15
maintenance=1
trap 'if [[ "${maintenance:-0}" == 1 ]]; then echo "Deployment failed; application remains in maintenance mode." >&2; echo "Previous SHA: ${previous_sha:-unknown}" >&2; fi' EXIT

COMPOSER_ALLOW_SUPERUSER=1 \
GIT_CONFIG_COUNT=1 \
GIT_CONFIG_KEY_0=safe.directory \
GIT_CONFIG_VALUE_0="${REPO_DIR}" \
"${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
"${NPM_BIN}" ci
"${NPM_BIN}" run build

"${PHP_BIN}" artisan route:clear
"${PHP_BIN}" artisan config:clear
"${PHP_BIN}" artisan migrate --force --no-interaction
"${PHP_BIN}" artisan storage:link --no-interaction || true
"${PHP_BIN}" artisan config:cache
"${PHP_BIN}" artisan view:cache
"${PHP_BIN}" artisan queue:restart

systemctl restart "${PHP_FPM_SERVICE}"
systemctl restart lfamilia-queue.service
systemctl restart lfamilia-scheduler.service
systemctl start lfamilia-healthcheck.timer lfamilia-backup.timer lfamilia-restore-verify.timer

"${PHP_BIN}" artisan up
maintenance=0
trap - EXIT

bash "${DEPLOY_DIR}/healthcheck.sh"
echo "M12 deployment complete: ${previous_sha} -> ${target_sha}"
