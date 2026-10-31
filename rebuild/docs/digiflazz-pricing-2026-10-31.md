# Kebijakan harga Digiflazz LFAMILIA STORE — 31 Oktober 2026

Keputusan pemilik (menggantikan aturan modal toko / harga jual tetap untuk Digiflazz):

- DigiTools adalah mesin terpisah untuk pengelolaan seller dan pemilihan SKU. LFAMILIA STORE tidak menjalankan pemilihan seller atau SKU cadangan otomatis.
- LFAMILIA STORE mengambil price list resmi Digiflazz langsung setiap **15 menit** memakai integrasi Digiflazz toko. Data dari website member berbasis cookie DigiTools bukan sumber login toko.
- Harga dasar satu nominal Digiflazz adalah **harga seller** terkini dari SKU yang terhubung. Tidak ada penambahan harga modal toko untuk menghitung harga jual, dan tidak ada harga jual tetap.
- Setiap **nominal** memiliki aturan tambahan harga sendiri: **PERCENT** (harga seller + persentase; bawaan impor 10%) atau **FIXED** (harga seller + tambahan rupiah). Tidak ada markup tingkat produk. Ini adalah markup atas harga seller, bukan target margin net.
- Pengaturan tambahan harga per nominal tidak pernah ditimpa sinkronisasi. Perubahan harga seller otomatis mengubah harga jual katalog dan quote checkout berikutnya. Order yang sudah dibuat tetap menyimpan snapshot.
- Nama **produk** dan nama **nominal** yang telah ditata toko adalah milik admin. Impor/sinkronisasi Digiflazz hanya boleh menghubungkan SKU dan memperbarui data seller, tidak boleh mengganti nama produk/nominal yang sudah ada.
- Satu nominal memakai satu SKU Digiflazz terpilih, tidak ada SKU fallback. Jika identitas SKU berubah/mencurigakan, jangan diam-diam memilih seller/SKU lain.
- Kolom `cost_idr`, `store_cost_idr` dan `max_price_idr` lama dipertahankan untuk kompatibilitas, audit, dan fulfillment; untuk mapping Digiflazz nilainya mengikuti harga seller, bukan pengaturan modal independen.
- Jika sinkron gagal, harga terakhir tetap tersimpan; jangan reset ke Rp0. Ketersediaan katalog tetap diperiksa oleh backend.
- Produk manual dan stok voucher lokal **tidak** terikat perubahan rumus khusus Digiflazz.
