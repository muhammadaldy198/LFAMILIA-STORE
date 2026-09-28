#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${LFAMILIA_ROOT:-/var/www/lfamilia-store}"
HOST="${LFAMILIA_HOST:-lfamiliastore.my.id}"

check_service() {
  local name="$1"
  if ! systemctl is-active --quiet "$name"; then
    echo "FAIL service $name is not active" >&2
    return 1
  fi
  echo "OK service $name"
}

check_http() {
  local path="$1"
  local expected="$2"
  local code
  code="$(curl -ksS --resolve "${HOST}:443:127.0.0.1" -o /dev/null -w '%{http_code}' "https://${HOST}${path}")"
  if [[ "$code" != "$expected" ]]; then
    echo "FAIL ${path}: expected ${expected}, got ${code}" >&2
    return 1
  fi
  echo "OK ${path} -> ${code}"
}

check_service nginx
check_service php8.3-fpm
check_service mariadb
check_service lfamilia-web

check_http / 200
check_http /api/health 200
check_http /api/system-status 200
check_http /api/products 200
check_http /api/storefront 200
check_http /admin/panel/login 200
check_http /staff/panel/login 200

runuser -u lfamilia -- bash -lc "cd '${APP_ROOT}' && git diff --check"
runuser -u lfamilia -- bash -lc "cd '${APP_ROOT}/laravel' && php artisan migrate:status >/dev/null"

echo "VPS runtime check passed."
