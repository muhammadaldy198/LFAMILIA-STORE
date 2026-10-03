# LFAMILIA STORE — REBUILD PLAN

> **HISTORICAL / LEGACY / NO LONGER ACTIVE AS IMPLEMENTATION STATUS**
>
> Dokumen ini merekam rencana pembangunan ulang awal. Milestone M1–M13 di bawah adalah histori perencanaan, **bukan** status runtime saat ini. Untuk kondisi aktual gunakan [../rebuild/README.md](../rebuild/README.md), [../rebuild/ARCHITECTURE.md](../rebuild/ARCHITECTURE.md), dan dokumentasi operasional lain di `rebuild/`.

## Baseline rencana awal

Target stack yang direncanakan dan sekarang menjadi runtime utama:

- Laravel 12 / PHP 8.4
- Vue 3 / Inertia.js v2 / TailwindCSS v4
- MySQL 8
- Redis
- Nginx / CloudPanel / VPS
- Cloudflare
- Fortify + Sanctum
- Spatie Media Library

## Konteks legacy

Pada awal rebuild, repository masih memiliki atau membandingkan runtime lama seperti Next/Vinext, Cloudflare Workers/D1, Drizzle, dan MariaDB. Referensi tersebut hanya konteks migrasi historis. Runtime aktif sekarang berada di `rebuild/` dan memakai MySQL 8.

Relay Digiflazz lama, Cloudflare Pages sebagai runtime utama, D1 sebagai database produksi, dan MariaDB sebagai database final **tidak aktif sebagai arsitektur target/current**.

## Milestone historis

1. M1 Foundation
2. M2 Database
3. M3 Authentication
4. M4 Customer
5. M5 Product
6. M6 Checkout
7. M7 Payment
8. M8 Fulfillment
9. M9 Admin
10. M10 Security
11. M11 Testing
12. M12 Deployment
13. M13 Acceptance

Status fitur tidak boleh disimpulkan dari urutan milestone ini. Sebagai contoh, auth/payment/fulfillment/admin yang dahulu “akan dibuat pada milestone berikutnya” sekarang sudah memiliki implementasi kode dan dokumentasi tersendiri.

## Referensi current

- [Runtime](../rebuild/README.md)
- [Architecture](../rebuild/ARCHITECTURE.md)
- [Database](../rebuild/DATABASE.md)
- [Payment](../rebuild/PAYMENT.md)
- [Fulfillment](../rebuild/FULFILLMENT.md)
- [Admin](../rebuild/ADMIN.md)
- [Security](../rebuild/SECURITY.md)
- [Testing](../rebuild/TESTING.md)
- [Deployment](../rebuild/deploy/README.md)

PRD tetap menjadi requirement produk, sedangkan implementasi aktual harus diverifikasi terhadap kode dan operasi.
