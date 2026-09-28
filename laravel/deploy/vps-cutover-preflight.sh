#!/usr/bin/env bash
set -euo pipefail

ROOT="${LFAMILIA_ROOT:-/var/www/lfamilia-store}"
MAX_BACKUP_AGE_SECONDS="${LFAMILIA_MAX_BACKUP_AGE_SECONDS:-86400}"
BACKUP_DIR="${LFAMILIA_BACKUP_DIR:-/var/backups/lfamilia/mariadb}"

as_app() {
  if [[ "$(id -u)" -eq 0 ]]; then
    runuser -u lfamilia -- bash -lc "$1"
  else
    bash -lc "$1"
  fi
}

fail() {
  echo "FAIL $*" >&2
  exit 1
}

[[ "$(id -u)" -eq 0 ]] || fail "run this cutover preflight as root; the backup directory is intentionally root-only"

echo "== Runtime =="
"$ROOT/laravel/deploy/vps-runtime-check.sh"

echo "== Production environment =="
ENV_CHECK="$(python3 - "$ROOT/laravel/.env" <<'PY'
from pathlib import Path
import sys
vals={}
for line in Path(sys.argv[1]).read_text().splitlines():
    t=line.strip()
    if not t or t.startswith('#') or '=' not in t:
        continue
    k,v=t.split('=',1)
    vals[k.strip()]=v.strip().strip('"').strip("'")
checks={
    'APP_ENV=production': vals.get('APP_ENV') == 'production',
    'APP_DEBUG=false': vals.get('APP_DEBUG','').lower() in {'false','0','off','no'},
    'APP_URL=https': vals.get('APP_URL','').startswith('https://'),
    'DB local': vals.get('DB_HOST') in {'127.0.0.1','localhost'},
}
for name,ok in checks.items():
    print(('OK ' if ok else 'FAIL ')+name)
if not all(checks.values()):
    raise SystemExit(1)
PY
)" || { printf '%s\n' "$ENV_CHECK"; exit 1; }
printf '%s\n' "$ENV_CHECK"

echo "== Database backup =="
LATEST="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name '*.sql.gz' -printf '%T@ %p\n' | sort -nr | head -1 | cut -d' ' -f2-)"
[[ -n "$LATEST" && -f "$LATEST" ]] || fail "no MariaDB backup found"
NOW="$(date +%s)"
MTIME="$(stat -c %Y "$LATEST")"
AGE="$((NOW-MTIME))"
(( AGE <= MAX_BACKUP_AGE_SECONDS )) || fail "latest backup is too old: ${AGE}s"
gzip -t "$LATEST"
sha256sum -c "$LATEST.sha256"
echo "OK backup $(basename "$LATEST") age=${AGE}s"

echo "== Media =="
MEDIA_CHECK="$(as_app "cd '$ROOT/laravel' && php artisan tinker --execute='\$s=app(App\\Services\\MediaMigrationService::class); \$k=\$s->referencedKeys(); \$existing=DB::table(\"media_assets\")->whereIn(\"media_key\",\$k)->count(); echo count(\$k).\":\".\$existing;'")"
REFS="${MEDIA_CHECK%%:*}"
EXISTING="${MEDIA_CHECK##*:}"
[[ "$REFS" = "$EXISTING" ]] || fail "referenced media mismatch ${REFS}/${EXISTING}"
echo "OK media ${EXISTING}/${REFS}"

echo "== Deferred services =="
for service in lfamilia-queue lfamilia-scheduler; do
  if systemctl is-active --quiet "$service"; then
    fail "$service must remain stopped until provider credentials are validated"
  fi
  echo "OK $service is stopped"
done

echo "== Integration hold =="
READINESS="$(as_app "cd '$ROOT/laravel' && php artisan tinker --execute='echo json_encode(app(App\\Services\\IntegrationConfigService::class)->integrationOverview()); echo PHP_EOL; echo json_encode(app(App\\Services\\IntegrationConfigService::class)->paymentOverview());'")"
printf '%s\n' "$READINESS"

echo
echo "NON-PROVIDER CUTOVER PREFLIGHT PASSED."
echo "HOLD: do not switch production DNS or start queue/scheduler until provider credentials are re-entered and validated."
