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
: "${MYSQL_DEFAULTS_FILE:?MYSQL_DEFAULTS_FILE is required}"
: "${MYSQL_ADMIN_DEFAULTS_FILE:?MYSQL_ADMIN_DEFAULTS_FILE is required}"
: "${BACKUP_DATABASE_NAME:?BACKUP_DATABASE_NAME is required}"
: "${BACKUP_PASSPHRASE_FILE:?BACKUP_PASSPHRASE_FILE is required}"
: "${BACKUP_DIR:?BACKUP_DIR is required}"

APP_DIR="${REPO_DIR%/}/${APP_SUBDIR}"
ENV_FILE="${APP_DIR}/.env"
[[ -d "${APP_DIR}" ]] || { echo "APP_DIR not found: ${APP_DIR}" >&2; exit 1; }
[[ -r "${ENV_FILE}" ]] || { echo "Production .env is missing" >&2; exit 1; }
[[ -r "${MYSQL_DEFAULTS_FILE}" ]] || { echo "MySQL backup defaults file is not readable" >&2; exit 1; }
[[ -r "${MYSQL_ADMIN_DEFAULTS_FILE}" ]] || { echo "MySQL admin defaults file is not readable" >&2; exit 1; }
[[ -r "${BACKUP_PASSPHRASE_FILE}" ]] || { echo "Backup passphrase file is not readable" >&2; exit 1; }

require_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "Missing command: $1" >&2; exit 1; }; }
for cmd in git curl mysql mysqldump redis-cli openssl sha256sum tar; do require_cmd "${cmd}"; done

"${PHP_BIN}" -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);'   || { echo "PHP 8.4+ is required" >&2; exit 1; }
for ext in pdo_mysql redis mbstring openssl; do
  "${PHP_BIN}" -r "exit(extension_loaded('${ext}') ? 0 : 1);"     || { echo "Missing PHP extension: ${ext}" >&2; exit 1; }
done

for pair in   'APP_ENV=production'   'APP_DEBUG=false'   'SESSION_ENCRYPT=true'   'SESSION_SECURE_COOKIE=true'; do
  grep -Eq "^${pair}$" "${ENV_FILE}" || { echo "Required setting missing: ${pair}" >&2; exit 1; }
done

grep -Eq '^APP_KEY=base64:.+$' "${ENV_FILE}" || { echo "APP_KEY must be generated server-side" >&2; exit 1; }
grep -Eq '^APP_URL=https://' "${ENV_FILE}" || { echo "APP_URL must use HTTPS" >&2; exit 1; }
grep -Eq '^APP_TRUSTED_HOSTS=.+
cd "${APP_DIR}"
"${COMPOSER_BIN}" validate --strict
"${NPM_BIN}" --version >/dev/null

mysql --defaults-extra-file="${MYSQL_DEFAULTS_FILE}" "${BACKUP_DATABASE_NAME}"   --batch --skip-column-names -e 'SELECT 1' | grep -Fxq '1'

if [[ -n "${REDIS_PASSWORD_FILE:-}" ]]; then
  [[ -r "${REDIS_PASSWORD_FILE}" ]] || { echo "Redis password file is not readable" >&2; exit 1; }
  REDISCLI_AUTH="$(<"${REDIS_PASSWORD_FILE}")" redis-cli -h 127.0.0.1 ping | grep -Fxq PONG
else
  redis-cli -h 127.0.0.1 ping | grep -Fxq PONG
fi

if [[ "${BACKUP_REQUIRE_REMOTE:-true}" == "true" ]]; then
  require_cmd rclone
  : "${BACKUP_RCLONE_REMOTE:?BACKUP_RCLONE_REMOTE is required}"
  [[ -r "${RCLONE_CONFIG:?RCLONE_CONFIG is required}" ]] || { echo "rclone config is not readable" >&2; exit 1; }
fi

echo "M12 production preflight passed."
 "${ENV_FILE}" || { echo "APP_TRUSTED_HOSTS is required" >&2; exit 1; }
grep -Eq '^TRUSTED_PROXIES=.+
cd "${APP_DIR}"
"${COMPOSER_BIN}" validate --strict
"${NPM_BIN}" --version >/dev/null

mysql --defaults-extra-file="${MYSQL_DEFAULTS_FILE}" "${BACKUP_DATABASE_NAME}"   --batch --skip-column-names -e 'SELECT 1' | grep -Fxq '1'

if [[ -n "${REDIS_PASSWORD_FILE:-}" ]]; then
  [[ -r "${REDIS_PASSWORD_FILE}" ]] || { echo "Redis password file is not readable" >&2; exit 1; }
  REDISCLI_AUTH="$(<"${REDIS_PASSWORD_FILE}")" redis-cli -h 127.0.0.1 ping | grep -Fxq PONG
else
  redis-cli -h 127.0.0.1 ping | grep -Fxq PONG
fi

if [[ "${BACKUP_REQUIRE_REMOTE:-true}" == "true" ]]; then
  require_cmd rclone
  : "${BACKUP_RCLONE_REMOTE:?BACKUP_RCLONE_REMOTE is required}"
  [[ -r "${RCLONE_CONFIG:?RCLONE_CONFIG is required}" ]] || { echo "rclone config is not readable" >&2; exit 1; }
fi

echo "M12 production preflight passed."
 "${ENV_FILE}" || { echo "TRUSTED_PROXIES is required" >&2; exit 1; }

cd "${APP_DIR}"
"${COMPOSER_BIN}" validate --strict
"${NPM_BIN}" --version >/dev/null

mysql --defaults-extra-file="${MYSQL_DEFAULTS_FILE}" "${BACKUP_DATABASE_NAME}"   --batch --skip-column-names -e 'SELECT 1' | grep -Fxq '1'

if [[ -n "${REDIS_PASSWORD_FILE:-}" ]]; then
  [[ -r "${REDIS_PASSWORD_FILE}" ]] || { echo "Redis password file is not readable" >&2; exit 1; }
  REDISCLI_AUTH="$(<"${REDIS_PASSWORD_FILE}")" redis-cli -h 127.0.0.1 ping | grep -Fxq PONG
else
  redis-cli -h 127.0.0.1 ping | grep -Fxq PONG
fi

if [[ "${BACKUP_REQUIRE_REMOTE:-true}" == "true" ]]; then
  require_cmd rclone
  : "${BACKUP_RCLONE_REMOTE:?BACKUP_RCLONE_REMOTE is required}"
  [[ -r "${RCLONE_CONFIG:?RCLONE_CONFIG is required}" ]] || { echo "rclone config is not readable" >&2; exit 1; }
fi

echo "M12 production preflight passed."
