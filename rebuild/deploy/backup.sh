#!/usr/bin/env bash
set -euo pipefail

OPS_ENV_FILE="${OPS_ENV_FILE:-/etc/lfamilia/ops.env}"
[[ -r "${OPS_ENV_FILE}" ]] || { echo "Missing readable ${OPS_ENV_FILE}" >&2; exit 1; }
# shellcheck disable=SC1090
source "${OPS_ENV_FILE}"

: "${MYSQL_DEFAULTS_FILE:?MYSQL_DEFAULTS_FILE is required}"
: "${BACKUP_DATABASE_NAME:?BACKUP_DATABASE_NAME is required}"
: "${BACKUP_PASSPHRASE_FILE:?BACKUP_PASSPHRASE_FILE is required}"
: "${BACKUP_DIR:?BACKUP_DIR is required}"
: "${BACKUP_RETENTION_DAYS:?BACKUP_RETENTION_DAYS is required}"

[[ -r "${MYSQL_DEFAULTS_FILE}" ]] || { echo "MySQL defaults file is not readable" >&2; exit 1; }
[[ -r "${BACKUP_PASSPHRASE_FILE}" ]] || { echo "Backup passphrase file is not readable" >&2; exit 1; }
[[ "${BACKUP_DATABASE_NAME}" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Unsafe database name" >&2; exit 1; }
[[ "${BACKUP_RETENTION_DAYS}" =~ ^[0-9]+$ ]] || { echo "Invalid retention days" >&2; exit 1; }

if [[ "${BACKUP_REQUIRE_REMOTE:-true}" == "true" ]]; then
  command -v rclone >/dev/null 2>&1 || { echo "rclone is required" >&2; exit 1; }
  : "${BACKUP_RCLONE_REMOTE:?BACKUP_RCLONE_REMOTE is required}"
  [[ -r "${RCLONE_CONFIG:?RCLONE_CONFIG is required}" ]] || { echo "rclone config is not readable" >&2; exit 1; }
fi

umask 077
mkdir -p "${BACKUP_DIR}"
tmpdir="$(mktemp -d)"
trap 'rm -rf "${tmpdir}"' EXIT

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
base="lfamilia-${stamp}"
dump="${tmpdir}/database.sql"
meta="${tmpdir}/metadata.env"
archive="${tmpdir}/${base}.tar.gz"
encrypted="${BACKUP_DIR}/${base}.tar.gz.enc"

mysqldump --defaults-extra-file="${MYSQL_DEFAULTS_FILE}"   --single-transaction --quick --routines --triggers --events --hex-blob --no-tablespaces   "${BACKUP_DATABASE_NAME}" > "${dump}"

query() {
  mysql --defaults-extra-file="${MYSQL_DEFAULTS_FILE}" --batch --skip-column-names     "${BACKUP_DATABASE_NAME}" -e "$1"
}

{
  echo "format_version=1"
  echo "created_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "users=$(query 'SELECT COUNT(*) FROM users')"
  echo "orders=$(query 'SELECT COUNT(*) FROM orders')"
  echo "product_packages=$(query 'SELECT COUNT(*) FROM product_packages')"
  echo "paid_total_idr=$(query "SELECT COALESCE(SUM(total_idr),0) FROM orders WHERE status IN ('PAID','PROCESSING','SUCCESS','REFUND')")"
} > "${meta}"

tar -C "${tmpdir}" -czf "${archive}" database.sql metadata.env
openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000   -pass file:"${BACKUP_PASSPHRASE_FILE}" -in "${archive}" -out "${encrypted}"

(
  cd "${BACKUP_DIR}"
  sha256sum "$(basename "${encrypted}")" > "$(basename "${encrypted}").sha256"
)

if [[ "${BACKUP_REQUIRE_REMOTE:-true}" == "true" ]]; then
  remote="${BACKUP_RCLONE_REMOTE%/}"
  rclone --config "${RCLONE_CONFIG}" copyto "${encrypted}" "${remote}/$(basename "${encrypted}")"
  rclone --config "${RCLONE_CONFIG}" copyto "${encrypted}.sha256" "${remote}/$(basename "${encrypted}").sha256"
  rclone --config "${RCLONE_CONFIG}" lsf "${remote}" --include "$(basename "${encrypted}")" | grep -Fq "$(basename "${encrypted}")"
  rclone --config "${RCLONE_CONFIG}" delete "${remote}"     --min-age "${BACKUP_RETENTION_DAYS}d" --include 'lfamilia-*.tar.gz.enc*'
fi

find "${BACKUP_DIR}" -type f \( -name 'lfamilia-*.tar.gz.enc' -o -name 'lfamilia-*.tar.gz.enc.sha256' \)   -mtime "+${BACKUP_RETENTION_DAYS}" -delete

echo "Encrypted backup completed: ${encrypted}"
