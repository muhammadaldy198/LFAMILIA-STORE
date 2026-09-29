# LFAMILIA STORE v1 — rencana pembangunan ulang

Acuan tunggal: [PRD v1](PRD-LFAMILIA-STORE-v1.md). Proyek masih pre-production. Implementasi baru dibuat bertahap di branch/PR terpisah; `main` tetap deployable hingga acceptance dan cutover.

## Keputusan setelah audit repo (2026-09-29)

| Area | Repo saat ini | PRD v1 | Tindakan |
|---|---|---|---|
| Customer frontend | Next/Vinext + React | Vue 3 + Inertia.js v2 + TailwindCSS v4 | Bangun ulang frontend |
| Backend | `laravel/` Laravel 13, PHP 8.3+ | Laravel 12, PHP 8.4 | Fondasi baru yang terisolasi, migrasikan logika bisnis setelah diverifikasi |
| Database | Migrasi historis D1 dan runtime MariaDB | Satu MySQL 8 | Rancang skema baru; jangan impor data lama tanpa kebutuhan dan rekonsiliasi eksplisit |
| Cache/queue | Laravel lama memakai file/database pada contoh konfigurasi | Redis | Konfigurasi dan uji Redis sejak M1 |
| Auth | Kode login dan sesi kompatibilitas lama | Fortify + Sanctum | Bangun auth pada M3, tanpa Staff v1 |
| Media | Endpoint media lama | Spatie Media Library | Pasang dan validasi pada M5 |
| Deployment | VPS + Cloudflare dengan runtime lama | CloudPanel/Nginx/VPS/Cloudflare | Cutover setelah M11, bukan saat scaffolding |
| UI | Alur lama | Ourastore sebagai acuan; identitas LFAMILIA | Desktop dulu; footer, bantuan, kalkulator, tautan harus terhubung ke konfigurasi panel |

Dokumen `MIGRATION-LARAVEL.md` menjelaskan migrasi terdahulu, bukan status selesai terhadap PRD v1 ini. Checklist di sana tidak boleh dijadikan bukti bahwa requirement baru sudah lulus.

## Urutan kerja dan gerbang

1. **M1 Foundation:** aplikasi Laravel 12/PHP 8.4 + Vue 3/Inertia v2/Tailwind v4, MySQL 8/Redis, konfigurasi contoh tanpa secret, CI untuk install/build/lint/test, health dasar. Uji lokal dan CI; jangan hubungkan domain.
2. **M2 Database:** migrasi MySQL untuk kategori → produk → nominal → mapping provider, wallet ledger, order/payment, voucher, membership, audit; indeks, constraints, transaksi, migration up/down.
3. **M3 Authentication:** Fortify/Sanctum, customer, Super Admin/Admin, Google OAuth, forgot password; uji akses.
4. **M4 Customer:** profil, guest, wallet, membership, riwayat, tiket dan lifecycle akun.
5. **M5 Product:** katalog dan produk manual, mapping provider, input dinamis, media dan panel konten.
6. **M6 Checkout:** nickname, voucher atomic, harga server-side, snapshot, idempotensi; tes abuse.
7. **M7 Payment:** channel routing Midtrans/DOKU, Manual QRIS, fee, webhook tervalidasi dan status monotonic.
8. **M8 Fulfillment:** adapter Digiflazz/manual, queue, callback, retry dan rekonsiliasi status unknown sebelum failover.
9. **M9 Admin:** 17 menu PRD, permission, notifikasi, integrasi terenkripsi, logo/banner/footer tersambung ke frontend.
10. **M10 Security:** rate limit, Turnstile, audit, hardening, redaksi secret.
11. **M11 Testing:** suite backend/API/UI/payment/wallet/voucher/provider dan abuse; seluruh gerbang hijau.
12. **M12 Deployment:** CloudPanel dan VPS melalui Remote Desktop Commander; DNS/edge melalui Composio; backup dan restore.
13. **M13 Acceptance:** checkout end-to-end, admin, mobile, provider, recovery; baru putuskan cutover.

Setiap milestone berakhir dengan PR yang berisi perubahan terukur, bukti tes, dan sisa risiko. Jangan merge atau mengganti jalur trafik hanya berdasarkan checklist. Tidak ada kredensial di repo: nilai sensitif operasional dikelola terenkripsi melalui Super Admin → Integrasi; secret bootstrap server tetap di environment VPS.

## Status M1 Foundation

Fondasi terisolasi berada di `rebuild/`: Laravel 12/PHP 8.4, Vue 3/Inertia v2/Tailwind v4, konfigurasi MySQL 8/Redis, lockfile Composer/npm, Inertia root page, readiness route, dan CI khusus. CI menguji Composer, npm, sintaks PHP, migrasi pada MySQL 8, build frontend, PHPUnit, dan Pint. Run awal lulus; URL publik dan deployment belum dialihkan. M2 adalah skema bisnis MySQL, bukan impor D1 lama.
