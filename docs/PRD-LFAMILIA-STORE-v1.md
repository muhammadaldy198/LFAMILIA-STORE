> **STATUS DOKUMEN:** PRD ini adalah requirement produk. Ia bukan laporan status implementasi per 2026-10-04. Untuk kondisi kode/runtime aktual lihat `rebuild/README.md` dan `rebuild/ARCHITECTURE.md`. Milestone/checklist yang belum dicentang di PRD tidak boleh dipakai untuk menyimpulkan fitur tersebut belum ada tanpa verifikasi kode.

> **STATUS DOKUMEN:** PRD ini adalah requirement produk. Ia bukan laporan status implementasi per 2026-10-04. Untuk kondisi kode/runtime aktual lihat `rebuild/README.md` dan `rebuild/ARCHITECTURE.md`. Milestone/checklist yang belum dicentang di PRD tidak boleh dipakai untuk menyimpulkan fitur tersebut belum ada tanpa verifikasi kode.

# LFAMILIA STORE
## PRODUCT REQUIREMENTS DOCUMENT (PRD)
### MASTER v1.0 — Laravel + CloudPanel + VPS

| Item | Keputusan | Status |
|---|---|---|
| Baseline pembangunan / single source of truth | Dokumen ini | ✅ |
| Domain utama | lfamiliastore.my.id | — |
| Bahasa | Bahasa Indonesia | — |
| Platform awal | Website | — |
| Platform berikutnya | Aplikasi Android | — |
| Fase | Pre-production / pembangunan baru | — |

Dokumen ini menjadi acuan utama untuk arsitektur, fitur, keamanan, pengujian, dan deployment LFAMILIA STORE.

---

## 1. Tujuan Produk
LFAMILIA STORE adalah platform penjualan produk digital, top-up game, PPOB, voucher, dan entertainment dengan dukungan guest checkout di website, customer account, wallet, multi-provider, multi-payment, fulfillment otomatis maupun manual, dan panel administrasi terpusat.

- Website dibangun ulang dari nol berdasarkan PRD ini.
- Fitur bisnis tidak boleh dirombak besar tanpa perubahan PRD.
- Frontend customer ditargetkan sangat mirip dengan referensi Ourastore.com, tetapi menggunakan identitas LFAMILIA STORE sendiri.
- Prioritas visual: desktop terlebih dahulu, kemudian Android/mobile browser.

## 2. Stack Teknologi

| Komponen | Teknologi |
|---|---|
| Backend | Laravel 12, PHP 8.4 |
| Frontend | Vue 3, Inertia.js v2, TailwindCSS v4 |
| Database | MySQL 8 |
| Cache / Queue | Redis |
| Web Server | Nginx |
| Auth | Laravel Fortify + Sanctum |
| Media | Spatie Media Library |
| Server Management | CloudPanel |
| Edge / Security | Cloudflare |

## 3. Arsitektur Sistem
Satu Laravel backend/API menjadi source of truth untuk website dan aplikasi Android di masa depan. Aplikasi Android boleh berada di VPS/application server berbeda, tetapi akun, saldo, order, produk, voucher, dan transaksi tetap menggunakan satu sumber data bisnis yang konsisten.

- Website customer → Laravel backend/API → MySQL/Redis.
- Android app → Laravel API yang sama → sumber data bisnis yang sama.
- Jika trafik membesar, application server dapat dipisah tanpa membuat database bisnis kedua yang tidak sinkron.

## 4. Kategori Produk
- Game
- Pulsa
- Paket Data
- PLN
- Voucher
- Entertainment
- PPOB lainnya

E-wallet tidak menjadi kategori katalog produk awal. Arsitektur kategori harus extensible agar kategori baru dapat ditambahkan dari panel tanpa membongkar checkout.

