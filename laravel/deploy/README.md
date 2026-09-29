# LFAMILIA Laravel VPS deployment

Target production LFAMILIA adalah **VPS Laravel + MariaDB**. MariaDB `lfamilia-store` adalah satu-satunya database authority aplikasi. Cloudflare hanya dipakai untuk DNS, TLS/proxy, Access/security, dan object storage R2; D1 tidak menjadi runtime database.

## Prinsip runtime

Browser customer/Admin memakai `/api/*` pada domain LFAMILIA. Nginx meneruskan seluruh jalur tersebut ke Laravel, dan Laravel membaca/menulis MariaDB. Frontend Vinext tidak boleh menjadi database authority atau melayani handler D1 lama. Implementasi Worker/D1 terdahulu hanya tersisa sebagai riwayat migrasi di Git dan tidak boleh diberi binding D1 pada konfigurasi runtime.

## 1. Siapkan runtime

Gunakan PHP 8.3+ dengan extension `curl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, dan `fileinfo`; Composer 2; serta MariaDB. Web root harus menunjuk ke folder `laravel/public`.

Salin `.env.example` menjadi `.env` di server dan isi **hanya bootstrap/infrastructure configuration** seperti database, `APP_KEY`, `INTEGRATION_ENCRYPTION_KEY`, `PUBLIC_BASE_URL`, dan endpoint non-secret yang memang dibutuhkan bootstrap. Jangan commit `.env`. Digiflazz, Midtrans, DOKU, KokinPay, Resend, Google Login, Turnstile, dan credential provider lain dikonfigurasi dari **Super Admin → Integrasi** dan disimpan terenkripsi di MariaDB. Profil relay historis tetap tersimpan namun tidak dibaca runtime.

Kemudian jalankan dari folder `laravel`:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan key:generate --show
php artisan config:clear
php artisan migrate --force
```

`key:generate --show` hanya menghasilkan nilai. Simpan hasilnya sebagai `APP_KEY` di `.env`; jangan menulis `.env` melalui script repo.

## 2. Preflight sebelum import/cutover

```bash
bash deploy/preflight.sh
```

Script hanya memeriksa runtime, environment wajib, koneksi/migration status, routes, scheduler, dan permission writable. Script **tidak** menjalankan `migrate:fresh`, test suite, import D1, atau perubahan DNS.

Jangan menjalankan `php artisan test` dengan konfigurasi production. Regression test tetap dilakukan oleh GitHub Actions pada database test terpisah.

## 3. Import snapshot production D1

Setelah dump D1 terbaru sudah berada di VPS dan database MariaDB tujuan benar:

```bash
php artisan lfamilia:import-d1 /absolute/path/export.sql --replace --confirm=IMPORT_D1_TO_MARIADB
php artisan lfamilia:reconcile
```

Importer melakukan rekonsiliasi jumlah row serta agregat finansial. Jika rekonsiliasi gagal, hentikan cutover dan pertahankan production lama.

## 4. Pasang queue worker + scheduler

Template systemd tidak mengandung path/user server. Installer merendernya dari environment saat dijalankan.

```bash
sudo LFAMILIA_APP_DIR="$(pwd)" \
  LFAMILIA_RUN_USER="$(stat -c '%U' .)" \
  LFAMILIA_RUN_GROUP="$(stat -c '%G' .)" \
  LFAMILIA_PHP_BIN="$(command -v php)" \
  bash deploy/install-systemd.sh
```

Periksa proses:

```bash
systemctl status lfamilia-queue.service --no-pager
systemctl status lfamilia-scheduler.service --no-pager
journalctl -u lfamilia-queue.service -n 100 --no-pager
journalctl -u lfamilia-scheduler.service -n 100 --no-pager
```

Scheduler menjalankan `schedule:work`; queue menjalankan database worker dengan retry terbatas. Keduanya membaca `.env` Laravel dari app directory, sehingga secret tidak ditulis ke unit systemd.

## 5. Smoke test tanpa DNS cutover

