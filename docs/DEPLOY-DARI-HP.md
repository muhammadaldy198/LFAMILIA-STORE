# Deploy LFAMILIA STORE dari HP

Panduan ini memakai Termux, GitHub, Cloudflare Workers, dan D1.

## 1. Source

Ekstrak ZIP sampai folder `LFAMILIA-STORE` langsung berisi `package.json`, `app`, `components`, `drizzle`, dan `wrangler.jsonc`.

Jangan memasukkan credential ke `.env.example` atau file source lain.

## 2. GitHub dari Termux

```bash
cd /storage/emulated/0/Download/LFAMILIA-STORE
git config --global --add safe.directory /storage/emulated/0/Download/LFAMILIA-STORE
git status
git add .
git commit -m "Tambah stok kode otomatis"
git branch -M main
git push origin main
```

Jika folder belum menjadi repository, jalankan `git init` sebelum `git add .`. Jangan menjalankan `git init` lagi bila `git status` sudah bekerja.

## 3. Cloudflare Git build

Gunakan:

| Pengaturan | Nilai |
|---|---|
| Production branch | `main` |
| Root directory | `/` |
| Build command | `npm run build` |
| Deploy command | `npx wrangler deploy` |
| Node.js | `22.13.0` atau lebih baru |

Setiap push ke `main` akan memicu build baru.

## 4. Migrasi D1 production

Login Wrangler dari Termux, lalu jalankan satu kali:

```bash
npx wrangler login
npx wrangler d1 migrations apply lfamilia-store-db --remote
```

Setelah sukses, jangan mengulang atau menghapus file migrasi lama secara manual. Wrangler akan mengetahui migrasi yang sudah diterapkan.

## 5. Rahasia dan callback

Tambahkan secret serta variable melalui Cloudflare Worker → Settings → Variables and Secrets. Daftar lengkap ada di `INTEGRATION-SETUP.md`.

Mulai dengan:

- `IPAYMU_ENV=sandbox`
- `DIGIFLAZZ_ENV=development`
- `PUBLIC_BASE_URL=https://domain-toko-anda`
- `VOUCHER_DELIVERY_CHANNEL=both`

Sebelum mengimpor kode, isi `VOUCHER_ENCRYPTION_KEY` sebagai Secret minimal 32 karakter. Jangan pernah menggantinya setelah stok tersimpan. Konfigurasi email Resend dan WhatsApp Cloud API dijelaskan di `INTEGRATION-SETUP.md`.

## 6. Admin

Lindungi `/admin*` dan `/api/admin*` menggunakan Cloudflare Access, hanya untuk email pemilik. Jangan membuka panel admin sebelum Access aktif.

## Masalah umum

- `dubious ownership`: jalankan perintah `safe.directory` pada langkah 2 dengan path folder yang benar.
- Build gagal karena Node lama: set Node ke versi 22 atau lebih baru.
- Cloudflare meminta output directory: gunakan alur Workers, bukan Pages.
- Produk otomatis tidak bisa dibayar: isi provider dan SKU untuk nominal tersebut dari admin.
- Kode tidak terkirim: periksa stok, Resend/WhatsApp, lalu tekan Kirim kode pada pesanan atau Kirim ulang pada tab Voucher.
- Masih ada banner mode pengembangan: memang sengaja sampai sandbox, callback, SKU, dan harga lulus pengujian.
