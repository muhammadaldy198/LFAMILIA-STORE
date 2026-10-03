# Fulfillment & reconciliation

Fulfillment dimulai setelah order berada pada state yang diizinkan oleh payment flow. Service membuat `fulfillment_attempts` dan mencegah order yang sama dikirim berulang tanpa state transition yang aman.

## Provider mappings

Satu `ProductPackage` dapat mempunyai beberapa `provider_mappings`. Untuk Digiflazz, mapping dapat mempunyai `buyer_sku_code`/external SKU berbeda.

Candidate berikutnya diurutkan:

1. `priority` kecil lebih dahulu;
2. cost lebih kecil;
3. mapping ID.

Customer tetap membeli satu nominal. Provider mapping dan SKU internal tidak menjadi pilihan customer.

## Unsent mapping skip

Sebelum provider request, mapping dapat dinyatakan unusable bila misalnya:

- mapping/provider inactive;
- adapter tidak didukung;
- external SKU kosong;
- Digiflazz catalog item inactive, stok habis, atau cut-off;
- voucher-stock key/stok tidak tersedia;
- provider cost invalid;
- cost melewati `max_price` snapshot order.

Mapping seperti ini dapat dilewati **sebelum external request**, karena belum ada risiko provider pertama sudah memproses transaksi.

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

## Definitive-failure-only failover

Source berikutnya hanya boleh dipilih ketika attempt sebelumnya sudah aman untuk failover, termasuk kegagalan definitive. Attempted mapping IDs dikecualikan agar source yang sama tidak dikirim dua kali.

Order snapshot total/cost/max-price tidak ditulis ulang ketika source berikutnya dipilih.

Mekanisme ini **bukan universal failover semua provider**. Adapter otomatis yang ditemukan saat audit adalah Digiflazz dan voucher-stock internal; manual fulfillment mempunyai flow Admin sendiri.

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
