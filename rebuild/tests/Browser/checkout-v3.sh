#!/usr/bin/env bash
set -euo pipefail
export DB_DATABASE=lfamilia_checkout_preview_v3
export LFAMILIA_VISUAL_QA=1
export TRUSTED_PROXIES=127.0.0.1
export CHECKOUT_QA_URL="${CHECKOUT_QA_URL:-http://127.0.0.1:8003}"
export CHROME_BIN="${CHROME_BIN:-$(command -v google-chrome || command -v chromium || true)}"
export APP_URL="$CHECKOUT_QA_URL"
# Validate local connection before executing migrations, not only before seeding.
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!app()->environment(["local","testing"]) || config("database.default")!=="mysql" || config("database.connections.mysql.database")!=="lfamilia_checkout_preview_v3" || !in_array(config("database.connections.mysql.host"),["127.0.0.1","localhost"],true)) {fwrite(STDERR,"Local isolated QA database required\n");exit(1);}'
php artisan migrate --force
if [[ ! -e public/storage ]]; then php artisan storage:link; fi
php tests/Browser/checkout-v3-fixture.php
port="$(node -e 'console.log(new URL(process.env.CHECKOUT_QA_URL).port || 80)')"
log="${CHECKOUT_QA_OUTPUT:-/tmp/lfamilia-checkout-v3}/server.log"
mkdir -p "$(dirname "$log")"
setsid php artisan serve --no-reload --host=127.0.0.1 --port="$port" >"$log" 2>&1 &
server_pid=$!
cleanup(){ kill -- "-$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; }
trap cleanup EXIT
for attempt in {1..30}; do
 if curl --fail --silent "$CHECKOUT_QA_URL/health/ready" >/dev/null; then break; fi
 if [[ "$attempt" == 30 ]]; then cat "$log"; exit 1; fi
 sleep 1
done
node tests/Browser/checkout-v3.mjs
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach (["orders","payment_transactions"] as $table) { $count=Illuminate\Support\Facades\DB::table($table)->count(); echo "$table: $count\n"; if ($count!==0) exit(1); }'