## 5. Model Katalog & Multi-Provider
- Struktur utama: **Kategori → Produk → Nominal/Paket → Provider Mapping**.
- Satu nominal dapat memiliki satu atau beberapa provider.
- Business logic checkout tidak boleh dikunci hanya untuk Digiflazz.
- Provider awal: **Digiflazz** dan **Manual**.
- Provider tambahan dapat ditambahkan kemudian melalui abstraction/provider adapter.
- Jika provider pertama berstatus pending, processing, timeout, atau unknown, **failover dilarang** sampai reconciliation memastikan transaksi pertama belum diproses.
- Failover hanya boleh dilakukan bila provider pertama dipastikan belum memproses transaksi.

## 6. Digiflazz
- Panel LFAMILIA menyediakan import dan sync produk/SKU Digiflazz.
- `buyer_sku_code` berasal dari data Digiflazz dan tidak dapat dimanipulasi customer.
- Seller selection, ranking, toleransi harga, dan auto-switch seller dikelola oleh Digiflazz Tools terpisah.
- LFAMILIA hanya mengonsumsi hasil/mapping yang dibutuhkan untuk fulfillment.

## 7. Produk Manual
- Produk Manual memiliki tab sendiri dan tidak terbatas pada Roblox.
- Manual dapat digunakan untuk gamepass, gift, produk custom, entertainment, voucher tertentu, atau produk digital lain yang fulfillment-nya dilakukan manusia.
- Mode fulfillment minimal: `AUTO_PROVIDER` dan `MANUAL`.
- Produk manual menggunakan ID internal database LFAMILIA; tidak perlu meniru SKU provider.
- Produk manual dapat menggunakan Manual QRIS dan notifikasi internal/webhook.

## 8. Customer Input & Nickname
- Field per produk configurable: User ID, Zone ID, Server ID, nomor HP, nomor meter PLN, email, username, dan field lain sesuai kebutuhan provider.
- Server hanya mengirim field yang memang dibutuhkan provider.
- Provider nickname utama: **KokinPay**.
- Super Admin dapat mengelola game code, mapping field, test nickname, enable/disable checker, dan status koneksi.
- Jika checker gagal/down, customer tetap boleh melanjutkan checkout dengan peringatan bahwa nickname tidak berhasil diverifikasi.
- Nama provider nickname tidak ditampilkan kepada customer.

## 9. Akun Pelanggan
- Website mendukung **Guest Checkout** dan **Registered Customer**.
- Login: email/password dan Google OAuth.
- Setelah Google login, nomor HP wajib diisi tetapi OTP tidak diwajibkan pada v1.
- Fitur akun: profil, wallet, membership tier, riwayat transaksi, detail pesanan, tiket komplain, ubah password, lupa password, dan hapus akun.

## 10. Membership Pelanggan
Membership pelanggan terpisah dari RBAC Admin. Membership digunakan untuk benefit komersial customer dan tidak memberikan akses panel administrasi.

| Urutan | Tier Customer |
|---|---|
| 1 | BASIC |
| 2 | SILVER |
| 3 | GOLD |
| 4 | DIAMOND |
| 5 | PLATINUM |
| 6 | MAFIA |

- BASIC adalah tier awal/default kecuali aturan panel menentukan lain.
- Syarat naik tier, benefit, pricing advantage, badge, promo khusus, atau limit dibuat configurable melalui panel Super Admin.
- Threshold dan benefit detail tidak di-hardcode pada business logic agar dapat diubah tanpa deployment.
- Perubahan tier manual oleh Super Admin wajib masuk audit log.

## 11. Guest Customer
- Guest boleh melakukan pembelian di website.
- Guest **tidak memiliki wallet/saldo**.
- Guest memperoleh order ID dan dapat mengecek status order.
- Guest dapat memberi review setelah order berhasil melalui mekanisme token/signed link yang aman.
- Aplikasi Android tidak mendukung guest checkout; user wajib login dan menggunakan saldo.

