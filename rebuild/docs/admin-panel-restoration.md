> **HISTORICAL SNAPSHOT:** Dokumen ini merekam audit/restoration pada tanggal pembuatannya. Gunakan `rebuild/ADMIN.md`, `rebuild/ARCHITECTURE.md`, dan kode `main` sebagai sumber status current. Jangan menganggap gap/label milestone di snapshot ini masih berlaku tanpa verifikasi ulang.

# Admin panel restoration — 2026-10-02

The Laravel/Vue admin uses shadcn-vue controls and an accessible Sheet on mobile. Customer-facing controls and design remain separate.

| Menu | Available operations |
| --- | --- |
| Dashboard | Metrics, recent orders, notifications, global search |
| Pesanan | Invoice search, status filter, pagination, detail, payment history, fulfillment history |
| Produk | Categories, product editor, images/banner/notices, customer fields with preview, nominal table and editor, provider SKU import, individual sync, product/global margin, per-nominal percent/fixed/final price, drag order and mobile order buttons |
| Manual | Antrean proses terbaru per pesanan, metrik, pencarian, filter, pagination, penanganan manual, hasil/kegagalan, pemeriksaan status, dan retry/failover aman |
| Banner & Konten | Banner desktop/mobile, satu pop-up, logo & gambar storefront, blok bantuan/footer, news, moderasi reviews, FAQ, dan halaman kebijakan; kontak toko tetap di Pengaturan agar tidak ganda |
| Digiflazz | Dedicated operational monitor, connection state, owner-only balance, normal/warning/critical summary, search/category/product/brand/health filters, mapped/all scope, stock, buyer/seller status, cut-off, multi, baseline, recent transactions, per-SKU/all sync, configurable auto-sync interval and warning thresholds |
| Validasi Akun | Game codes, game nickname, MLBB region, PLN checks |
| Provider | Daftar provider dengan nama/keterangan/urutan/status yang dapat diubah, ringkasan kesehatan dan transaksi, serta inventaris mapping nominal tanpa menggandakan editor SKU/harga/margin di menu Produk |
| Pembayaran | Metode pembayaran editable (nama/deskripsi/logo/biaya/urutan), gateway & maintenance, routing pesanan/top up khusus Super Admin, QRIS manual & konfirmasi, editor halaman pembayaran, transaksi dengan filter/pagination, callback URL, serta master toggle dan minimum top up saldo |
| Pelanggan | Ringkasan pelanggan, search/filter/pagination, detail profil & autentikasi, membership otomatis/manual, Super Admin balance adjustment, order/ledger/top-up/ticket/saved-game history, serta safe empty-account cleanup |
| Promo | Dedicated voucher workspace: nama/deskripsi, nominal/persen, maksimum diskon, minimum transaksi, kuota & limit pelanggan, jadwal, scope kategori/produk, search/filter/pagination, usage/reservation summary, safe delete, serta prioritas Populer Sekarang |
| Layanan Pelanggan | Dedicated support workspace: ringkasan status/sumber, search/filter/pagination, kategori tiket, detail percakapan, keterkaitan pesanan/pelanggan, penangan terakhir, status/reply, editable quick replies, email reply, dan notifikasi ulang saat pelanggan membalas |
| Laporan | Dedicated shadcn report workspace: preset/custom period, operational metrics, daily sales chart, order/payment/fulfillment status, top products/categories, provider performance/error rate, role-safe CSV export, and Super Admin-only finance metrics |
| Admin & Akses | Dedicated shadcn workspace untuk Super Admin/Admin: search/filter/pagination, ringkasan role/status, tambah/edit/hapus akun, password/status, permission granular, last-owner/self-lockout protection, serta aktivitas Admin terbaru |
| Pengaturan | Dedicated shadcn workspace: identitas toko, kontak & jam layanan, identitas merchant publik, membership tier dengan field non-JSON, dan safe configuration export khusus Super Admin |
| Integrasi | Dedicated shadcn workspace khusus Super Admin: 9 profil integrasi, status tersimpan, kelengkapan field wajib, callback/redirect URL, kredensial terenkripsi, tampilkan kredensial dengan verifikasi password, Tes Koneksi, validasi input operasional, dan preservasi metadata lama |
| System Health | Dedicated shadcn workspace khusus Super Admin: status aplikasi/MySQL/Redis/storage, heartbeat worker & scheduler, failed jobs, rekonsiliasi fulfillment, 9 integrasi, gateway maintenance, dan runtime non-secret tanpa menjalankan transaksi/provider probe |
| Audit Log | Dedicated shadcn workspace khusus Super Admin: ringkasan, search/filter server-side, filter Admin/peran/aksi/target/tanggal, pagination, actor identity, before/after detail, correlation ID, IP/user-agent, serta read-time secret redaction tanpa edit/delete log |

## Important behavior

