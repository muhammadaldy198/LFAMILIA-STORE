# Digiflazz — aturan terkini (25 Oktober 2026)

**Jangan menggunakan formula lama di arsip / percakapan 12–22 Oktober 2026.**
Sumber utama: `docs/PRD.md` v2.1 §4, §8, §19;
`AGENTS.md` dan `rebuild/PRODUCT.md` / `rebuild/FULFILLMENT.md`.

## Model katalog: satu SKU terpilih per nominal

Setiap nominal Digiflazz memiliki **satu SKU terpilih**, tanpa SKU
cadangan `_B`, tanpa perpindahan otomatis ke SKU lain sebelum/atau
sesudah pengiriman. Provider mappings tambahan yang tersisa dari
migrasi lama hanya data historis dan memerlukan pemeriksaan admin,
bukan daftar fallback.

## Sistem tiga harga

| Konsep | Perhitungan |
|---|---|
| Harga seller | Harga Digiflazz terbaru; diperbarui oleh sinkronisasi |
| Harga modal toko | Dibentuk **sekali** saat impor: seller + uplift bawaan **2%**, bulatkan ke atas; bisa diedit admin |
| Harga jual | Modal toko × (1 + markup nominal bawaan **10%**), bulatkan ke atas; bisa diganti harga tetap per nominal |

Tidak ada formula ML khusus ×1.06; tidak ada markup tingkat game.
Sinkronisasi tidak menimpa modal toko atau harga jual yang ditentukan
dari modal dan setelan nominal. Bila seller > modal toko, nominal
disembunyikan dan tidak boleh di-checkout sampai harga pulih.

Contoh: seller Rp10.000 → modal Rp10.200 → jual Rp11.220.
**Request Digiflazz `max_price=10200`**, persis modal SKU terpilih,
tanpa tambahan 1% atau harga SKU cadangan. Ini berbeda dari istilah
administratif lama `provider_mappings.max_price_idr`; hanya snapshot
pesanan yang dikirim ke API.

## Sinkronisasi dan keamanan transaksi

- Sinkronisasi katalog penuh default **15 menit**, tidak pada setiap
  kunjungan pembeli; simpan data di DB lokal, nilai terakhir valid
  dipertahankan bila request gagal.
- `sign=md5(username+apiKey+ref_id)`, endpoint
  `https://api.digiflazz.com/v1/transaction`. Verifikasi
  `ref_id`, SKU, dan `customer_no` terhadap snapshot request.
- `Sukses + rc=00` → pengiriman sukses.
- `Pending + rc=03/99` → PENDING.
- `rc=01` (timeout) dan `rc=70` (timeout biller) dapat berarti
  transaksi SUDAH dibuat; tetapkan UNKNOWN jika hasil tak pasti,
  **jangan otomatis kirim SKU lain**.
- Respons status dan rc bertentangan → UNKNOWN untuk rekonsiliasi.
- Cek ulang paling cepat 1 menit, kebijakan otomatis LFAMILIA
  sebanyak 3 kali dengan jeda minimal 2 menit, `ref_id` sama.
  Jangan cek ulang transaksi yang telah berumur lebih dari 90 hari.
- Webhook HMAC-SHA1 wajib diverifikasi sebelum status diproses.

## Checklist regresi wajib

- [ ] Dua SKU nominal yang sama tidak dapat diimpor/dihidupkan.
- [ ] Migrasi impor sekali jalan tidak membuat SKU cadangan pada instalasi baru.
- [ ] SKU pilihan diblokir sebelum request: satu BLOCKED attempt;
  tidak otomatis menggunakan mapping kedua yang tersisa dari masa lalu.
- [ ] Max price tepat modal toko, snapshot tetap saat harga seller berubah.
- [ ] Seller naik di atas modal → nominal tersembunyi.
- [ ] Uplift 2% tidak ditambahkan ulang setiap sync.
- [ ] Timeout dan callback terlambat tidak menggandakan pemenuhan pesanan.
- [ ] Tidak ada transaksi uang atau Digiflazz nyata dalam test CI.

## Dokumentasi provider

https://developer.digiflazz.com/api/buyer/topup/
https://developer.digiflazz.com/api/buyer/response-code/
https://developer.digiflazz.com/api/buyer/cek-status/
