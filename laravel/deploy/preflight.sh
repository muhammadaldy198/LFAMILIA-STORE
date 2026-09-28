#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${LFAMILIA_APP_DIR:-$(cd -- "${SCRIPT_DIR}/.." && pwd)}"
PHP_BIN="${LFAMILIA_PHP_BIN:-$(command -v php || true)}"

[[ "${APP_DIR}" = /* ]] || { echo "LFAMILIA_APP_DIR harus path absolut." >&2; exit 1; }
[[ -f "${APP_DIR}/artisan" ]] || { echo "artisan tidak ditemukan di ${APP_DIR}." >&2; exit 1; }
[[ "${PHP_BIN}" = /* && -x "${PHP_BIN}" ]] || { echo "PHP CLI tidak ditemukan. Isi LFAMILIA_PHP_BIN." >&2; exit 1; }
[[ -f "${APP_DIR}/.env" ]] || { echo ".env production belum ada di ${APP_DIR}." >&2; exit 1; }

cd "${APP_DIR}"

required_extensions=(curl mbstring openssl pdo pdo_mysql tokenizer xml ctype fileinfo)
for ext in "${required_extensions[@]}"; do
  "${PHP_BIN}" -r "exit(extension_loaded('${ext}') ? 0 : 1);" || {
    echo "PHP extension wajib belum aktif: ${ext}" >&2
    exit 1
  }
done

required_env=(APP_KEY APP_URL DB_DATABASE DB_USERNAME PUBLIC_BASE_URL INTEGRATION_ENCRYPTION_KEY)
for key in "${required_env[@]}"; do
  if ! grep -Eq "^${key}=.+" .env; then
    echo "Environment wajib belum diisi: ${key}" >&2
    exit 1
  fi
done

"${PHP_BIN}" artisan lfamilia:status
"${PHP_BIN}" artisan migrate:status --no-interaction >/dev/null
"${PHP_BIN}" artisan route:list --path=api/admin --no-interaction >/dev/null
"${PHP_BIN}" artisan schedule:list --no-interaction >/dev/null

storage_paths=(storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache)
for path in "${storage_paths[@]}"; do
  mkdir -p "${path}"
  [[ -w "${path}" ]] || { echo "Path Laravel tidak writable: ${APP_DIR}/${path}" >&2; exit 1; }
done

echo "Preflight Laravel VPS berhasil. Tidak ada test destructive atau perubahan DNS yang dijalankan."
