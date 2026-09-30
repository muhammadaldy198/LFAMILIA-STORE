#!/usr/bin/env bash
set -euo pipefail

APP_URL="http://127.0.0.1:8000"
LOG_FILE="/tmp/lfamilia-browser-smoke.log"
SERVER_PID=""

cleanup() {
  if [[ -n "${SERVER_PID}" ]]; then
    kill "${SERVER_PID}" 2>/dev/null || true
  fi
}
trap cleanup EXIT

CHROME_BIN="$(command -v google-chrome || command -v google-chrome-stable || command -v chromium || command -v chromium-browser || true)"
if [[ -z "${CHROME_BIN}" ]]; then
  echo "Headless Chrome/Chromium is required for M11 browser smoke."
  exit 1
fi

php artisan serve --host=127.0.0.1 --port=8000 >"${LOG_FILE}" 2>&1 &
SERVER_PID=$!

for attempt in {1..30}; do
  if curl --fail --silent --show-error "${APP_URL}/" >/dev/null; then
    break
  fi
  if [[ "${attempt}" -eq 30 ]]; then
    cat "${LOG_FILE}"
    exit 1
  fi
  sleep 1
done

assert_page() {
  local path="$1"
  local expected="$2"
  local output
  output="$("${CHROME_BIN}"     --headless=new     --no-sandbox     --disable-gpu     --disable-dev-shm-usage     --virtual-time-budget=3000     --dump-dom "${APP_URL}${path}" 2>/dev/null)"

  if ! grep -Fq "${expected}" <<<"${output}"; then
    echo "Browser smoke failed for ${path}; expected: ${expected}"
    cat "${LOG_FILE}"
    exit 1
  fi
}

assert_page "/" "LFAMILIA STORE"
assert_page "/login" "Masuk LFAMILIA"
assert_page "/register" "Daftar LFAMILIA"
assert_page "/orders/check" "Cek status pesananmu"
assert_page "/admin/login" "Admin LFAMILIA"

echo "M11 browser smoke passed."
