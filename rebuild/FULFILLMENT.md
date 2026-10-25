# Fulfillment & reconciliation

> Aturan produk ada di [`docs/PRD.md`](../docs/PRD.md). Dokumen ini menjelaskan **cara kode bekerja**, bukan aturan produk. Kalau berbeda, PRD yang menang.

Fulfillment dimulai setelah order berada pada state yang diizinkan oleh payment flow. Service membuat `fulfillment_attempts` dan mencegah order yang sama dikirim berulang tanpa state transition yang aman.

## Provider mappings

Tabel `provider_mappings` dapat tetap menyimpan baris historis, tetapi untuk Digiflazz yang dijual sekarang **setiap nominal hanya memakai satu SKU terpilih**, tanpa SKU cadangan (`_B`) maupun pergantian otomatis ke SKU lain (PRD v2.1 §4).

Jika ada multiple mapping terkonfigurasi, candidate diurutkan:

1. `priority` kecil lebih dahulu;
2. cost lebih kecil;
3. mapping ID.

Customer tetap membeli satu nominal. Provider mapping dan SKU internal tidak menjadi pilihan customer.

## Digiflazz max_price snapshot

Saat checkout, `provider.max_price_idr` untuk Digiflazz = **harga modal toko SKU terpilih**, persis tanpa tambahan. Tidak ada perhitungan harga tertinggi SKU lain atau markup 1%. Harga seller yang melampaui modal toko membuat nominal sementara tidak tersedia sampai harga seller turun kembali. Snapshot `max_price` disimpan bersama order dan dikirim ke Digiflazz pada attempt maupun reconciliation.

Harga jual dihitung dari modal toko SKU terpilih. Kenaikan harga seller tidak otomatis menaikkan modal atau mengubah pesanan yang sudah dibuat.

Jangan membingungkan `provider_mappings.max_price_idr` (batas administratif per sumber saat sinkron) dengan `order.snapshot.provider.max_price_idr` (batas transaksi Digiflazz hasil perhitungan saat checkout).

## Unsent mapping skip

Sebelum provider request, mapping dapat dinyatakan unusable bila misalnya:

- mapping/provider inactive;
- adapter tidak didukung;
- external SKU kosong;
- Digiflazz catalog item inactive, stok habis, atau cut-off;
- voucher-stock key/stok tidak tersedia;
- provider cost invalid;
- cost melewati `max_price` snapshot order.

Mapping terpilih yang tidak dapat digunakan berubah menjadi attempt **BLOCKED** tanpa request Digiflazz. Tidak ada pemilihan SKU lain secara otomatis, sekalipun provider belum dihubungi.

## Attempt state

State implementasi mencakup antara lain:

- `CREATED`
- `SENDING`
- `PENDING`
- `UNKNOWN`
- `SUCCESS`
- `FAILED_CONFIRMED`
- state manual/blocked yang relevan

`PENDING`, `SENDING`, atau `UNKNOWN` tidak boleh langsung membuat transaksi provider kedua.

## Reconciliation

Attempt uncertain direconcile menggunakan request/reference attempt yang sama. Hasil provider diverifikasi terhadap `ref_id`, `buyer_sku_code`, dan `customer_no` yang tersimpan.

Status provider sukses menjadi `SUCCESS`; kegagalan definitive menjadi `FAILED_CONFIRMED`; pending tetap `PENDING`; hasil yang tidak dapat dipastikan menjadi `UNKNOWN`.

## Penanganan definitive failure dan kebijakan no auto-switch

Sesuai keputusan PRD v1.1 §47, **automatic failover / auto-switch dinonaktifkan**. Toko tidak menggunakan SKU cadangan otomatis (`_B`), sehingga kegagalan pengiriman tidak otomatis memicu provider/mapping kedua.

Ketika fulfillment attempt mencapai kegagalan pasti (`FAILED_CONFIRMED`), pesanan tidak otomatis dialihkan ke sumber lain, melainkan ditandai `BLOCKED` untuk penanganan manual oleh Admin/CS.

**Catatan teknis (F-04):** Failover otomatis dinonaktifkan. `retrySafeAttempt()` menolak attempt legacy dengan status `FAILED_CONFIRMED` dan `safe_to_failover=true` melalui `return null`, sehingga tidak dikirim ulang. Metode yatim `failoverIfSafe()` dihapus pada PR ini; kabel pemanggilnya telah dicabut pada PR #286.

## Double fulfillment prevention

Proteksi yang ditemukan:

- existing attempt diperiksa sebelum start;
- attempt/order di-lock dalam transaction;
- external reference unik;
- attempt terbaru/state terminal diperiksa;
- reconciliation tidak membuat reference baru;
- mapping yang sudah attempted tidak dipilih ulang;
- success/confirmed-failure tidak ditimpa sembarang callback/result lama;
- order events dan attempt record menyediakan audit trail.

## Scheduler/queue

Scheduler mengantrikan attempt baru dan reconciliation untuk attempt stale/uncertain sesuai implementasi command/job. Production queue worker dan scheduler dijalankan sebagai systemd service dan diverifikasi di deployment healthcheck.
