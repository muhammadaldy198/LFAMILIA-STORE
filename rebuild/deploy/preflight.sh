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

APP_DIR="${REPO_DIR%/}/${APP_SUBDIR}"
ENV_FILE="${APP_DIR}/.env"
[[ -d "${APP_DIR}" ]] || { echo "APP_DIR not found: ${APP_DIR}" >&2; exit 1; }
[[ -r "${ENV_FILE}" ]] || { echo "Production .env is missing" >&2; exit 1; }

require_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "Missing command: $1" >&2; exit 1; }; }
require_cmd git
require_cmd curl
require_cmd mysql
require_cmd mysqldump
require_cmd redis-cli
require_cmd openssl
require_cmd sha256sum

"${PHP_BIN}" -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);'   || { echo "PHP 8.4+ is required" >&2; exit 1; }
for ext in pdo_mysql redis mbstring openssl; do
  "${PHP_BIN}" -r "exit(extension_loaded('${ext}') ? 0 : 1);"     || { echo "Missing PHP extension: ${ext}" >&2; exit 1; }
done

for pair in   'APP_ENV=production'   'APP_DEBUG=false'   'SESSION_ENCRYPT=true'   'SESSION_SECURE_COOKIE=true'; do
  grep -Eq "^\${pair}$" "${ENV_FILE}" || { echo "Required setting missing: ${pair}" >&2; exit 1; }
done

grep -Eq '^APP_URL=https://' "${ENV_FILE}" || { echo "APP_URL must use HTTPS" >&2; exit 1; }
grep -Eq '^APP_TRUSTED_HOSTS=.+$' "${ENV_FILE}" || { echo "APP_TRUSTED_HOSTS is required" >&2; exit 1; }

cd "${APP_DIR}"
"${COMPOSER_BIN}" validate --strict
"${NPM_BIN}" --version >/dev/null
"${PHP_BIN}" artisan migrate:status --no-interaction >/dev/null
"${PHP_BIN}" artisan schedule:list --no-interaction >/dev/null

echo "M12 production preflight passed."
