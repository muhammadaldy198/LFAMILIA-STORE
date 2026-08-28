# LFAMILIA STORE

Frontend toko top up game dan voucher digital bergaya gelap-neon, dibuat dengan Next.js 16, React 19, Tailwind CSS 4, Vinext, dan Cloudflare Workers.

## Status saat ini

Website ini sudah memiliki tampilan dan alur demo lengkap, tetapi **belum menerima pembayaran dan belum mengirim produk sungguhan**. Harga, akun, checkout, invoice, laporan, dan data admin masih berupa data contoh.

Halaman yang tersedia:

- Beranda dan katalog game + voucher
- Pencarian dan filter produk
- Checkout responsif dengan simulasi biaya admin
- Pelacakan invoice demo `DEMO-20260828-001`
- Login, daftar, dan area pelanggan demo
- Panel admin: ringkasan, pesanan, produk, voucher, konten, pelanggan, laporan, dan pengaturan
- FAQ, privasi, syarat, dan kebijakan refund

## Menjalankan di komputer atau Termux

Persyaratan: Node.js 22 atau lebih baru.

```bash
npm install
npm run dev
```

Buka alamat yang ditampilkan di terminal. Pemeriksaan sebelum upload:

```bash
npm run lint
npm run build
```

## Deploy ke Cloudflare Workers

Untuk deploy manual pertama kali:

```bash
npx wrangler login
npm run deploy
```

Untuk deploy otomatis dari GitHub, ikuti [panduan dari HP](docs/DEPLOY-DARI-HP.md).

## Integrasi berikutnya

Rencana backend DigiFlazz, Midtrans QRIS, database, keamanan webhook, serta biaya admin dijelaskan di [docs/RENCANA-API.md](docs/RENCANA-API.md).

Jangan pernah menyimpan Server Key, API key, password, atau webhook secret di source GitHub. Gunakan menu **Variables and Secrets** di Cloudflare.

## Lisensi

Kode khusus LFAMILIA STORE menggunakan lisensi MIT. Komponen pihak ketiga tetap mengikuti lisensinya masing-masing.

