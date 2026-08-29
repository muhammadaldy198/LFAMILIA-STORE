# LFAMILIA STORE

Website top up game responsif untuk Cloudflare Workers + D1, dibuat dengan Next.js 16, React 19, Tailwind CSS 4, dan Vinext.

## Yang sudah tersedia

- Beranda, katalog, pencarian, checkout, FAQ, kontak, dan kalkulator game.
- Data akun berada di langkah pertama checkout dan dapat memeriksa nickname lewat API yang dikonfigurasi.
- Pembayaran iPaymu: Virtual Account bank, DANA, ShopeePay, dan QRIS; biaya iPaymu diarahkan ke pembeli.
- Produk otomatis multi-provider: adapter DigiFlazz dan VIPayment.
- Stok kode internal terenkripsi untuk REDFINGER/lisensi, dengan reservasi atomik dan pengiriman Email/WhatsApp otomatis.
- Produk manual: antrean admin untuk Roblox Via Login, Gamepass, Gift in Game, dan produk manual lain.
- Pelacakan invoice D1 dengan data tujuan disamarkan.
- Admin tersembunyi dari navigasi utama, dengan proteksi Cloudflare Access.
- Webhook bertanda tangan dan pencatatan event untuk mencegah pengiriman ganda setelah callback pembayaran berulang.

Kode integrasi sudah siap, tetapi toko tidak boleh menerima transaksi nyata sebelum migrasi D1, secret, SKU, harga, callback, dan Cloudflare Access selesai diuji.

## Menjalankan dari komputer atau Termux

Memerlukan Node.js 22 atau lebih baru.

```bash
npm install
npm run dev
```

Pemeriksaan source:

```bash
npm run lint
npm run build
npm test
```

## Deploy Cloudflare

Pengaturan build GitHub:

| Pengaturan | Nilai |
|---|---|
| Build command | `npm run build` |
| Deploy command | `npx wrangler deploy` |
| Root directory | `/` |

Ikuti [panduan deploy dari HP](docs/DEPLOY-DARI-HP.md), lalu lanjutkan [konfigurasi integrasi](INTEGRATION-SETUP.md).

Jangan simpan API key, VA merchant, webhook secret, password pelanggan, PIN, atau OTP di GitHub.

## Lisensi

Kode khusus LFAMILIA STORE menggunakan lisensi MIT. Komponen pihak ketiga mengikuti lisensinya masing-masing.