## 12. Lifecycle Akun
Akun dapat masuk automatic cleanup bila saldo Rp0, belum pernah bertransaksi, dan tidak aktif ≥30 hari. Sebelum deletion, backend harus memverifikasi tidak ada saldo, transaksi, refund, tiket aktif, atau kewajiban bisnis lain. Customer juga boleh menghapus akun sendiri sesuai policy data retention.

## 13. Wallet / Saldo
- Wallet wajib untuk registered customer.
- Sumber saldo: top-up customer, penambahan oleh Super Admin, dan refund.
- Minimum top-up default Rp10.000 dan dapat diubah melalui panel.
- Saldo dapat dipakai untuk seluruh produk yang mendukung pembayaran saldo.
- Saldo tidak dapat ditarik menjadi uang tunai oleh customer.
- Wallet menggunakan **ledger transaction** dengan amount, balance before/after, source, reference, actor, timestamp, dan idempotency key.
- Saldo negatif dilarang; perubahan saldo harus menggunakan database transaction/locking yang benar.

## 14. Aplikasi Android (Fase Berikutnya)
- Wajib login.
- Menggunakan akun LFAMILIA yang sama dengan website.
- Pembayaran transaksi menggunakan wallet/saldo LFAMILIA.
- Berkomunikasi ke Laravel melalui authenticated API.
- Tidak membuat database customer kedua yang berdiri sendiri.

## 15. Flow Checkout Customer
1. Masukkan ID / data tujuan.
2. Cek nickname bila tersedia.
3. Pilih produk / nominal.
4. Pilih metode pembayaran.
5. Masukkan voucher.
6. Lihat ringkasan harga final.
7. Buat order.
8. Bayar.
9. Backend memverifikasi pembayaran.
10. Provider / manual fulfillment diproses.
11. Order selesai.

Nominal produk diurutkan berdasarkan nilai nominal produk, bukan berdasarkan harga jual.

## 16. Status Order

| Status Customer | Makna |
|---|---|
| Menunggu Pembayaran | Order dibuat tetapi pembayaran belum terverifikasi |
| Dibayar | Pembayaran sudah valid |
| Diproses | Fulfillment sedang berjalan |
| Berhasil | Fulfillment selesai |
| Gagal | Transaksi gagal sesuai state machine |
| Dibatalkan | Pembayaran/order dibatalkan sebelum fulfillment dimulai |
| Kedaluwarsa | Batas waktu pembayaran habis |
| Refund | Dana dikembalikan sesuai flow refund |

## 17. Pricing & Snapshot
- Formula: **harga modal + margin produk + biaya payment channel = harga final customer**.
- Margin utama per produk dan menggunakan persentase.
- Biaya payment gateway dibebankan kepada customer.
- Semua kalkulasi final dilakukan backend; frontend tidak dipercaya sebagai sumber harga.
- Saat order dibuat, harga modal, margin, discount, fee, total, provider, package, dan customer input disimpan sebagai **snapshot**.
- Harga order tidak berubah walaupun harga provider berubah setelah order dibuat.
- Jika harga provider melebihi `max_price`, fulfillment otomatis dihentikan untuk pengecekan/reconciliation.

## 18. Voucher
- Promo v1 hanya voucher kode diskon.
- Mendukung periode aktif, minimum transaksi, limit per customer, total kuota, scope produk, dan scope kategori.
- Redemption harus atomic untuk mencegah double-use akibat concurrent checkout.

## 19. Payment Architecture
- Gateway awal: **Midtrans Snap** dan **DOKU Direct API**.
- Customer tidak memilih nama gateway; customer hanya memilih channel/metode pembayaran.
- Super Admin mengatur routing channel → gateway.
- Panel mendukung enable/disable gateway, enable/disable channel, biaya per channel, dan maintenance mode.
- Backend/webhook tervalidasi menjadi sumber otoritas status paid; redirect frontend **bukan** bukti pembayaran.

