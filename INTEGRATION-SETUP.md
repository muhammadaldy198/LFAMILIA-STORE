# Konfigurasi Integrasi LFAMILIA STORE

Arsitektur production LFAMILIA:

- **1 payment gateway:** DOKU Checkout
- **1 provider otomatis:** DigiFlazz
- **Produk manual/stok internal:** diproses LFAMILIA

## 1. Migrasi D1

Binding: `DB`, database: `lfamilia-store-db`, folder migrasi: `drizzle`.

Setelah branch ini masuk ke deployment, jalankan:

```bash
npx wrangler d1 migrations apply lfamilia-store-db --remote
```

Migration `0023_doku_digiflazz_reset.sql` menambahkan kolom DOKU, menonaktifkan toggle gateway lama, menghapus profile Midtrans/iPaymu/VIPPayment, dan membersihkan data transaksi lama. Produk dan akun tidak ikut dihapus.

## 2. Root encryption Cloudflare

Cloudflare tetap membutuhkan satu secret sistem:

```text
INTEGRATION_ENCRYPTION_KEY
```

Minimal 32 karakter dan jangan diganti setelah credential Integration Manager tersimpan.

## 3. DOKU dari Admin Panel

Buka **Admin Panel → Integrasi & harga → Kredensial API & callback → DOKU Checkout**.

Isi Sandbox atau Production:

- Client ID
- Secret Key
- Checkout API URL (boleh dikosongkan agar memakai endpoint resmi)
- pilih Environment DOKU yang aktif

Kemudian buka **Pembayaran** dan aktifkan DOKU untuk checkout dan/atau top up saldo.

### Notification URL DOKU

Pasang URL berikut di dashboard DOKU:

```text
https://lfamiliastore.my.id/api/payments/doku/callback
```

DOKU Checkout redirect pelanggan kembali ke halaman LFAMILIA, sedangkan status pembayaran hanya dianggap sah setelah notification bertanda tangan berhasil diverifikasi server.

## 4. DigiFlazz

Buka **Integration Manager → DigiFlazz** lalu isi Username, API Key, Transaction API URL, Price List URL, dan Webhook Secret untuk environment yang dipakai.

Webhook:

```text
https://lfamiliastore.my.id/api/fulfillment/digiflazz/callback
```

Untuk nominal otomatis pilih **DigiFlazz** dan isi SKU provider. Produk manual tidak membutuhkan provider.

## 5. VPS Relay

VPS relay sekarang hanya untuk DigiFlazz. DOKU berjalan langsung dari Worker.

Di Admin Panel → VPS Relay isi:

- DigiFlazz Relay URL
- Relay Token

Di VPS hapus konfigurasi host/upstream iPaymu/BI-SNAP lama, gunakan file `relay/server.mjs` dan `relay/Caddyfile.example` terbaru, lalu restart service relay dan reload Caddy.

## 6. Cloudflare Access

Area Owner tetap dilindungi Cloudflare Access:

- `/admin*`
- `/api/admin*`

Variable sistem yang tetap diperlukan sesuai konfigurasi Access:

```text
TEAM_DOMAIN
POLICY_AUD
```

Credential DOKU/DigiFlazz tidak ditempatkan di Cloudflare Variables/Secrets.

## 7. Checklist sebelum buka toko

- migrasi D1 `0023` sudah sukses
- DOKU Sandbox diuji end-to-end
- Notification URL DOKU menerima callback valid
- DOKU Production credential diisi sebelum go-live
- DigiFlazz SKU/harga/margin sudah sinkron dan diverifikasi
- relay DigiFlazz sehat jika IP statis memang diperlukan
- produk manual masuk ke antrean admin setelah pembayaran paid
- tidak ada credential provider di GitHub
