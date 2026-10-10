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

**Sinkronisasi script systemd root-owned:** `deploy.sh` juga **tidak otomatis** memperbarui `/usr/local/lib/lfamilia/backup.sh` atau `restore-verify.sh`. Kedua copy tersebut dipasang oleh `install-systemd.sh`. `preflight.sh` sekarang memberi **WARNING** apabila salah satu script terpasang hilang atau berbeda dari source repository, tanpa mengubah file server. Setelah review, operator harus menyinkronkan script terkait secara terpisah dengan prosedur perubahan production dan kemudian menjalankan verifikasi. Jangan abaikan warning drift ketika mengaudit backup, dan jangan menganggap CI hijau berarti copy script privileged di VPS sudah terbaru.


### Penting: worker antrean fulfillment dan notifikasi

Job pembelian supplier memakai antrean `fulfillment`; email transaksional (termasuk reset password admin) dan notifikasi admin memakai antrean `notifications`. Service `lfamilia-queue.service` **harus** memproses antrean `fulfillment,notifications,default` sesuai `deploy/systemd/lfamilia-queue.service.in`.

**Deploy kode saja tidak memperbarui unit systemd yang sudah terpasang.** Setelah deployment, operator VPS harus membandingkan `systemctl cat lfamilia-queue.service` dengan template (khususnya `ExecStart`). Jika unit aktual belum memproses ketiga antrean, buat salinan aman unit saat ini, render/perbarui **hanya** unit queue memakai prosedur systemd yang disetujui, verifikasi dengan `systemd-analyze verify`, jalankan `systemctl daemon-reload`, lalu `systemctl restart lfamilia-queue.service` dan cek status. Hindari menjalankan ulang seluruh installer root tanpa meninjau semua perubahan unit/service lain terlebih dahulu.

`systemctl is-active` hanya membuktikan worker hidup, **bukan** bahwa worker memproses antrean yang benar. Periksa antrean dan pengiriman email uji yang diizinkan, serta jangan mengklaim pemulihan email/pesanan sebelum terbukti.

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
