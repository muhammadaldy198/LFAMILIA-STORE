# Genshin Impact — konfigurasi contoh server

Contoh untuk pengisian panel **Data Pelanggan**; dokumen ini **tidak** mengubah produk atau seller produksi.

- Field pertama: `user_id`, tipe `text`, wajib.
- Field kedua: `server`, tipe `select` / **Pilihan**, wajib.
- Opsi field `server` (format panel `nilai|Label`):

```text
os_asia|Asia
os_usa|America
os_euro|Europe
os_cht|TW, HK, MO
```

- Format tujuan produk: `{{user_id}}|{{server}}`.
- Contoh: UID `123456789` + pilihan **Asia** menghasilkan `123456789|os_asia` pada `customer_no` Digiflazz.

Kode opsi, bukan label, yang disimpan di pesanan. Jangan menambah penerjemah label atau mengubah `FulfillmentTargetBuilder`.

**Batas seller (berdasarkan keputusan pemilik 6 November 2026):** konfigurasi ini hanya disepakati untuk PT KUOTA PULSA INTERNUSA, JOSJIS DIAMOND, Syandanapay, dan TOPUPKUY GG. Pemilihan seller tetap di panel/DigiTools; jangan aktifkan SKU seller lain tanpa verifikasi format.

Jenis `password` hanya dipakai untuk produk yang memang menggunakan akses via-login. Password disimpan terpisah dalam nilai terenkripsi dan tidak boleh dikirim ke fitur cek nickname, riwayat publik, email, bot, atau log. Placeholder `{{password}}` di format tujuan hanya dipakai jika provider produk tersebut mewajibkannya.
