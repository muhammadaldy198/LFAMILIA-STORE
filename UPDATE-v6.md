# Pembaruan LFAMILIA STORE v6

## Urutan pemasangan

1. Unggah source versi ini ke repositori/deployment yang digunakan.
2. Jalankan migrasi D1 `drizzle/0003_final_storefront.sql` satu kali.
3. Deploy ulang Worker/Pages.
4. Buka `/admin` sebagai Pemilik, lalu pilih **Produk → Lengkapi katalog utama**.
5. Atur logo, banner, kontak, kategori, FAQ, voucher, flash sale, dan informasi pop-up melalui panel admin.

## Akses admin

- **Pemilik**: seluruh fitur, termasuk harga, provider, promosi, keuangan, integrasi, dan pengelolaan tim.
- **Staff**: pesanan, pemenuhan manual, informasi produk, gambar, jam operasional, pop-up, serta konten toko.
- Tambahkan email admin pada menu **Tim Admin**, lalu izinkan email yang sama pada kebijakan Cloudflare Access untuk `/admin*` dan `/api/admin/*`.

## Pop-up produk

- Berlaku untuk produk otomatis dan manual.
- Bisa memiliki beberapa slide, diaktifkan/nonaktifkan, dan diurutkan dari panel admin.
- Produk manual dapat memakai token `{{jam_buka}}`, `{{jam_tutup}}`, dan `{{zona_waktu}}`.
- Pengunjung dapat menyembunyikan informasi selama 7 hari. Perubahan isi dari admin akan membuat informasi terbaru muncul kembali.
