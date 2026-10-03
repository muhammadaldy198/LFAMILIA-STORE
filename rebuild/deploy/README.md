# Production deployment & operations

Runtime production adalah Laravel 12 / PHP 8.4 / MySQL 8 / Redis di VPS dengan Nginx/CloudPanel. Production source ref adalah `main`.

## Server-only configuration

File berikut tidak boleh di-commit:

- `rebuild/.env` — Laravel/infrastructure bootstrap config.
- `/etc/lfamilia/ops.env` — deploy paths, service names, health URLs, backup policy/path.
- MySQL backup/admin client config.
- backup encryption passphrase file.
- rclone config bila remote backup dipakai.

Provider/application credential yang dikelola aplikasi tetap di Super Admin → Integrasi, bukan di deploy env/source.

## Deployment runbook

Urutan operasional yang aman:

1. Pastikan PR sudah merged ke `main` dan CI hijau.
2. Jalankan encrypted database backup dengan `bash deploy/backup.sh`.
3. Pastikan backup berhasil sebelum perubahan runtime.
4. Jalankan `bash deploy/preflight.sh`.
5. Jalankan `bash deploy/deploy.sh`.
6. Jalankan/konfirmasi `bash deploy/healthcheck.sh`.
7. Periksa queue/scheduler dan error log bila healthcheck gagal.

**Catatan aktual:** `deploy.sh` memanggil `preflight.sh`, tetapi tidak otomatis memanggil `backup.sh`. Karena itu backup-before-deploy adalah kewajiban runbook/operator, bukan safety net otomatis di script.

## Apa yang dilakukan deploy.sh

Script:

- menolak dirty Git worktree;
- fetch + checkout production ref;
- fast-forward-only ke origin;
- masuk maintenance mode;
- Composer install production;
- `npm ci` + production build;
- clear route/config;
- `php artisan migrate --force`;
- memastikan storage link;
- config/view cache;
- queue restart;
- restart PHP-FPM, queue, scheduler;
- memastikan timer healthcheck/backup/restore-verification aktif;
- keluar maintenance;
- menjalankan healthcheck.

Deployment gagal meninggalkan aplikasi dalam maintenance mode agar half-upgraded runtime tidak disajikan.

## Preflight

`deploy/preflight.sh` memverifikasi file ops/env, executable yang diperlukan, PHP 8.4+, PHP extensions, production Laravel settings, Composer config, MySQL connectivity/version, Redis localhost connectivity, backup prerequisites, dan service PHP-FPM.

MariaDB ditolak; target runtime adalah MySQL 8.

## Healthcheck

`deploy/healthcheck.sh` memeriksa:

- readiness melalui public/Cloudflare URL;
- readiness langsung ke origin dengan Host header;
- `lfamilia-queue.service`;
- `lfamilia-scheduler.service`.

Production audit 2026-10-04 menunjukkan healthcheck timer/service terakhir sukses dan queue/scheduler aktif.

## Backup

`deploy/backup.sh`:

- membuat consistent MySQL dump;
- menambahkan metadata count/financial sanity fields;
- membuat tar archive;
- mengenkripsi archive dengan AES-256-CBC + PBKDF2;
- membuat SHA-256 sidecar;
- menerapkan retention;
- dapat menyalin encrypted archive/checksum ke rclone remote bila `BACKUP_REQUIRE_REMOTE=true`.

Production audit 2026-10-04: backup timer aktif, last service result sukses, retention 7 hari, tetapi `BACKUP_REQUIRE_REMOTE=false`. Jadi dokumentasi **tidak boleh mengklaim external/object-storage backup sedang aktif**.

### Media

Script `backup.sh` saat ini membackup **database + metadata**, bukan seluruh media filesystem. Media harus dilindungi oleh backup filesystem/VPS/storage terpisah sebelum dianggap recoverable. Jangan menyatakan media sudah tercakup oleh encrypted DB backup.

## Restore verification

`deploy/restore-verify.sh`:

- memilih encrypted backup terbaru;
- memverifikasi checksum;
- decrypt/extract ke temp directory;
- membuat database verifikasi sementara;
- restore dump;
- membandingkan metadata penting;
- menghapus database sementara;
- memverifikasi keberadaan remote copy bila remote backup diwajibkan.

Script ini **tidak melakukan overwrite database production**. Production restore tetap tindakan recovery eksplisit yang harus mempertimbangkan downtime, database target, dan media state.

Production restore-verification timer aktif dan hasil service terakhir yang diperiksa sukses.

## Source rollback

```bash
ROLLBACK_REF=<known-good-sha> \
CONFIRM_ROLLBACK=ROLLBACK_SOURCE_ONLY \
bash deploy/rollback-source.sh
```

Source rollback:

- checkout commit yang ditentukan;
- reinstall production dependencies/build/cache;
- restart PHP-FPM/queue/scheduler;
- healthcheck.

**Database migrations sengaja tidak di-rollback otomatis.** Commit rollback harus kompatibel dengan schema yang sudah berjalan atau recovery database harus direncanakan terpisah.

## Systemd

`deploy/install-systemd.sh` dan templates di `deploy/systemd/` mengelola queue, scheduler, backup, restore verification, dan healthcheck services/timers sesuai config server.

## Secret safety

Jangan menaruh DB password, backup passphrase, rclone secret, APP_KEY, provider token, private key, atau origin credential di Markdown/Git.
