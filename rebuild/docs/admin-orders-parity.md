# Menu 2 — Pesanan
Baseline: 45eb340740ef0a72c02eed5fd3933a8bb7d5c176 (panel lama).

| Fitur lama | Pemulihan dan penggabungan |
|---|---|
| Ringkasan enam status | Total, menunggu pembayaran, diproses, berhasil, gagal, perlu perhatian; mengikuti filter aktif |
| Pencarian pelanggan, telepon, produk, tujuan | Pencarian nomor, kontak, katalog, data tujuan, serta nama pada catatan pembelian |
| Filter status, penyedia, pembayaran, tanggal | Filter server; tanggal WIB; metode saldo akun dan pembayaran admin ditambahkan |
| Jumlah baris / halaman | 10, 25, 50, 100 dan pagination server |
| Pembaruan 30 detik | Otomatis, tombol muat ulang, waktu pembaruan; berhenti saat tab tersembunyi, filter diubah, baris dipilih, atau formulir dibuka |
| Unduh CSV | Hasil filter seluruh halaman atau pilihan halaman; BOM UTF-8 dan perlindungan rumus spreadsheet |
| Pilihan beberapa pesanan | Unduh pilihan; tidak menampilkan tindakan status massal yang hanya berupa label di panel lama |
| Catat pesanan manual | Pelanggan, telepon, email, produk, paket, tujuan, total, catatan; wajib memastikan pembayaran diterima; kunci permintaan persisten mencegah duplikat |
| Detail pesanan dan riwayat | Nama produk/paket pada saat pembelian, kontak tamu, label tujuan, pembayaran, penanganan, hasil pengiriman, riwayat; seluruh label status berbahasa Indonesia |
| Salin nomor | Di daftar dan detail |
| Hubungi WhatsApp | Tautan langsung hanya jika nomor pelanggan tersedia; pesan awal memakai nomor pesanan |
| Kirim ulang voucher / hasil | Hanya pesanan berhasil yang sudah memiliki kode/hasil tersimpan dan email valid; mengantrekan email ulang tanpa memproses provider lagi |
| Periksa pembayaran | Pemeriksaan Midtrans memakai klien dan validasi yang sama dengan callback; nominal dan nomor wajib cocok. Doku mengikuti callback terverifikasi, QRIS manual memakai konfirmasi yang sudah ada |
| Periksa / coba lagi pengiriman | Pemeriksaan memakai antrean dan referensi yang sudah ada; pengiriman ulang memakai FulfillmentService dan hanya keadaan aman |
| Selesaikan manual | Memakai tindakan Manual yang sudah ada; kode/catatan, konfirmasi, atau alasan gagal |
| Aktivitas terbaru | Sepuluh kejadian terbaru; tautan menuju detail |
| Tampilan ponsel | Kartu pesanan, filter satu kolom, formulir samping yang dapat digulir |

Tidak menggandakan layanan pembayaran atau penanganan. Tombol retry Digiflazz lama tidak didukung oleh endpoint PATCH lama; diganti dengan pemeriksaan hasil / lanjutkan pengiriman yang mengikuti proteksi server saat ini. Jumlah produk tidak dikembalikan karena checkout satu item merupakan perubahan produk yang telah disetujui.

Referensi katalog pesanan khusus bersifat tidak aktif dan tidak ditawarkan di toko. Nama sebenarnya disimpan dalam catatan pembelian. Pencatatan manual bukan transaksi daring dan tidak menagih pelanggan. Pengiriman ulang kode/hasil hanya mengirim ulang data delivery yang sudah tersimpan; tidak membuat fulfillment attempt baru dan tidak memanggil provider. Akses membuat pesanan memerlukan hak Pesanan, Pembayaran, dan Penanganan. Akses operasional lainnya mengikuti hak yang sudah ada.
