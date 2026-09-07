# LFAMILIA STORE

Toko top up digital berbasis Cloudflare Workers dan D1. Aplikasi mencakup katalog, checkout Midtrans/iPaymu, saldo pelanggan, pemenuhan otomatis melalui provider, antrean manual, voucher diskon, stok kode terenkripsi, pelacakan transaksi, membership, serta panel Pemilik/Staff.

## Fitur utama

- Katalog dinamis dengan gambar, banner produk, kategori, urutan, status, tab pemisah nominal, dan harga yang dikelola dari panel.
- Nominal produk memakai provider per nominal, SKU provider, margin Rupiah/Persen, harga modal supplier, dan sinkron harga DigiFlazz per nominal maupun otomatis.
- Produk otomatis memakai DigiFlazz, VIPayment, atau Stok Kode LFAMILIA. Produk manual masuk antrean admin setelah pembayaran terverifikasi.
- Checkout Midtrans atau iPaymu dengan QRIS, Virtual Account, dan e-wallet sesuai channel yang aktif.
- Saldo pelanggan dapat di-top-up otomatis melalui gateway dan dipakai langsung saat checkout.
- Account pelanggan, membership BASIC/GOLD/DIAMOND/PLATINUM, leaderboard, ulasan, bantuan/refund, berita, FAQ, banner Home, dan pop-up informasi produk.
- Voucher diskon, promo terjadwal, minimum transaksi, kuota, dan batas potongan.
- Stok kode digital disimpan terenkripsi dan dapat ditampilkan di website serta dikirim melalui kanal notifikasi yang dikonfigurasi.
- Panel memakai sesi Pemilik/Staff berbasis ID admin + password. Akses pemulihan Pemilik tetap dapat dilindungi dengan Cloudflare Access.
- Secret payment/provider tidak disimpan di repository. Credential operasional dapat disimpan terenkripsi melalui Integration Manager.

## Menjalankan proyek

Prasyarat: Node.js `>=22.13.0` dan akun Cloudflare dengan Workers serta D1.

```bash
npm ci
npm run build
npm run lint
npm test
```

Konfigurasi Worker berada di `wrangler.jsonc`. Binding database harus bernama `DB`. Terapkan seluruh migrasi di folder `drizzle` secara berurutan. Migration lama dipertahankan apa adanya karena dapat sudah tercatat di D1 production. Jangan menjalankan `drizzle-kit generate` sampai metadata Drizzle dibaseline ulang; perubahan schema berikutnya harus dibuat sebagai migration SQL baru yang idempotent.

Panduan secret, callback, provider, relay, dan pengiriman kode tersedia di [INTEGRATION-SETUP.md](./INTEGRATION-SETUP.md).

## Keamanan

- Jangan simpan API key, secret pembayaran, webhook secret, atau kunci enkripsi di GitHub.
- Jangan meminta PIN atau OTP pelanggan melalui formulir maupun catatan pesanan.
- Lindungi halaman pemulihan Pemilik dan endpoint admin sensitif sesuai konfigurasi deployment.
- Gunakan password admin yang kuat; password disimpan sebagai hash dan tidak dapat dibaca kembali dari panel.
- `INTEGRATION_ENCRYPTION_KEY` tetap disimpan sebagai Cloudflare Secret root. Credential provider/service lain dapat dikelola terenkripsi melalui Integration Manager atau memakai Cloudflare Variables/Secrets sebagai fallback.
