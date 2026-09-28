#!/usr/bin/env bash
set -euo pipefail

HOST="${LFAMILIA_HOST:-lfamiliastore.my.id}"
BASE="https://${HOST}"

check() {
  local path="$1"
  local expected="$2"
  local code
  code="$(curl -ksS --resolve "${HOST}:443:127.0.0.1" -o /dev/null -w '%{http_code}' "${BASE}${path}")"
  if [[ "$code" != "$expected" ]]; then
    echo "FAIL ${path} expected=${expected} got=${code}" >&2
    exit 1
  fi
  echo "OK ${path} ${code}"
}

check /api/health 200
check /api/system-status 200
check /api/products 200
check /api/storefront 200
