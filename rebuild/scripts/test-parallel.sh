#!/usr/bin/env bash
# Jalankan suite tes paralel dengan satu database per worker.
#
# Kenapa perlu: paratest menjalankan beberapa proses sekaligus. Bila semuanya
# memakai satu database, migrasi/refresh satu worker menimpa query worker lain
# (MySQL 1412 "Table definition has changed").
#
# Pemakaian:
#   WORKERS=2 bash scripts/test-parallel.sh
#   WORKERS=4 bash scripts/test-parallel.sh
#
# Database worker: <DB_DATABASE>_1 .. _N (dibuat dan dimigrasi di sini).
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-php}"
WORKERS="${WORKERS:-2}"
BASE_DB="${PARATEST_DB_BASE:-lfamilia_admin_redesign_test}"
export PARATEST_DB_BASE="$BASE_DB"

create_database() {
    "$PHP_BIN" -r '
        $db = $argv[1];
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%s", getenv("DB_HOST") ?: "127.0.0.1", getenv("DB_PORT") ?: "3306"),
            getenv("DB_USERNAME") ?: "root",
            getenv("DB_PASSWORD") ?: "",
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `".str_replace("`", "", $db)."`");
    ' "$1"
}

for i in $(seq 1 "$WORKERS"); do
    db="${BASE_DB}_${i}"
    create_database "$db"
    DB_DATABASE="$db" "$PHP_BIN" artisan migrate --force --no-interaction >/dev/null
    echo "worker $i -> $db siap"
done

"$PHP_BIN" vendor/bin/paratest \
    --configuration phpunit.xml \
    --processes="$WORKERS" \
    "$@"