Dari VPS, tes virtual host/origin secara lokal terlebih dahulu, misalnya:

```bash
curl -fsS -H 'Host: lfamiliastore.my.id' http://127.0.0.1/api/health
```

Lanjutkan smoke test auth, katalog, checkout tanpa pembayaran nyata, panel RBAC, callback signature rejection, scheduler, dan koneksi provider sesuai environment yang dipilih. DNS baru boleh dipindahkan setelah data production di MariaDB direkonsiliasi dan semua smoke test lolos.

## Runtime production saat ini

Production memakai Nginx, PHP-FPM, MariaDB, dan frontend Vinext pada VPS. Jangan membuat database aplikasi kedua. Queue/scheduler LFAMILIA memakai unit systemd aplikasi dengan user aplikasi, bukan root. Email tetap dikelola terpisah melalui Resend/DNS email.


## 6. Reproduce Nginx, PHP-FPM, backup, dan runtime check

Template production yang dipakai VPS disimpan di folder deploy:

- `deploy/nginx/lfamilia-origin.conf.template` — reverse proxy HTTPS apex + redirect `www`, Laravel `/api/*`, dan frontend Vinext.
- `deploy/nginx/cloudflare-origin-only.conf` — allowlist origin agar public vhost hanya menerima Cloudflare edge + localhost.
- `deploy/php-fpm/lfamilia.conf.template` — pool PHP khusus user aplikasi; upload PHP diset di atas limit aplikasi 5 MiB.
- `deploy/logrotate/lfamilia-laravel` — rotasi log Laravel harian.
- `deploy/lfamilia-db-backup.sh` + unit systemd backup — dump MariaDB terkompresi dengan checksum dan retention.
- `deploy/vps-runtime-check.sh` — read-only smoke check service, health endpoint, migration status, dan working tree.
- `deploy/install-ops-systemd.sh` — memasang backup harian, verifikasi restore mingguan, dan healthcheck berkala tanpa mengaktifkan queue/scheduler provider. Pada CloudPanel, script membuat login client MariaDB root-only dari master credential CloudPanel tanpa menyimpan credential di repository.

Jalankan installer operasional sebagai root dari checkout aktif. Installer membaca `DB_DATABASE` dari `laravel/.env`, menyimpan hanya konfigurasi operasional non-provider di `/etc/lfamilia/ops.env`, lalu memasang unit systemd dalam keadaan belum diaktifkan agar setiap service dapat diuji satu per satu sebelum timer dinyalakan.

Laravel membaca `CF-Connecting-IP` langsung untuk rate-limit/security. Jangan mengaktifkan Nginx `real_ip_header CF-Connecting-IP` bersamaan dengan `allow/deny` origin ini tanpa mengubah desain allowlist, karena access phase akan melihat IP customer dan dapat menolak request Cloudflare yang valid.

Contoh check runtime:

```bash
cd /path/to/LFAMILIA-STORE
./laravel/deploy/vps-runtime-check.sh
```

Backup harus diuji dengan restore ke database sementara sebelum cutover; checksum file saja tidak cukup membuktikan dump dapat direstore.


## 7. Observability dan memory headroom

Production VPS juga memakai baseline operasional berikut:

- MariaDB slow query log aktif dengan `long_query_time=1` detik. File log: `/var/log/mysql/lfamilia-slow.log`.
- Slow log dan Laravel log dirotasi harian selama 14 hari.
- VPS 4 GiB menggunakan 1 GiB swap dengan `vm.swappiness=10` untuk memberi headroom saat build/restart tanpa mendorong workload normal ke swap.
- `lfamilia-healthcheck.timer` menjalankan read-only smoke check setiap 5 menit dan hanya menulis hasil ke journal; timer ini tidak melakukan payment, fulfillment, atau restart otomatis.

Template MariaDB, logrotate, PHP-FPM, Nginx, systemd, dan sysctl disimpan di folder `deploy/` agar konfigurasi VPS dapat direproduksi.
