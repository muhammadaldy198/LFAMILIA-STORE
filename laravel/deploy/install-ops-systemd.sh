#!/usr/bin/env bash
set -euo pipefail
umask 077

if [[ "${EUID}" -ne 0 ]]; then
  echo "Jalankan sebagai root." >&2
  exit 1
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${LARAVEL_DIR}/.env"
OPS_DIR="/etc/lfamilia"
OPS_ENV="${OPS_DIR}/ops.env"
MYSQL_CLIENT="/root/.my.cnf"

[[ -f "${ENV_FILE}" ]] || { echo "Laravel .env tidak ditemukan." >&2; exit 1; }

DB_NAME="$(awk -F= '$1=="DB_DATABASE"{sub(/^[^=]*=/,""); gsub(/^[\"'\'' ]+|[\"'\'' ]+$/,""); print; exit}' "${ENV_FILE}")"
[[ "${DB_NAME}" =~ ^[A-Za-z0-9_-]+$ ]] || { echo "DB_DATABASE tidak valid." >&2; exit 1; }

install -d -m 0700 "${OPS_DIR}"
cat > "${OPS_ENV}" <<EOF
LFAMILIA_DB_NAME=${DB_NAME}
LFAMILIA_BACKUP_DIR=/var/backups/lfamilia/mariadb
LFAMILIA_BACKUP_RETENTION_DAYS=7
EOF
chmod 0600 "${OPS_ENV}"

if [[ ! -s "${MYSQL_CLIENT}" ]]; then
  command -v clpctl >/dev/null || { echo "clpctl tidak ditemukan." >&2; exit 1; }
  MASTER="$(clpctl db:show:master-credentials)"
  DB_HOST="$(printf '%s\n' "${MASTER}" | awk -F'|' '/\| Host[[:space:]]*\|/{gsub(/^[[:space:]]+|[[:space:]]+$/,"",$3); print $3; exit}')"
  DB_USER="$(printf '%s\n' "${MASTER}" | awk -F'|' '/\| User Name[[:space:]]*\|/{gsub(/^[[:space:]]+|[[:space:]]+$/,"",$3); print $3; exit}')"
  DB_PASS="$(printf '%s\n' "${MASTER}" | awk -F'|' '/\| Password[[:space:]]*\|/{gsub(/^[[:space:]]+|[[:space:]]+$/,"",$3); print $3; exit}')"
  DB_PORT="$(printf '%s\n' "${MASTER}" | awk -F'|' '/\| Port[[:space:]]*\|/{gsub(/^[[:space:]]+|[[:space:]]+$/,"",$3); print $3; exit}')"
  [[ -n "${DB_HOST}" && -n "${DB_USER}" && -n "${DB_PASS}" && -n "${DB_PORT}" ]] || {
    echo "Kredensial master CloudPanel tidak dapat dibaca." >&2
    exit 1
  }
  python3 - "${MYSQL_CLIENT}" "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}" <<'PY'
from pathlib import Path
import sys
path, host, port, user, password = sys.argv[1:]
escaped = password.replace("\\", "\\\\").replace('"', '\\"')
Path(path).write_text(
    "[client]\n"
    f"host={host}\n"
    f"port={port}\n"
    f"user={user}\n"
    f'password="{escaped}"\n'
)
PY
  chmod 0600 "${MYSQL_CLIENT}"
fi

install -m 0750 "${SCRIPT_DIR}/lfamilia-db-backup.sh" /usr/local/sbin/lfamilia-db-backup
install -m 0750 "${SCRIPT_DIR}/verify-latest-backup.sh" /usr/local/sbin/lfamilia-backup-verify
install -m 0750 "${SCRIPT_DIR}/vps-healthcheck.sh" /usr/local/sbin/lfamilia-healthcheck

for unit in \
  lfamilia-db-backup.service.template \
  lfamilia-db-backup.timer.template \
  lfamilia-backup-verify.service.template \
  lfamilia-backup-verify.timer.template \
  lfamilia-healthcheck.service.template \
  lfamilia-healthcheck.timer.template
do
  install -m 0644 "${SCRIPT_DIR}/systemd/${unit}" "/etc/systemd/system/${unit%.template}"
done

systemctl daemon-reload
systemd-analyze verify \
  /etc/systemd/system/lfamilia-db-backup.service \
  /etc/systemd/system/lfamilia-db-backup.timer \
  /etc/systemd/system/lfamilia-backup-verify.service \
  /etc/systemd/system/lfamilia-backup-verify.timer \
  /etc/systemd/system/lfamilia-healthcheck.service \
  /etc/systemd/system/lfamilia-healthcheck.timer

echo "LFAMILIA operational units installed. Timers are not enabled yet."