## 20. Manual QRIS
1. Customer membuat order.
2. Sistem menampilkan QRIS LFAMILIA.
3. Order berstatus Menunggu Pembayaran.
4. Admin Panel dan Telegram/Discord menerima notifikasi order baru.
5. Super Admin/Admin mengecek pembayaran sesuai izin.
6. Pembayaran dikonfirmasi manual.
7. Order menjadi Dibayar lalu Diproses.
8. Fulfillment manual dilakukan.
9. Order menjadi Berhasil.

Manual QRIS tidak memiliki payment-gateway webhook asli. Sistem menggunakan notifikasi internal/webhook ketika order dibuat dan ketika status penting berubah.

## 21. Notifikasi
- Admin Panel notifications.
- Telegram webhook.
- Discord webhook.
- Event: order baru, pembayaran berhasil, manual QRIS, provider gagal, pending terlalu lama, order berhasil, refund, tiket baru, dan integration/system error.
- Telegram/Discord dapat diaktif/nonaktifkan dari konfigurasi Super Admin.

## 22. Fulfillment & Reconciliation
- Produk otomatis dikirim ke provider melalui queue setelah payment terverifikasi.
- Status pending dicek ulang oleh queue/job.
- Timeout/unknown **tidak boleh** memicu provider kedua sampai reconciliation selesai.
- Duplicate provider request dan duplicate fulfillment wajib dicegah.
- Setiap provider interaction memiliki reference unik dan audit trail.

## 23. Review Pelanggan
- Order Berhasil membuat customer/guest eligible untuk menulis review.
- Sistem tidak membuat isi review otomatis atas nama customer.
- Guest dapat menggunakan signed order token/link yang aman.
- Review langsung dapat tampil pada v1 tanpa moderasi awal.
- Admin tetap dapat menghapus review bermasalah.

## 24. Role & Akses Administrasi

| Role | Akses |
|---|---|
| SUPER_ADMIN | Seluruh sistem: integrasi, credential, wallet, admin, laporan finansial, settlement, audit, system health, konfigurasi |
| ADMIN | Operasional sesuai permission; tidak boleh melihat secret/API credential sensitif |

Staff tidak digunakan pada v1. Membership customer BASIC–MAFIA bukan role administrasi.

## 25. Menu Panel
1. Dashboard
2. Pesanan
3. Produk
4. Manual
5. Banner & Konten
6. Digiflazz
7. Provider
8. Pembayaran
9. Pelanggan
10. Promo
11. Layanan Pelanggan
12. Laporan
13. Admin & Akses
14. Pengaturan
15. Integrasi
16. System Health
17. Audit Log

## 26. Audit Log
- Catat actor, role, action, target, before, after, IP, user agent, timestamp, dan correlation ID.
- Wajib untuk aksi sensitif: harga, saldo, refund, provider, gateway, integration credential, reveal secret, role/admin, manual payment confirmation, dan perubahan membership customer.

## 27. Frontend Customer
- Dibuat ulang dengan target visual/alur sangat mirip Ourastore.com.
- Homepage juga mengikuti referensi Ourastore sebagai acuan UX/UI.
- Branding tetap LFAMILIA STORE.
- Tema dark, modern, gaming/digital commerce.
- Buang pola kotak-kotak pada background.
- Desktop dikerjakan dan divalidasi terlebih dahulu; mobile setelah desktop stabil.

## 28. Media Management
- Gunakan Spatie Media Library.
- Super Admin dapat mengganti logo, favicon, banner desktop/mobile, gambar produk, gambar nominal, popup, dan aset konten.
- Storage dapat menggunakan local VPS dan/atau object storage sesuai reliability/biaya.

## 29. Email
- Provider: **Resend**.
- Event: verification, reset password, payment success, transaction success, refund, support ticket, dan security notification relevan.
- Email dikirim melalui queue.

