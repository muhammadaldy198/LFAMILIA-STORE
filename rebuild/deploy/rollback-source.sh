#!/usr/bin/env bash
set -euo pipefail

OPS_ENV_FILE="${OPS_ENV_FILE:-/etc/lfamilia/ops.env}"
[[ -r "${OPS_ENV_FILE}" ]] || { echo "Missing readable ${OPS_ENV_FILE}" >&2; exit 1; }
# shellcheck disable=SC1090
source "${OPS_ENV_FILE}"

: "${REPO_DIR:?REPO_DIR is required}"
: "${APP_SUBDIR:?APP_SUBDIR is required}"
: "${PHP_BIN:?PHP_BIN is required}"
: "${COMPOSER_BIN:?COMPOSER_BIN is required}"
: "${NPM_BIN:?NPM_BIN is required}"
: "${ROLLBACK_REF:?ROLLBACK_REF is required}"
[[ "${CONFIRM_ROLLBACK:-}" == "ROLLBACK_SOURCE_ONLY" ]]   || { echo "Set CONFIRM_ROLLBACK=ROLLBACK_SOURCE_ONLY" >&2; exit 1; }

APP_DIR="${REPO_DIR%/}/${APP_SUBDIR}"
cd "${REPO_DIR}"
git_repo() {
  git -c "safe.directory=${REPO_DIR}" "$@"
}
[[ -z "$(git_repo status --porcelain)" ]] || { echo "Repository has uncommitted changes" >&2; exit 1; }

git_repo fetch --all --tags --prune
git_repo cat-file -e "${ROLLBACK_REF}^{commit}" 2>/dev/null   || { echo "ROLLBACK_REF is not a valid commit" >&2; exit 1; }

cd "${APP_DIR}"
"${PHP_BIN}" artisan down --retry=60 --refresh=15
maintenance=1
trap 'if [[ "${maintenance:-0}" == 1 ]]; then echo "Rollback failed; application remains in maintenance mode." >&2; fi' EXIT

cd "${REPO_DIR}"
git_repo checkout --detach "${ROLLBACK_REF}"
cd "${APP_DIR}"

COMPOSER_ALLOW_SUPERUSER=1 \
GIT_CONFIG_COUNT=1 \
GIT_CONFIG_KEY_0=safe.directory \
GIT_CONFIG_VALUE_0="${REPO_DIR}" \
"${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
"${NPM_BIN}" ci
"${NPM_BIN}" run build
"${PHP_BIN}" artisan route:clear
"${PHP_BIN}" artisan config:clear
"${PHP_BIN}" artisan config:cache
"${PHP_BIN}" artisan view:cache
"${PHP_BIN}" artisan queue:restart
systemctl restart lfamilia-queue.service lfamilia-scheduler.service

"${PHP_BIN}" artisan up
maintenance=0
trap - EXIT
bash "${APP_DIR}/deploy/healthcheck.sh"

echo "Source rollback completed at $(git_repo -C "${REPO_DIR}" rev-parse HEAD)."
echo "Database migrations were intentionally NOT rolled back."
