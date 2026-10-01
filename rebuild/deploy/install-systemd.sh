#!/usr/bin/env bash
set -euo pipefail

[[ "${EUID}" -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
OPS_ENV_FILE="${OPS_ENV_FILE:-/etc/lfamilia/ops.env}"
[[ -r "${OPS_ENV_FILE}" ]] || { echo "Missing readable ${OPS_ENV_FILE}" >&2; exit 1; }
# shellcheck disable=SC1090
source "${OPS_ENV_FILE}"

: "${REPO_DIR:?REPO_DIR is required}"
: "${APP_SUBDIR:?APP_SUBDIR is required}"
: "${APP_USER:?APP_USER is required}"
: "${APP_GROUP:?APP_GROUP is required}"
: "${PHP_BIN:?PHP_BIN is required}"
: "${BACKUP_DIR:?BACKUP_DIR is required}"
: "${BACKUP_CALENDAR:?BACKUP_CALENDAR is required}"
: "${RESTORE_VERIFY_CALENDAR:?RESTORE_VERIFY_CALENDAR is required}"
: "${HEALTHCHECK_CALENDAR:?HEALTHCHECK_CALENDAR is required}"

APP_DIR="${REPO_DIR%/}/${APP_SUBDIR}"
BACKUP_RUN_USER="${BACKUP_RUN_USER:-${APP_USER}}"
BACKUP_RUN_GROUP="${BACKUP_RUN_GROUP:-${APP_GROUP}}"
TEMPLATE_DIR="${APP_DIR}/deploy/systemd"

getent passwd "${APP_USER}" >/dev/null || { echo "Unknown APP_USER" >&2; exit 1; }
getent group "${APP_GROUP}" >/dev/null || { echo "Unknown APP_GROUP" >&2; exit 1; }
getent passwd "${BACKUP_RUN_USER}" >/dev/null || { echo "Unknown BACKUP_RUN_USER" >&2; exit 1; }
getent group "${BACKUP_RUN_GROUP}" >/dev/null || { echo "Unknown BACKUP_RUN_GROUP" >&2; exit 1; }

escape_sed() { printf '%s' "$1" | sed 's/[&|\\]/\\&/g'; }
APP_USER_E="$(escape_sed "${APP_USER}")"
APP_GROUP_E="$(escape_sed "${APP_GROUP}")"
APP_DIR_E="$(escape_sed "${APP_DIR}")"
PHP_BIN_E="$(escape_sed "${PHP_BIN}")"
BACKUP_USER_E="$(escape_sed "${BACKUP_RUN_USER}")"
BACKUP_GROUP_E="$(escape_sed "${BACKUP_RUN_GROUP}")"
BACKUP_CAL_E="$(escape_sed "${BACKUP_CALENDAR}")"
RESTORE_CAL_E="$(escape_sed "${RESTORE_VERIFY_CALENDAR}")"
HEALTH_CAL_E="$(escape_sed "${HEALTHCHECK_CALENDAR}")"

render() {
  local source="$1" destination="$2"
  sed     -e "s|@@APP_USER@@|${APP_USER_E}|g"     -e "s|@@APP_GROUP@@|${APP_GROUP_E}|g"     -e "s|@@APP_DIR@@|${APP_DIR_E}|g"     -e "s|@@PHP_BIN@@|${PHP_BIN_E}|g"     -e "s|@@BACKUP_RUN_USER@@|${BACKUP_USER_E}|g"     -e "s|@@BACKUP_RUN_GROUP@@|${BACKUP_GROUP_E}|g"     -e "s|@@BACKUP_CALENDAR@@|${BACKUP_CAL_E}|g"     -e "s|@@RESTORE_VERIFY_CALENDAR@@|${RESTORE_CAL_E}|g"     -e "s|@@HEALTHCHECK_CALENDAR@@|${HEALTH_CAL_E}|g"     "${source}" > "${destination}"
  chmod 0644 "${destination}"
}

mkdir -p /etc/lfamilia "${BACKUP_DIR}"
# Health checks run as APP_USER and must traverse the operations directory.
chown root:"${APP_GROUP}" /etc/lfamilia
chmod 0750 /etc/lfamilia
chown "${BACKUP_RUN_USER}:${BACKUP_RUN_GROUP}" "${BACKUP_DIR}"
chmod 0750 "${BACKUP_DIR}"
chown root:"${APP_GROUP}" "${OPS_ENV_FILE}"
chmod 0640 "${OPS_ENV_FILE}"


# Privileged services must never execute application-writable source files.
install -d -o root -g root -m 0755 /usr/local/lib/lfamilia
for script in backup.sh restore-verify.sh; do
  install -o root -g root -m 0700 "${APP_DIR}/deploy/${script}" "/usr/local/lib/lfamilia/${script}"
done

for name in   lfamilia-queue.service   lfamilia-scheduler.service   lfamilia-healthcheck.service   lfamilia-healthcheck.timer   lfamilia-backup.service   lfamilia-backup.timer   lfamilia-restore-verify.service   lfamilia-restore-verify.timer; do
  render "${TEMPLATE_DIR}/${name}.in" "/etc/systemd/system/${name}"
done

systemctl daemon-reload
systemctl enable   lfamilia-queue.service   lfamilia-scheduler.service   lfamilia-healthcheck.timer   lfamilia-backup.timer   lfamilia-restore-verify.timer

if command -v systemd-analyze >/dev/null 2>&1; then
  systemd-analyze verify     /etc/systemd/system/lfamilia-queue.service     /etc/systemd/system/lfamilia-scheduler.service     /etc/systemd/system/lfamilia-healthcheck.service     /etc/systemd/system/lfamilia-backup.service     /etc/systemd/system/lfamilia-restore-verify.service
fi

echo "M12 systemd units installed and enabled. Services start after deploy.sh succeeds."