## 30. Infrastruktur VPS & CloudPanel
- Satu VPS awal menjalankan Nginx, PHP-FPM, Laravel, MySQL, Redis, Queue Worker, Scheduler, dan CloudPanel.
- CloudPanel mengelola site, domain, SSL, PHP, database, dan server-level configuration.
- Cloudflare berada di depan VPS untuk DNS, proxy, WAF, rate limiting, Turnstile, dan edge security.

## 31. GitHub & Deployment
- GitHub digunakan untuk source code, version history, branch/PR, CI/test, dan rollback source.
- Credential tidak disimpan di GitHub.
- Production deployment dapat dilakukan dari VPS/CloudPanel dengan repository Git sebagai source.
- Alur standar: development → commit/PR → CI hijau → main → production pull → composer/install/build/migrate/cache → queue restart → health check.
- Production environment diprioritaskan lebih dulu; staging dapat ditambahkan kemudian.

## 32. Security Baseline
- Session Admin/Super Admin: 24 jam.
- 2FA ditunda pada v1 tetapi arsitektur auth harus siap ditambah nanti.
- Turnstile selektif pada register, forgot password, suspicious login, dan form publik rawan abuse.
- Rate limit minimal untuk: login, register, forgot password, nickname, order lookup, payment create, wallet top-up, voucher validation, dan API publik sensitif.
- Security headers, authentication, authorization, CSRF, validation, dan least privilege wajib diterapkan.

## 33. Secret & Integration Credential
- Credential tidak boleh hardcoded di repository.
- Dikelola melalui Super Admin → Integrasi.
- Nilai sensitif disimpan terenkripsi di database.
- Controlled reveal hanya untuk Super Admin dan dicatat di audit log.
- Log wajib menyensor: password, API key, client secret, access token, private key, bearer token, dan payment signature.

## 34. System Health & Observability
- Pantau: Laravel, MySQL, Redis, Queue, Scheduler, disk/storage, Digiflazz, KokinPay, Midtrans, DOKU, dan Resend.
- Status minimal: Healthy, Degraded, Down, Not Configured, Maintenance.
- Setiap request/transaksi penting menggunakan **correlation ID** untuk tracing order, payment, webhook, queue, provider request, callback, dan error.

## 35. Backup & Recovery
- Backup MySQL otomatis setiap hari.
- Simpan di VPS dan external/object storage.
- Retention awal 7 hari.
- Backup yang mengandung data sensitif dienkripsi.
- Restore test dilakukan berkala ke database non-production untuk memastikan dump benar-benar bisa dipakai.
- Super Admin memiliki Export Configuration JSON untuk konfigurasi aman; credential sensitif tidak masuk export biasa.

## 36. Order & Wallet Security
Backend menolak: harga dari frontend, SKU invalid, produk/nominal nonaktif, manipulated provider code, fee palsu, total Rp0 ilegal, voucher tidak sah/expired/over-quota, dan negative amount.
- Backend selalu menghitung ulang transaksi.
- Payment create dan tombol Bayar wajib idempotent.
- Wallet credit/debit, refund, fulfillment, dan callback juga wajib idempotent.

## 37. Callback / Webhook Security
- Verifikasi signature dan source sesuai spesifikasi provider.
- Idempotency protection wajib.
- Callback disimpan sebagai audit/event record.
- Replay berbahaya harus ditolak.
- Final state tidak boleh didowngrade oleh callback lama; contoh: Berhasil tidak boleh kembali menjadi Menunggu Pembayaran.

## 38. Testing Wajib
- Backend unit/feature tests.
- API tests.
- Authentication dan RBAC tests.
- Payment state tests.
- Wallet and ledger tests.
- Voucher concurrency tests.
- Provider/reconciliation tests.
- Queue/job tests.
- Frontend smoke tests customer dan admin.
- **Abuse tests**: manipulasi harga/SKU, Rp0, voucher double-use, fake callback, duplicate webhook, duplicate payment, duplicate fulfillment, saldo negatif, race condition wallet, order ganda, provider timeout, callback terlambat.

