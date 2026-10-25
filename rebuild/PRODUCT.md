# Product, catalog & media

> Aturan produk ada di [`docs/PRD.md`](../docs/PRD.md). Dokumen ini menjelaskan **cara kode bekerja**, bukan aturan produk. Kalau berbeda, PRD yang menang.

## Model katalog

Struktur bisnis:

`Category → Product → ProductPackage/nominal → Provider Mapping`

Public catalog hanya menawarkan entity yang aktif dan eligible. Field customer per product bersifat data-driven.

Produk menggunakan fulfillment mode otomatis atau manual. Manual product tidak dibatasi pada Roblox.

## Provider mapping

Tabel dapat menyimpan provider mappings historis, tetapi setiap nominal Digiflazz baru hanya boleh memiliki **satu SKU terpilih** tanpa cadangan atau perpindahan otomatis. Mapping menyimpan provider, external SKU, cost, max price, prioritas, status aktif, dan konfigurasi fulfillment.

Untuk selection, angka `priority` lebih kecil didahulukan. Provider/mapping internal, cost, external SKU, dan ranking tidak menjadi input customer.

Digiflazz SKU masuk melalui trusted catalog/import/sync flow. Customer tidak dapat menentukan `buyer_sku_code`.

## Pricing

Harga jual katalog dihitung dari **modal toko**, bukan langsung dari harga seller provider:

- **modal toko** = `provider_mappings.store_cost_idr` — dibentuk sekali saat impor/bootstrap dengan menambahkan **tambahan modal** (uplift, bawaan 2%) di atas harga seller, lalu boleh diedit admin. Sync provider memperbarui harga seller sebagai referensi (`seller_reference_price_idr`) tanpa menimpa modal toko dan tanpa menerapkan ulang uplift.
- **harga jual** = modal toko × **markup jual** yang berlaku (bawaan 10%). Markup berlaku di tingkat **nominal** (mode persen; admin dapat menggantinya menjadi harga tetap per nominal). Produk/game **tidak punya markup sendiri** — markup tingkat produk dihapus (PR #302; keputusan pemilik 2026-10-24). Harga rupiah dibulatkan ke atas, tanpa pembulatan "cantik".

Nominal manual memakai modal toko yang diketik admin apa adanya (tanpa uplift) dengan markup yang sama.

Server menolak harga jual di bawah modal. Nominal dengan modal toko akan **sementara tidak tersedia** bila harga seller provider naik di atas modal toko tersebut, dan tersedia kembali otomatis ketika harganya turun ke dalam batas. Checkout membuat snapshot sehingga perubahan katalog, cost, margin, atau provider berikutnya tidak menulis ulang transaksi lama.

## Media

Spatie Media Library dipakai untuk image collections produk/kategori/package dan store assets seperti logo, favicon, banner desktop/mobile, popup, serta asset yang memang didukung model.

Storage disk adalah konfigurasi runtime. Repository tidak boleh menyimpan production media dump atau credential object storage.

## Admin ownership

Produk/kategori/nominal, urutan, media, provider mappings, Digiflazz catalog/sync, dan pricing dikelola melalui workspace Admin yang sesuai. Secret provider tetap berada di Integrasi, bukan di Produk.

Untuk checkout dan safe fulfillment lihat [CHECKOUT.md](CHECKOUT.md) dan [FULFILLMENT.md](FULFILLMENT.md).
