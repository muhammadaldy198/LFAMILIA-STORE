# Audit dan rencana UI Admin — 2026-10-04

Baseline: `121d72b9`, runtime Laravel/Vue di `rebuild/`. Audit source, bukan klaim hasil visual production.

## Temuan aktual

- 24 halaman Admin, 7 komponen shared/application, dan primitives shadcn/reka-ui. CSS Admin tersebar di bagian awal dan akhir `app.css`; rule global `min-width:520px` memaksa tabel ke HP.
- Shell memakai Sheet/reka untuk navigasi mobile (focus trap/scroll lock sudah ada). Topbar memakai dropdown buatan sendiri dan z-index 65; overlay/content memakai 95/100. Branding memakai gradient/shadow, kontrol dan heading ditimpa banyak selector.
- Produk sudah memiliki compact product rows, filter dan pagination client-side. Editor masih Sheet lebar 94vw dengan daftar terlihat di belakang. Tab Data Pelanggan mengubah catalogTab di belakang Sheet, bukan editor. Nominal dan penanganan masih tabel desktop di HP. Reorder drag dan tombol naik/turun sudah tersedia.
- Digiflazz sudah memiliki compact disclosure rows dan pagination server. Summary masih kumpulan card. Detail sudah mencakup seller, stok, cut-off, baseline, mapping dan aksi.
- Pesanan dan Manual punya representasi mobile, tetapi setiap row menampilkan semua data sekunder. Detail pesanan punya event log dan confirmation untuk operasi berisiko.
- Favicon upload otomatis aktif dan URL dibagikan ke Admin/storefront melalui implementasi PR #198; tidak perlu mengganti flow credential/storage.
- Integrasi Tahap 8B sudah memisahkan enabled/configured/verified, environment, callback dan E2E; browser secret reveal telah dihapus. Pertahankan payload/status dan blank-secret preservation.

| Menu/halaman | Kondisi source dan pekerjaan UI |
|---|---|
| Dashboard | 2 tabel termasuk pesanan min 720px; daftar prioritas mobile, summary strip |
| Pesanan / Detail | Ringkas data utama, disclosure tujuan/kontak; pertahankan event log dan confirmation |
| Produk | Workspace penuh, 5 tab di satu tempat, nominal compact + editor detail, fallback reorder |
| Banner & Konten | Form bertab, 1 tabel min 780px; preview proporsional, upload/confirmation shared |
| Digiflazz | Pertahankan disclosure, ringkas summary/filter, angka rata kanan desktop |
| Provider | Mapping 8 kolom tanpa alternatif HP; progressive disclosure |
| Pembayaran | 3 tabel, tab metode masih mencampur gateway/channel/routing/QRIS; pisahkan area, pertahankan readiness |
| Pelanggan / Detail | 1 + 5 tabel tanpa mobile; prioritas identitas/tier/saldo/pesanan, detail tab existing, confirmation saldo existing |
| Promo | 2 tabel tanpa mobile; identitas/status/aksi utama, detail lain dibuka |
| Layanan Pelanggan | 8 kolom tanpa mobile; tiket/status/aksi utama |
| Laporan | 3 tabel; angka prioritas dan disclosure statistik sekunder |
| Admin & Akses | 2 tabel; akun/status/aksi, permission multi-select tetap checkbox |
| Pengaturan / Presentasi | Form bertab existing; spacing, label dan switch konsisten |
| Integrasi | Environment production warning dan 6 status dipertahankan; switch enabled |
| Health / Audit | 2 + 1 tabel lebar; ringkas identitas/status, detail dan redaction dipertahankan |
| Manual | Daftar existing diringkas; Sheet operasional memiliki scroll/focus lock |
| Validasi Akun | Tabel 980px tanpa alternatif HP; prioritas game/status/aksi |
| Notifikasi | List existing; compact spacing, long text |
| Login / Aktivasi | Kontrol/focus konsisten; auth/CSRF tetap |

## Referensi history vs PRD

Commit sebelum cleanup `92fd61f5`: `components/admin-product-manager.tsx`, `admin-digiflazz-workspace.tsx`, `admin-dashboard.tsx`, `admin-payment-workspace.tsx`, `admin-customer-workspace.tsx`, `admin-customer-directory.tsx`, `admin-media-upload.tsx`.

Pola yang diambil: lima tab produk, detail nominal terpisah, summary strip monitor, pemisahan konfigurasi dan transaksi pembayaran, identitas pelanggan + detail operasional, preview upload dengan rekomendasi. History lama juga memakai tabel min 890–980px dan font sangat kecil; pola tersebut tidak diadopsi. PRD aktif menuntut semua fitur dan RBAC tetap tersedia. Tidak ada runtime/API/dependency legacy dikembalikan.

## Rencana komponen global

- `admin.css`: token warna/spacing/radius/typography, surface netral, fokus jelas, kontrol 36/40px, angka tabular, sidebar stabil, layer shell/dialog terukur.
- `AdminResponsiveTable`: mempertahankan slot dan aksi tabel existing di desktop; di HP menjadi compact rows dengan kolom prioritas eksplisit per tabel, detail sekunder native disclosure. Tidak membuat card per record, tidak menduplikasi request/handler.
- `AdminSwitch`: boolean operasional, accessible keyboard/disabled/checked; checkbox tetap untuk multi-select dan konfirmasi eksplisit.
- AdminShell: dropdown reka, active navigation aria-current, skip link, pencarian ringkas, drawer full-height.
- Workspace Produk: in-page full workspace dengan URL editor yang dapat dibuka ulang; list hanya muncul ketika editor ditutup. Tab Data Pelanggan berada di workspace yang sama. Bottom save bar aman dengan ruang konten.
- `AdminMediaControl`: preview berasio/contain, rekomendasi existing, busy/error state dan confirmation hapus; endpoint Spatie existing.

## Gate per bagian

Mobile: 360, 390, 412, 430px; desktop: 1024, 1280, 1440, 1920px. Gate utama 390×844, 430×932, 1024×768, 1440×900. Browser actual Laravel routes pada fixture lokal; cek overflow, nama panjang, angka besar, tab/editor/nominal/drawer, keyboard dan scroll lock. Lint, vue-tsc, build, PHPUnit MySQL 8/Redis, Pint, security scan dan CI tetap wajib. Deploy hanya sesudah gate dan mengikuti `deploy/README.md`.
