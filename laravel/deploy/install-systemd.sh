#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Jalankan sebagai root: sudo LFAMILIA_APP_DIR=... LFAMILIA_RUN_USER=... bash deploy/install-systemd.sh" >&2
  exit 1
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${LFAMILIA_APP_DIR:-$(cd -- "${SCRIPT_DIR}/.." && pwd)}"
RUN_USER="${LFAMILIA_RUN_USER:-$(stat -c '%U' "${APP_DIR}")}"
RUN_GROUP="${LFAMILIA_RUN_GROUP:-$(stat -c '%G' "${APP_DIR}")}"
PHP_BIN="${LFAMILIA_PHP_BIN:-$(command -v php || true)}"

[[ "${APP_DIR}" = /* ]] || { echo "LFAMILIA_APP_DIR harus path absolut." >&2; exit 1; }
[[ -f "${APP_DIR}/artisan" ]] || { echo "artisan tidak ditemukan di ${APP_DIR}." >&2; exit 1; }
[[ "${RUN_USER}" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "LFAMILIA_RUN_USER tidak valid." >&2; exit 1; }
[[ "${RUN_GROUP}" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "LFAMILIA_RUN_GROUP tidak valid." >&2; exit 1; }
id -u "${RUN_USER}" >/dev/null 2>&1 || { echo "User ${RUN_USER} tidak ditemukan." >&2; exit 1; }
getent group "${RUN_GROUP}" >/dev/null 2>&1 || { echo "Group ${RUN_GROUP} tidak ditemukan." >&2; exit 1; }
[[ "${PHP_BIN}" = /* && -x "${PHP_BIN}" ]] || { echo "LFAMILIA_PHP_BIN harus executable path absolut." >&2; exit 1; }

escape_sed() {
  printf '%s' "$1" | sed -e 's/[\\&|]/\\&/g'
}

APP_ESC="$(escape_sed "${APP_DIR}")"
USER_ESC="$(escape_sed "${RUN_USER}")"
GROUP_ESC="$(escape_sed "${RUN_GROUP}")"
PHP_ESC="$(escape_sed "${PHP_BIN}")"

render_unit() {
  local source="$1"
  local target="$2"
  sed \
    -e "s|@@APP_DIR@@|${APP_ESC}|g" \
    -e "s|@@RUN_USER@@|${USER_ESC}|g" \
    -e "s|@@RUN_GROUP@@|${GROUP_ESC}|g" \
    -e "s|@@PHP_BIN@@|${PHP_ESC}|g" \
    "${source}" > "${target}"
  chmod 0644 "${target}"
}

render_unit "${SCRIPT_DIR}/systemd/lfamilia-queue.service.template" "/etc/systemd/system/lfamilia-queue.service"
render_unit "${SCRIPT_DIR}/systemd/lfamilia-scheduler.service.template" "/etc/systemd/system/lfamilia-scheduler.service"

systemctl daemon-reload
systemctl enable --now lfamilia-queue.service lfamilia-scheduler.service
systemctl restart lfamilia-queue.service lfamilia-scheduler.service

systemctl --no-pager --full status lfamilia-queue.service || true
systemctl --no-pager --full status lfamilia-scheduler.service || true

echo "Systemd LFAMILIA terpasang untuk app=${APP_DIR}, user=${RUN_USER}, php=${PHP_BIN}."