- Imported nominal and mapping stay inactive until reviewed.
- Sync takes SKU and modal from provider response, never from customer input. Max price equals synced modal.
- Existing order snapshots are not rewritten by pricing or synchronization changes.
- Menu Manual hanya mengizinkan tindakan pada proses terbaru setiap pesanan; proses lama ditolak untuk mencegah double fulfillment.
- Pemeriksaan status transaksi tidak pasti memakai referensi proses yang sama dan tidak membuat transaksi baru.
- Buyer/seller inactive, finite stock zero, missing SKU after full sync, and cut-off prevent checkout.
- Fixed sell price below modal is rejected and is blocked at checkout if modal later rises.
- Input keys used by nickname or delivery templates cannot be removed until their references are changed.
- Provider responses expose buyer catalog data; seller rating/SLA and marketplace seller selection are not provided by this endpoint and are not fabricated.
- Activation links are time-limited and single-use; the owner selects a password. No public self-registration grants admin privileges.
- Customer memilih metode pembayaran, bukan gateway internal. Routing metode ke Midtrans/DOKU dikontrol Super Admin; nama gateway tidak diekspos ke customer.
- Routing pesanan dan top up saldo dipisahkan agar satu metode dapat memakai gateway berbeda sesuai tujuan tanpa pilihan gateway di frontend.
- Top up saldo memiliki master toggle; ketika OFF, daftar metode top up disembunyikan dan backend menolak quote/create.
- Kredensial rahasia tetap hanya disimpan terenkripsi di menu Integrasi dan ditolak dari konfigurasi routing. Halaman normal hanya menerima status tersimpan, bukan nilainya; penampilan kredensial memerlukan password Super Admin dan dicatat ke Audit Log.
- Menyimpan profil Integrasi mempertahankan metadata lama yang belum dikenali agar migrasi tidak menghapus konfigurasi provider sebelumnya. Field wajib, URL HTTPS, path endpoint, daftar email, dan hostname divalidasi server-side.
- Menu Pelanggan memisahkan customer membership dari Admin RBAC; tier customer tetap BASIC → SILVER → GOLD → DIAMOND → PLATINUM → MAFIA sesuai konfigurasi membership aktif.
- Penyesuaian saldo pelanggan hanya Super Admin, memakai wallet ledger, row lock, idempotency key, audit log, dan tidak boleh membuat saldo negatif.
- Data sensitif akun game tersimpan tidak dibuka dari daftar/detail pelanggan; panel hanya menampilkan metadata operasional yang diperlukan.
- Penghapusan/pembersihan customer hanya berlaku untuk akun kosong; akun dengan saldo, ledger, order, top up, atau tiket dipertahankan.
- Flash Sale tidak dipulihkan: keputusan storefront terbaru menggantinya dengan Populer Sekarang. Menu Promo menjadi satu sumber pengaturan prioritas populer agar tidak ganda dengan Produk.
- Perubahan voucher mengunci row voucher; total kuota tidak boleh diturunkan di bawah pemakaian + reservasi aktif. Voucher ber-riwayat tidak dapat dihapus, hanya dinonaktifkan.
- Maksimum diskon voucher dihitung server-side dan ikut masuk snapshot order sehingga perubahan promo berikutnya tidak menulis ulang harga order yang sudah dibuat.
- Menu Layanan Pelanggan mempertahankan conversation history sebagai sumber balasan; field `staff_reply` lama tidak diduplikasi. Metadata lama `kind` dan `handled_by/handled_at` dipulihkan sebagai kategori tiket dan admin penangan terakhir.
- Balasan customer maupun guest membuka kembali tiket menjadi OPEN dan menghasilkan Admin Notification agar percakapan lanjutan tidak terlewat. Tiket CLOSED tetap tidak dapat dibalas customer/guest.
- Status lama `rejected` tidak dipertahankan sebagai state terpisah; lifecycle Laravel menggunakan OPEN → IN_PROGRESS → RESOLVED/CLOSED.
- Menu Laporan menjaga pemisahan akses finansial: Admin dengan `reports.view` hanya menerima metrik operasional, sedangkan omzet, laba kotor, diskon, biaya pembayaran, top up, dan saldo pelanggan hanya dikirim ke Super Admin.
- Export CSV mengikuti pemisahan akses yang sama dan dicatat ke Audit Log. Laba kotor laporan menggunakan order snapshot (`total - fee - cost`, minimum nol), sehingga perubahan harga/provider berikutnya tidak mengubah histori order.
- Menu Admin & Akses hanya menerima role `SUPER_ADMIN` dan `ADMIN` sesuai keputusan v1 terbaru; `STAFF` lama tidak dihidupkan kembali. Admin biasa memakai permission granular yang diperiksa server-side dan selalu mempertahankan Dashboard.
- Akun yang sedang digunakan tidak dapat dinonaktifkan, diturunkan, atau dihapus dari sesi yang sama. Super Admin aktif terakhir tetap dilindungi. Tambah/edit/hapus akun dicatat di Audit Log tanpa menyimpan password mentah.
- Menu Pengaturan menjadi sumber tunggal untuk nama toko, tagline, kontak, tautan bantuan, jam layanan, dan identitas merchant. Logo/banner/footer/widget bantuan tetap di Banner & Konten agar tidak ada editor ganda.
- Membership di Pengaturan menggunakan field minimum transaksi, diskon persen, manfaat tambahan, dan status aktif; metadata lama yang tidak dikenali tetap dipertahankan saat penyimpanan. Endpoint JSON lama tetap kompatibel untuk migrasi/test, tetapi tidak ditampilkan ke Admin.
- Ekspor konfigurasi hanya Super Admin, mengecualikan credential/password/secret/token/key/signature/ciphertext, dan setiap unduhan tercatat di Audit Log.
- System Health hanya membaca state internal dan hasil Tes Koneksi terakhir; halaman ini tidak menembak provider atau gateway secara otomatis. Heartbeat rusak/kedaluwarsa ditandai perlu diperiksa, failed job hanya ditampilkan sebagai jumlah, dan payload/exception job tidak dikirim ke UI.
- Live payment/provider transactions require configured integrations and a separate live test. HTTP fakes in regression tests do not prove external service health.

## Verification

Feature regressions use a separate MySQL database. Browser verification covers 360, 390, 768, and 1440 widths, all 18 menus, populated catalog/order/customer views, mobile Sheet, activation-to-login-to-panel, current XSRF handling after activation, quick replies, and persisted nominal/customer-field changes. No test fixture credentials belong in production.
