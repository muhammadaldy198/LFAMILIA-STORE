# Deploy LFAMILIA STORE dari HP

Panduan ini memakai GitHub sebagai penyimpan source dan Cloudflare Workers sebagai hosting. Keduanya dapat dimulai dengan paket gratis, tetapi transaksi nyata tetap memerlukan saldo DigiFlazz dan biaya penyedia pembayaran.

## 1. Siapkan source

1. Unduh dan ekstrak ZIP LFAMILIA STORE di HP.
2. Pastikan hasil ekstrak langsung berisi `package.json`, folder `app`, dan folder `components`—bukan folder berlapis dua kali.
3. Jangan mengisi `.env.example` dengan API key asli.

## 2. Buat repository GitHub

1. Buka GitHub, tekan **New repository**.
2. Nama repository: `lfamilia-store`.
3. Pilih **Private** jika source belum ingin dilihat publik.
4. Jangan centang pembuatan README karena file tersebut sudah tersedia.
5. Unggah seluruh isi folder hasil ekstrak, lalu commit ke branch `main`.

Jika unggah folder melalui browser HP sulit, gunakan Termux:

```bash
pkg update
pkg install git gh nodejs-lts
termux-setup-storage
gh auth login
cd /storage/emulated/0/Download/lfamilia-store-source
git init
git add .
git commit -m "Website awal LFAMILIA STORE"
git branch -M main
git remote add origin https://github.com/NAMA-ANDA/lfamilia-store.git
git push -u origin main
```

Ganti `NAMA-ANDA` dengan username GitHub.

## 3. Hubungkan ke Cloudflare

1. Masuk ke dashboard Cloudflare.
2. Buka **Workers & Pages**, lalu pilih pembuatan aplikasi dari repository Git.
3. Hubungkan akun GitHub dan pilih repository `lfamilia-store`.
4. Pilih **Workers**. Jika formulir meminta *output directory* untuk Pages, kembali dan pilih alur Workers.
5. Gunakan konfigurasi build berikut:

| Pengaturan | Nilai |
| --- | --- |
| Production branch | `main` |
| Root directory | `/` |
| Build command | `npm run build` |
| Deploy command | `npx wrangler deploy` |
| Node.js | `22.13.0` atau lebih baru |

6. Simpan dan tunggu build selesai.
7. Cloudflare akan memberi alamat seperti `lfamilia-store.NAMA-SUBDOMAIN.workers.dev`.

Perubahan yang di-commit ke branch `main` selanjutnya dapat dideploy otomatis oleh Cloudflare.

## 4. Domain sendiri (opsional)

Setelah website aktif, buka Worker LFAMILIA STORE → **Settings** → **Domains & Routes** → **Add custom domain**. Domain berbayar tidak wajib untuk menguji website.

## 5. Saat API akan dipasang

Tambahkan nilai rahasia di Cloudflare Worker → **Settings** → **Variables and Secrets**. Jangan memasukkannya ke GitHub.

Nilai yang nanti diperlukan antara lain:

- `DIGIFLAZZ_USERNAME`
- `DIGIFLAZZ_API_KEY`
- `DIGIFLAZZ_WEBHOOK_SECRET`
- `MIDTRANS_SERVER_KEY`
- `MIDTRANS_CLIENT_KEY`
- `MIDTRANS_IS_PRODUCTION`

Mulai dari akun sandbox Midtrans. Jangan mengaktifkan transaksi nyata sebelum webhook, validasi nominal, idempotensi, refund, dan kontrol admin selesai diuji.

## Masalah umum

- **Build gagal karena Node terlalu lama:** atur `NODE_VERSION` ke `22.13.0` atau versi 22 yang lebih baru.
- **Cloudflare meminta folder output:** Anda berada di alur Pages; gunakan Workers.
- **Repository tidak terlihat:** periksa izin aplikasi Cloudflare di GitHub.
- **Website masih bertuliskan demo:** memang sengaja; hapus label hanya setelah API dan database benar-benar aman serta teruji.

