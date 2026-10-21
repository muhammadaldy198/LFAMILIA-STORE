# Catatan QA pemilik — 21 Oktober 2026

Daftar temuan dari pemilik toko saat memeriksa langsung tampilan produksi.
Dikerjakan bertahap; pemilik masih mencari temuan lain.

Status: **terbuka** (belum di-push, belum di-deploy) kecuali ditandai selesai.

| # | Halaman | Temuan | Status |
|---|---------|--------|--------|
| 1 | Checkout (`/catalog/*`), bagian 5 Detail Kontak, layar HP | Kolom input WhatsApp tidak simetris dengan kolom Email karena tombol `+62` menempel di kiri input. Permintaan: tombol negara pindah ke baris sendiri di atas input, supaya kedua kolom rata kiri. | Selesai di lokal — branch `fix/checkout-contact-polish`, commit `8e7617ad` |
| 2 | Checkout, bagian 5 Detail Kontak, layar HP | Placeholder nomor WhatsApp masih `081234567890` padahal kode negara `+62` sudah dipisah. Permintaan: hilangkan angka `0` di depan. | Selesai di lokal — branch `fix/checkout-contact-polish`, commit `8e7617ad` |

## Cara kerja

- Semua perbaikan dikumpulkan dulu di branch `fix/checkout-contact-polish` (berbasis `main` @ `d5739989`).
- Belum di-push, belum dibuat PR, belum di-deploy. Menunggu pemilik selesai memeriksa.
- Setiap temuan baru ditambahkan ke tabel di atas sebelum dikerjakan, supaya tidak ada yang terlewat.