## 39. Main Branch Policy
Branch `main` harus selalu deployable. Merge ditolak bila build, automated tests, migration validation, lint/type check, atau critical security checks gagal.

## 40. Milestone Pembangunan

| Milestone | Scope |
|---|---|
| M1 Foundation | Laravel, Vue, Inertia, Tailwind, MySQL, Redis, baseline CloudPanel |
| M2 Database | Schema, migrations, indexes, audit, wallet ledger, provider abstraction, membership |
| M3 Authentication | Customer auth, Super Admin/Admin auth, Google OAuth, forgot password |
| M4 Customer | Profile, guest, wallet, membership, order history, support |
| M5 Product | Category, product, nominal, provider mapping, manual, media |
| M6 Checkout | Input, nickname, voucher, pricing, snapshot, idempotency |
| M7 Payment | Midtrans, DOKU, routing, Manual QRIS, callbacks |
| M8 Fulfillment | Digiflazz, provider jobs, retry/reconciliation, manual fulfillment |
| M9 Admin | Panel, permissions, notifications, integration settings |
| M10 Security | Rate limit, Turnstile, secret encryption, hardening, audit |
| M11 Testing | Functional, API, security, browser, payment, abuse tests |
| M12 Deployment | CloudPanel/VPS production, Cloudflare, queues, scheduler, SSL, backup |
| M13 Acceptance | End-to-end customer/admin/integration/recovery validation |

## 41. Definition of Done
- [ ] Customer desktop selesai dan sesuai target UI.
- [ ] Customer mobile responsive selesai.
- [ ] Register/login/Google/forgot password bekerja.
- [ ] Guest checkout bekerja.
- [ ] Wallet dan membership bekerja.
- [ ] Catalog, nickname checker, voucher, pricing, dan order snapshot bekerja.
- [ ] Midtrans, DOKU, Manual QRIS, Digiflazz, dan manual fulfillment bekerja.
- [ ] Multi-provider abstraction dan reconciliation bekerja.
- [ ] Super Admin/Admin permissions bekerja.
- [ ] Telegram/Discord, email, System Health, dan Audit Log bekerja.
- [ ] Backup otomatis dan restore test berhasil.
- [ ] Queue dan scheduler stabil.
- [ ] Tidak ada critical security issue.
- [ ] Abuse test dan full checkout smoke test lulus.
- [ ] Build production dan seluruh test wajib hijau.

## 42. Non-Negotiable Requirements
1. Jangan mempercayai harga dari frontend.
2. Jangan menyimpan secret di GitHub.
3. Guest tidak boleh memiliki wallet.
4. Nama provider internal tidak ditampilkan ke customer.
5. Jangan melakukan double fulfillment.
6. Jangan failover ketika status provider pertama belum pasti.
7. Redirect payment bukan bukti paid.
8. Harga order tidak boleh berubah setelah dibuat.
9. Android tidak membuat database customer terpisah yang tidak sinkron.
10. Checkout tidak boleh bergantung secara hardcoded pada satu provider.
11. Membership customer BASIC–MAFIA tidak boleh menjadi akses Admin.

## 43. Keputusan yang Dapat Dikonfigurasi di Panel
- Minimum top-up saldo.
- Margin per produk.
- Gateway dan payment channel aktif.
- Routing channel ke Midtrans/DOKU.
- Fee setiap channel.
- Maintenance mode.
- Kategori/produk/nominal aktif.
- Nickname mapping dan game code.
- Voucher rule.
- Webhook Telegram/Discord.
- Membership threshold dan benefit BASIC/SILVER/GOLD/DIAMOND/PLATINUM/MAFIA.
- Logo, favicon, banner, popup, gambar produk dan nominal.

