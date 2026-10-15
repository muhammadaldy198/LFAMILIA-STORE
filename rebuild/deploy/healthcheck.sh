#!/usr/bin/env bash
set -euo pipefail

OPS_ENV_FILE="${OPS_ENV_FILE:-/etc/lfamilia/ops.env}"
[[ -r "${OPS_ENV_FILE}" ]] || { echo "Missing readable ${OPS_ENV_FILE}" >&2; exit 1; }
# shellcheck disable=SC1090
source "${OPS_ENV_FILE}"

: "${PUBLIC_BASE_URL:?PUBLIC_BASE_URL is required}"
: "${ORIGIN_HEALTH_URL:?ORIGIN_HEALTH_URL is required}"
: "${ORIGIN_HOST_HEADER:?ORIGIN_HOST_HEADER is required}"
TIMEOUT="${HEALTH_TIMEOUT_SECONDS:-10}"

# D8: kabari admin saat healthcheck gagal (dan saat pulih). Best-effort:
# notifikasi tidak boleh membuat healthcheck itu sendiri gagal.
# Jalan lewat artisan (memakai routing notif aplikasi: Telegram + Discord
# channel VPS + email). Bila aplikasi/DB ikut mati, perintah ini gagal
# diam-diam dan monitor eksternal (Better Stack) yang mengabari.
notify() {
    local state="$1" reason="${2:-}"
    local artisan
    artisan="$(dirname "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")")/artisan"
    if [[ -f "${artisan}" ]] && command -v php >/dev/null 2>&1; then
        if [[ -n "${reason}" ]]; then
            php "${artisan}" lfamilia:notify-healthcheck "${state}" --reason="${reason}" >/dev/null 2>&1 || true
        else
            php "${artisan}" lfamilia:notify-healthcheck "${state}" >/dev/null 2>&1 || true
        fi
    fi
}

fail() {
    local reason="$1"
    echo "${reason}" >&2
    notify down "${reason}"
    exit 1
}

if ! edge="$(curl --fail --silent --show-error --max-time "${TIMEOUT}" \
    "${PUBLIC_BASE_URL%/}/health/ready")"; then
    fail "Edge readiness request failed"
fi
grep -Fq '"status":"healthy"' <<<"${edge}" || fail "Edge readiness is not healthy"

if ! origin="$(curl --fail --silent --show-error --max-time "${TIMEOUT}" \
    -H "Host: ${ORIGIN_HOST_HEADER}" "${ORIGIN_HEALTH_URL}")"; then
    fail "Origin readiness request failed"
fi
grep -Fq '"status":"healthy"' <<<"${origin}" || fail "Origin readiness is not healthy"

for unit in lfamilia-queue.service lfamilia-scheduler.service; do
    systemctl is-active --quiet "${unit}" || fail "${unit} is not active"
done

notify up
echo "M12 edge, origin, queue, and scheduler health passed."
