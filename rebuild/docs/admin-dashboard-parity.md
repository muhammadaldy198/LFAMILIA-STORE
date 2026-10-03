> **HISTORICAL SNAPSHOT:** Dokumen ini merekam audit/restoration pada tanggal pembuatannya. Gunakan `rebuild/ADMIN.md`, `rebuild/ARCHITECTURE.md`, dan kode `main` sebagai sumber status current. Jangan menganggap gap/label milestone di snapshot ini masih berlaku tanpa verifikasi ulang.

> **HISTORICAL SNAPSHOT:** Dokumen ini merekam audit/restoration pada tanggal pembuatannya. Gunakan `rebuild/ADMIN.md`, `rebuild/ARCHITECTURE.md`, dan kode `main` sebagai sumber status current. Jangan menganggap gap/label milestone di snapshot ini masih berlaku tanpa verifikasi ulang.

# Menu 1 — Dashboard

Baseline: 45eb340740ef0a72c02eed5fd3933a8bb7d5c176, components/admin-overview.tsx dan app/api/admin/summary/route.ts.

| Bagian lama | Restorasi |
|---|---|
| Judul sesuai role | Panel Super Admin / Admin; hanya dua role sesuai PRD terbaru |
| Tanggal dan jam | WIB, update setiap menit |
| Omzet hari ini | Hanya transaksi dibayar, batas hari WIB |
| Pesanan hari ini | Batas hari WIB, tidak memakai tanggal UTC |
| Produk aktif | Jumlah status produk aktif |
| Saldo Digiflazz | Probe cek-saldo read-only, cache 60 detik, timeout 2 detik; gagal tidak menampilkan saldo palsu Rp0 |
| Pembayaran Berhasil | Mempertahankan penghitung pesanan fulfillment SUCCESS lama untuk periode dipilih; keterangan memperjelas makna |
| Grafik penjualan | today/7d/30d/90d, omzet dan jumlah pesanan, hari kosong diisi nol, data tabel dapat dibuka |
| Aktivitas terbaru | Audit tanpa before/after/secret untuk pemilik; fallback pesanan untuk Admin |
| Status integrasi | Status tes terakhir, konfigurasi belum dites tidak disebut online; tes lebih dari 15 menit ditandai perlu tes ulang |
| Sinkron terakhir | Timestamp snapshot Digiflazz aktual |
| Webhook | Kesiapan URL aplikasi HTTPS, bukan klaim callback live sudah dites |
| Pesanan terbaru | Invoice, pelanggan, snapshot produk/nominal, channel/status pembayaran, total Super Admin, status pesanan |
| Produk terlaris | Urutan SUCCESS terbanyak, lalu jumlah pesanan; 5 produk dalam periode |
| Lihat semua | Link aktif ke audit/orders/catalog/integrations, sesuai hak akses |
| Notifikasi | Tetap tersedia sebagai tambahan saat ini; tandai dibaca per admin |
| Tambahan Laravel | Saldo wallet, tiket terbuka, pending fulfillment tetap dipertahankan |

shadcn-vue Card/Button/Badge/Table/DropdownMenu digunakan hanya di admin. Layout responsif; tabel dan grafik panjang menggulir di dalam panel. Admin tanpa permission terkait tidak memperoleh dataset melalui props. Nilai finansial hanya Super Admin.

Saldo provider dan status koneksi live tidak dapat dibuktikan sebelum credential diisi. Probe saldo tidak membuat pesanan atau mengirim notifikasi eksternal.
