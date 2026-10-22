#!/usr/bin/env bash
set -euo pipefail

APP_URL="http://127.0.0.1:8000"
LOG_FILE="/tmp/lfamilia-browser-smoke.log"
SERVER_PID=""

cleanup() {
  if [[ -n "${SERVER_PID}" ]]; then
    kill -- "-${SERVER_PID}" 2>/dev/null || true
    wait "${SERVER_PID}" 2>/dev/null || true
  fi
}
trap cleanup EXIT

CHROME_BIN="$(command -v google-chrome || command -v google-chrome-stable || command -v chromium || command -v chromium-browser || true)"
if [[ -z "${CHROME_BIN}" ]]; then
  echo "Headless Chrome/Chromium is required for M11 browser smoke."
  exit 1
fi

php artisan cache:clear >/dev/null
php artisan storage:link >/dev/null

php tests/Browser/fixture.php >/tmp/lfamilia-browser-fixture.log

setsid php artisan serve --no-reload --host=127.0.0.1 --port=8000 >"${LOG_FILE}" 2>&1 &
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
  local width="$3"
  local height="$4"
  local output=""
  local attempt=""
  # One retry per page: the FIRST Chrome invocation on a fresh CI runner
  # can stall past 30s on cold start (empty profile/cache, contended
  # 2-core runner) even though the page itself renders in seconds —
  # observed once as exit 124 with no failing message. A genuinely hung
  # page still fails: the retry stalls the same way and the grep below
  # reports it. The per-attempt echo names the page BEFORE Chrome runs,
  # so a timeout in CI logs exactly where it happened instead of
  # failing silently. The 30s per-attempt bound is unchanged.
  for attempt in 1 2; do
    echo "Browser smoke: asserting $path at ${width}x${height} (attempt ${attempt}/2)"
    # Prevent a stalled Chromium process from blocking GitHub CI indefinitely.
    if output="$(timeout --signal=TERM --kill-after=5s 30s "$CHROME_BIN" \
      --headless=new \
      --no-sandbox \
      --disable-gpu \
      --disable-dev-shm-usage \
      --window-size="$width,$height" \
      --virtual-time-budget=3000 \
      --dump-dom "$APP_URL$path" 2>/dev/null)"; then
      break
    fi
    echo "Browser smoke: Chrome timed out on $path (attempt ${attempt}/2)"
  done

  if ! grep -Fq "$expected" <<<"$output"; then
    echo "Browser smoke failed for $path at $width x $height; expected: $expected"
    cat "$LOG_FILE"
    exit 1
  fi
}

assert_page "/" "LFAMILIA STORE" 1440 1000
assert_page "/login" "Masuk LFAMILIA" 1440 1000
assert_page "/register" "Daftar LFAMILIA" 1440 1000
assert_page "/orders/check" "Cek status pesananmu" 1440 1000
assert_page "/admin/login" "Masuk admin" 1440 1000


# Stage 7.8: render the real customer checkout page at desktop and mobile sizes.
assert_page "/catalog/browser-checkout-game" "Browser Checkout Game" 1440 1000
assert_page "/catalog/browser-checkout-game" "Data Akun" 1440 1000
assert_page "/catalog/browser-checkout-game" "Pilih Nominal" 1440 1000
assert_page "/catalog/browser-checkout-game" "Pilih Pembayaran" 1440 1000
assert_page "/catalog/browser-checkout-game" "100 Diamonds" 1440 1000
assert_page "/catalog/browser-checkout-game" "Ringkasan Pesanan" 390 844
assert_page "/catalog/browser-checkout-game" "Lihat voucher" 390 844

echo "Running responsive browser smoke (maximum 5 minutes)"
timeout --signal=TERM --kill-after=10s 5m node tests/Browser/responsive.mjs "$CHROME_BIN"
echo "Running admin browser smoke (maximum 15 minutes)"
timeout --signal=TERM --kill-after=10s 15m node tests/Browser/admin-ui.mjs "$CHROME_BIN"

# Browser-fallback icon must exist even when no Admin favicon is configured.
curl --fail --silent --show-error "$APP_URL/favicon.ico" >/dev/null || {
  echo "Default favicon is unavailable (GET /favicon.ico)."
  exit 1
}
echo "Running checkout Lab comparison across ten viewports"
if ! timeout --signal=TERM --kill-after=10s 5m node tests/Browser/checkout-visual.mjs "$CHROME_BIN"; then
  echo "Browser server HTTP errors:"
  grep -n -E '404|Not Found' "$LOG_FILE" || true
  exit 1
fi
echo "M11 browser smoke passed."
