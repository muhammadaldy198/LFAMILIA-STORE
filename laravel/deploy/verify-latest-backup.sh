#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${LFAMILIA_BACKUP_DIR:-/var/backups/lfamilia/mariadb}"
DB="lfamilia_restore_check_$$"
trap 'mariadb -e "DROP DATABASE IF EXISTS '$DB';" >/dev/null 2>&1 || true' EXIT

LATEST="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name '*.sql.gz' -printf '%T@ %p\n' | sort -nr | head -1 | cut -d' ' -f2-)"
[[ -n "$LATEST" && -f "$LATEST" ]] || { echo "No backup found" >&2; exit 1; }

gzip -t "$LATEST"
sha256sum -c "$LATEST.sha256"
mariadb -e "CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gzip -dc "$LATEST" | mariadb "$DB"
mariadb-check --check "$DB" >/dev/null

PRODUCTS="$(mariadb -N -e "SELECT COUNT(*) FROM $DB.products;")"
PACKAGES="$(mariadb -N -e "SELECT COUNT(*) FROM $DB.product_packages;")"
[[ "$PRODUCTS" -gt 0 && "$PACKAGES" -gt 0 ]] || { echo "Restore sanity check failed" >&2; exit 1; }

echo "Backup restore verification OK: $(basename "$LATEST") products=$PRODUCTS packages=$PACKAGES"
