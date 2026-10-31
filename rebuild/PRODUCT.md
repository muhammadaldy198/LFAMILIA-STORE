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

Untuk setiap nominal **Digiflazz**: harga jual = harga seller SKU tersinkron + markup nominal. Default setiap nominal baru hasil impor adalah **10%**; admin dapat memilih markup persen atau tambahan tetap **dalam rupiah**, bukan harga jual tetap. Harga seller tidak diedit dari panel, dan tidak ada tambahan modal toko sebagai lapisan perhitungan Digiflazz.

Harga seller dan SKU diperbarui melalui price-list Digiflazz setiap 15 menit. Sinkronisasi **tidak pernah mengganti nama produk atau nama nominal yang sudah ditata toko**, tidak menimpa markup masing-masing nominal, dan tidak memilih SKU cadangan. Setiap order menyimpan snapshot harga. Mapping cost lama tetap tersedia untuk kebutuhan kompatibilitas/fulfillment saja.

Produk manual dan stok voucher internal tetap boleh mencatat modal layanan tersendiri. Lihat [kebijakan keputusan 31 Oktober 2026](docs/digiflazz-pricing-2026-10-31.md).

## Media

Spatie Media Library dipakai untuk image collections produk/kategori/package dan store assets seperti logo, favicon, banner desktop/mobile, popup, serta asset yang memang didukung model.

Storage disk adalah konfigurasi runtime. Repository tidak boleh menyimpan production media dump atau credential object storage.

## Admin ownership

Produk/kategori/nominal, urutan, media, provider mappings, Digiflazz catalog/sync, dan pricing dikelola melalui workspace Admin yang sesuai. Secret provider tetap berada di Integrasi, bukan di Produk.

Untuk checkout dan safe fulfillment lihat [CHECKOUT.md](CHECKOUT.md) dan [FULFILLMENT.md](FULFILLMENT.md).