## 44. Prinsip Implementasi
PRD ini adalah single source of truth pembangunan LFAMILIA STORE Laravel v1. Implementasi boleh memperbaiki struktur teknis, performa, keamanan, dan maintainability, tetapi tidak boleh mengubah alur bisnis utama atau scope tanpa keputusan eksplisit. Frontend customer harus mengejar kemiripan tinggi dengan referensi Ourastore.com, sementara backend tetap dirancang mandiri, aman, dan provider-agnostic.

## 45. Homepage Support, Footer & Navigasi Customer
Komponen bagian bawah frontend customer pada referensi LFAMILIA STORE yang sudah ada tidak boleh hilang saat pembangunan ulang. Tampilan boleh dirapikan agar konsisten dengan desain baru yang sangat mirip Ourastore, tetapi seluruh fungsi dan jalur navigasi berikut wajib dipertahankan.

- **Blok bantuan/CTA**: label "BUTUH BANTUAN?", judul "Tim LFAMILIA siap membantu.", deskripsi bantuan produk/pembayaran/status pesanan, dan tombol "Hubungi Kami".
- **Banner/promotional strip** LFAMILIA pada area menjelang footer tetap tersedia sebagai komponen konten yang dapat diganti dari Super Admin → Banner & Konten.
- **Identitas footer**: logo LFAMILIA STORE dan tagline "Top up favoritmu, sat set tanpa ribet.".
- **Tautan sosial/footer**: WhatsApp, Instagram, Email, dan Discord. URL/tujuan tautan harus dapat diedit dari panel; ikon yang tidak dikonfigurasi boleh disembunyikan.
- **Bagian LAYANAN**: Top Up Game, Promo, Cek Transaksi, dan Hubungi Kami. Semua item harus benar-benar menuju route/fitur yang berfungsi dan bukan tombol dummy.
- **Bagian KALKULATOR**: Win Rate, Zodiac, Magic Wheel, dan Semua Alat. Fitur kalkulator yang sudah menjadi scope LFAMILIA tidak boleh hilang ketika frontend dibangun ulang.
- **Bagian INFORMASI**: Pertanyaan umum/FAQ, Syarat & Ketentuan, Kebijakan Pengembalian Dana, dan Kebijakan Privasi.
- **Copyright footer**: "© 2026 LFAMILIA STORE. Produk dan merek dagang adalah milik pemegang hak masing-masing." Tahun sebaiknya dirender dinamis dari aplikasi agar tidak perlu diubah manual setiap tahun.
- **Floating support/chat button** pada sisi kanan bawah tetap tersedia. Tujuan tombol mengikuti kanal support yang dikonfigurasi dari panel dan tidak boleh hardcoded ke nomor/URL tertentu.
- Footer dan blok bantuan harus responsif. Desktop menjadi baseline desain, kemudian disesuaikan untuk mobile tanpa menghilangkan item navigasi.
- Seluruh teks, link, status tampil/sembunyi, dan kanal kontak yang relevan harus dikelola dari panel bila sifatnya konten/configuration; tidak boleh ditanam permanen di source code jika dapat berubah secara operasional.

## 46. Acceptance Criteria — Footer & Support Customer
- [ ] CTA bantuan tampil dan tombol Hubungi Kami berfungsi.
- [ ] Banner bawah tampil benar pada desktop dan mobile tanpa crop/ruang kosong yang tidak diinginkan.
- [ ] Logo, tagline, dan empat kanal sosial mengikuti konfigurasi panel.
- [ ] Semua link Layanan, Kalkulator, dan Informasi membuka halaman/fitur yang benar.
- [ ] Floating support button tidak menutupi konten penting dan bekerja di desktop/mobile.
- [ ] Tidak ada link footer dummy, placeholder, atau route 404.
- [ ] Penggantian logo/banner/link dari panel tercermin di frontend tanpa perubahan source code.
- [ ] Visual footer tetap konsisten dengan tema dark LFAMILIA dan target kemiripan frontend dengan Ourastore.
