# Product, catalog & media

## Model katalog

Struktur bisnis:

`Category → Product → ProductPackage/nominal → Provider Mapping`

Public catalog hanya menawarkan entity yang aktif dan eligible. Field customer per product bersifat data-driven.

Produk menggunakan fulfillment mode otomatis atau manual. Manual product tidak dibatasi pada Roblox.

## Provider mapping

Satu package dapat mempunyai beberapa provider mappings. Mapping menyimpan antara lain provider, external SKU, cost, max price, priority, active state, dan fulfillment configuration.

Untuk selection, angka `priority` lebih kecil didahulukan. Provider/mapping internal, cost, external SKU, dan ranking tidak menjadi input customer.

Digiflazz SKU masuk melalui trusted catalog/import/sync flow. Customer tidak dapat menentukan `buyer_sku_code`.

## Pricing

Server menghitung cost + margin sesuai pricing mode dan menolak sell price di bawah modal. Checkout membuat snapshot sehingga perubahan katalog, cost, margin, atau provider berikutnya tidak menulis ulang transaksi lama.

## Media

Spatie Media Library dipakai untuk image collections produk/kategori/package dan store assets seperti logo, favicon, banner desktop/mobile, popup, serta asset yang memang didukung model.

Storage disk adalah konfigurasi runtime. Repository tidak boleh menyimpan production media dump atau credential object storage.

## Admin ownership

Produk/kategori/nominal, urutan, media, provider mappings, Digiflazz catalog/sync, dan pricing dikelola melalui workspace Admin yang sesuai. Secret provider tetap berada di Integrasi, bukan di Produk.

Untuk checkout dan safe fulfillment lihat [CHECKOUT.md](CHECKOUT.md) dan [FULFILLMENT.md](FULFILLMENT.md).
