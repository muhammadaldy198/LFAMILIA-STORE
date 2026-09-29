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

edge="$(curl --fail --silent --show-error --max-time "${TIMEOUT}"   "${PUBLIC_BASE_URL%/}/health/ready")"
grep -Fq '"status":"healthy"' <<<"${edge}" || { echo "Edge readiness is not healthy" >&2; exit 1; }

origin="$(curl --fail --silent --show-error --max-time "${TIMEOUT}"   -H "Host: ${ORIGIN_HOST_HEADER}" "${ORIGIN_HEALTH_URL}")"
grep -Fq '"status":"healthy"' <<<"${origin}" || { echo "Origin readiness is not healthy" >&2; exit 1; }

for unit in lfamilia-queue.service lfamilia-scheduler.service; do
  systemctl is-active --quiet "${unit}" || { echo "${unit} is not active" >&2; exit 1; }
done

echo "M12 edge, origin, queue, and scheduler health passed."
