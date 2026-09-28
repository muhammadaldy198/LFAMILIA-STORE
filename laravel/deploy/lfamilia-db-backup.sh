#!/usr/bin/env bash
set -euo pipefail
umask 077

BACKUP_DIR="/var/backups/lfamilia/mariadb"
DATABASE="${LFAMILIA_DB_NAME:-lfamilia_store}"
RETENTION_DAYS="${LFAMILIA_BACKUP_RETENTION_DAYS:-7}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="${BACKUP_DIR}/${DATABASE}-${STAMP}.sql.gz"
TMP="${TARGET}.tmp"

install -d -m 0700 "${BACKUP_DIR}"
trap 'rm -f "${TMP}"' EXIT

mariadb-dump \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "${DATABASE}" | gzip -9 > "${TMP}"

test -s "${TMP}"
gzip -t "${TMP}"
mv "${TMP}" "${TARGET}"
sha256sum "${TARGET}" > "${TARGET}.sha256"

find "${BACKUP_DIR}" -type f \( -name '*.sql.gz' -o -name '*.sql.gz.sha256' \) -mtime "+${RETENTION_DAYS}" -delete

echo "Backup OK: ${TARGET}"
