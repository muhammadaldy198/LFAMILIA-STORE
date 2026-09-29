#!/usr/bin/env bash
set -euo pipefail

OPS_ENV_FILE="${OPS_ENV_FILE:-/etc/lfamilia/ops.env}"
[[ -r "${OPS_ENV_FILE}" ]] || { echo "Missing readable ${OPS_ENV_FILE}" >&2; exit 1; }
# shellcheck disable=SC1090
source "${OPS_ENV_FILE}"

: "${MYSQL_ADMIN_DEFAULTS_FILE:?MYSQL_ADMIN_DEFAULTS_FILE is required}"
: "${BACKUP_DATABASE_NAME:?BACKUP_DATABASE_NAME is required}"
: "${BACKUP_PASSPHRASE_FILE:?BACKUP_PASSPHRASE_FILE is required}"
: "${BACKUP_DIR:?BACKUP_DIR is required}"

[[ -r "${MYSQL_ADMIN_DEFAULTS_FILE}" ]] || { echo "MySQL admin defaults file is not readable" >&2; exit 1; }
[[ -r "${BACKUP_PASSPHRASE_FILE}" ]] || { echo "Backup passphrase file is not readable" >&2; exit 1; }
[[ "${BACKUP_DATABASE_NAME}" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Unsafe database name" >&2; exit 1; }

latest="$(ls -1t "${BACKUP_DIR}"/lfamilia-*.tar.gz.enc 2>/dev/null | head -n 1 || true)"
[[ -n "${latest}" ]] || { echo "No encrypted backup found" >&2; exit 1; }
[[ -r "${latest}.sha256" ]] || { echo "Backup checksum sidecar missing" >&2; exit 1; }

(
  cd "${BACKUP_DIR}"
  sha256sum -c "$(basename "${latest}").sha256"
)

tmpdir="$(mktemp -d)"
tempdb="${BACKUP_DATABASE_NAME}_restore_$(date -u +%Y%m%d%H%M%S)_${RANDOM}"
[[ "${tempdb}" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Unsafe restore database name" >&2; exit 1; }

cleanup() {
  mysql --defaults-extra-file="${MYSQL_ADMIN_DEFAULTS_FILE}"     -e "DROP DATABASE IF EXISTS \`${tempdb}\`" >/dev/null 2>&1 || true
  rm -rf "${tmpdir}"
}
trap cleanup EXIT

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000   -pass file:"${BACKUP_PASSPHRASE_FILE}" -in "${latest}" -out "${tmpdir}/backup.tar.gz"
tar -C "${tmpdir}" -xzf "${tmpdir}/backup.tar.gz"

[[ -r "${tmpdir}/database.sql" && -r "${tmpdir}/metadata.env" ]]   || { echo "Backup archive is incomplete" >&2; exit 1; }
grep -Eq '^format_version=1$' "${tmpdir}/metadata.env" || { echo "Unsupported backup metadata" >&2; exit 1; }

mysql --defaults-extra-file="${MYSQL_ADMIN_DEFAULTS_FILE}"   -e "CREATE DATABASE \`${tempdb}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql --defaults-extra-file="${MYSQL_ADMIN_DEFAULTS_FILE}" "${tempdb}" < "${tmpdir}/database.sql"

meta_value() {
  local key="$1"
  grep -E "^${key}=[0-9]+$" "${tmpdir}/metadata.env" | head -n 1 | cut -d= -f2
}
query_temp() {
  mysql --defaults-extra-file="${MYSQL_ADMIN_DEFAULTS_FILE}" --batch --skip-column-names     "${tempdb}" -e "$1"
}

declare -A expected actual
expected[users]="$(meta_value users)"
expected[orders]="$(meta_value orders)"
expected[product_packages]="$(meta_value product_packages)"
expected[paid_total_idr]="$(meta_value paid_total_idr)"

actual[users]="$(query_temp 'SELECT COUNT(*) FROM users')"
actual[orders]="$(query_temp 'SELECT COUNT(*) FROM orders')"
actual[product_packages]="$(query_temp 'SELECT COUNT(*) FROM product_packages')"
actual[paid_total_idr]="$(query_temp "SELECT COALESCE(SUM(total_idr),0) FROM orders WHERE status IN ('PAID','PROCESSING','SUCCESS','REFUND')")"

for key in users orders product_packages paid_total_idr; do
  [[ -n "${expected[${key}]}" ]] || { echo "Missing metadata key: ${key}" >&2; exit 1; }
  [[ "${expected[${key}]}" == "${actual[${key}]}" ]]     || { echo "Restore verification mismatch for ${key}" >&2; exit 1; }
done

if [[ -n "${BACKUP_RCLONE_REMOTE:-}" ]]; then
  command -v rclone >/dev/null 2>&1 || { echo "rclone is required for remote verification" >&2; exit 1; }
  rclone --config "${RCLONE_CONFIG}" lsf "${BACKUP_RCLONE_REMOTE%/}"     --include "$(basename "${latest}")" | grep -Fq "$(basename "${latest}")"
fi

echo "Restore verification passed for $(basename "${latest}")"
