# Audit Kode Mati — Hasil Audit Ulang (versi Joko)

- **Tanggal:** 22 Oktober 2026
- **Repo:** `/home/hatch/workspace/LFAMILIA-STORE/rebuild`
- **HEAD:** `941cd835` (main, setelah PR #294 merge)
- **Sifat:** READ-ONLY. Tidak ada file repo yang diubah, dibuat, atau dihapus.
- **Status:** BAHAN DISKUSI. Belum ada keputusan eksekusi.

> Catatan: dokumen ini **bukan** salinan `AUDIT-kode-mati-20261022.md`. Ini hasil audit ulang
> yang dijalankan sendiri, dengan metode berbeda, dan **memuat koreksi** terhadap dokumen itu.

---

## Metode

Laporan lama menghitung "nama muncul berapa kali" (grep count). Metode itu rapuh: bisa
salah dua arah — menandai mati padahal dipanggil lewat konvensi framework, dan melewatkan
fungsi yang mati bersama induknya.

Audit ini memakai **analisis keterjangkauan (reachability)**:

1. Kumpulkan seluruh korpus: `app/ routes/ config/ database/ tests/ resources/js/ resources/views/ bootstrap/` (416 file).
2. Untuk setiap method, tentukan **pintu masuk yang sah**: terdaftar sebagai route, dipanggil framework
   (konvensi Laravel), atau dipanggil dari luar kelasnya.
3. Dari tiap pintu masuk, telusuri rantai `$this->method()` / `self::method()` sampai habis.
4. Method yang tidak terjangkau dari pintu mana pun = **mati**.
5. Setiap kandidat diperiksa manual satu per satu sebelum divonis.

---

## Ringkasan Sapuan

| Kategori | Diperiksa | Mati |
|---|---:|---:|
| Kelas PHP di `app/` | 148 | **0** |
| Action route (resolve ke method nyata) | 206 | **0 rusak** |
| Komponen Vue di-import | 44 | **0 yatim** |
| Ekspor JS (`lib/`, `Composables/`, `Data/`) | semua | **0** |
| Import PHP tak terpakai (`use ...;`) | semua | **0** |
| Import Vue tak terpakai | semua | **0** |
| Halaman Vue pernah di-render | 61 | **1** (`Guest/Lookup`) |
| Nama route dipakai kode | 218 | **188 tidak dipakai** |
| Method tidak terjangkau | semua | **5** |

---

## A. Yang Benar-Benar Mati (5 method + 1 halaman)

| # | Lokasi | Bukti | Catatan |
|---|---|---|---|
| 1 | `AdminDigiflazzController::autoSources` | 0 route, 0 pemanggil | Service `DigiflazzAutoSources` **HIDUP**, jangan disentuh |
| 2 | `CatalogPricingSettings::costUpliftFor` | 0 pemanggil | Lihat bagian D — ini duplikasi, bukan rencana mati |
| 3 | `CatalogPricingSettings::sellMarkupFor` | 0 pemanggil | Idem |
| 4 | `CatalogPricingSettings::firstUsable` (private) | hanya dipanggil #2 & #3 | Ikut mati bila induk dihapus |
| 5 | `CatalogPricingSettings::tryNormalize` (private) | **hanya dipanggil #4** | **Tidak disebut di laporan lama** — rantainya 4 lapis, bukan 3 |
| 6 | `resources/js/Pages/Guest/Lookup.vue` | 0 `Inertia::render`, 0 import | Satu-satunya dari 61 halaman yang tak pernah tampil |

Rantai kematian butir 2–5:

```
costUpliftFor  ──┐
                 ├──> firstUsable ──> tryNormalize
sellMarkupFor  ──┘
```

Memotong dua yang atas saja = memindahkan kode mati, bukan membersihkan.

---

## B. Yang Hampir Salah Divonis Mati (JANGAN DISENTUH)

| Item | Kenapa kelihatan mati | Kenyataan |
|---|---|---|
| `NicknameGameCode::scopeActive` | nama tidak muncul di pemanggil | dipanggil `->active()` (konvensi Eloquent) |
| `NicknameGameCode::scopeNicknameSupported` | idem | dipanggil `->nicknameSupported()` (3 tempat) |
| `AdminCatalogController::clearLegacyCustomerNoTemplates` | skrip reachability gagal baca blok bersarang | dipanggil di baris 473 & 803 — **HIDUP** |
| `HandleInertiaRequests::share` | tidak dipanggil kode aplikasi | dipanggil framework Inertia |
| `uniqueId`, `backoff` (Jobs) | idem | konvensi Laravel queue |
| `registerMediaCollections` (8 model) | idem | konvensi spatie/laravel-medialibrary |
| `AdminOrdersController::query()` | tidak ter-route | dipanggil via injeksi antar-controller |
| `AdminCatalogController::index/product/fields` | nama method generik | dipanggil sebagai `$this->index()` internal |
| `User::savedGameAccounts()` | 0 pemanggil (controller pakai `DB::table`) | relasi model — wajar disimpan |
| `CatalogPricingSettings::normalize()` | kandidat semu | dipanggil internal di `update()` |
| `LoginRiskService::failures()` | idem | dipanggil internal baris 17 |
| `DokuSignature::digest()` | idem | dipanggil internal baris 28 & 63 |
| `TransactionalEmailService::queueForOrder()` | idem | dipanggil internal 4 tempat |
| `DigiflazzAutoSources` (service) | namanya mirip action mati #1 | dipakai 4 tempat + tes |
| Endpoint `POST /admin/digiflazz/max-prices/*` | frontend tidak memanggil lagi | sengaja dipertahankan (keputusan harga tertunda) |
| Penolakan `auto_sources` di impor | terlihat seperti sisa fitur mati | pagar validasi — sengaja menolak format lama |

---

## C. Butir Lama dari Laporan Sebelumnya

| Butir | Status | Vonis Joko |
|---|---|---|
| #1 handler Harga Max di `Digiflazz.vue` | **sudah hilang**, 0 sisa | TUTUP — ada penjaga tes |
| #2 `_topProducts` + `_integrationSummary` + query `$top` | masih mati | MATI, tapi **PR terpisah** (lihat E) |
| #3 `autoSources()` | masih mati | MATI — hapus fungsinya saja |
| #4 `_voucherCodeError` di `Catalog/Show.vue:619` | masih ada | **BUKAN kode mati** — pola omit yang berfungsi |

### Catatan butir #4

```js
const { voucher_code: _voucherCodeError, ...remainingErrors } = errors.value;
errors.value = remainingErrors;
```

Variabel `_voucherCodeError` sengaja tidak dipakai — dia "tempat sampah" untuk memisahkan
key `voucher_code`. `remainingErrors` dipakai di baris berikutnya. Menghapus mentah =
error voucher lama tetap muncul saat pembeli memilih nominal. **Biarkan.**

---

## D. TEMUAN TERPENTING — Aturan Harga Ditulis 4 Kali

Aturan **nominal → game → global** (untuk cost uplift dan sell markup) **HIDUP dan DIPAKAI**.
Tapi implementasinya ada di empat tempat terpisah:

| # | Lokasi | Fungsi | Status |
|---|---|---|---|
| 1 | `DigiflazzCatalogImport::initialStoreCost` (baris 22–24) | hitung modal toko dari uplift | **DIPAKAI** (impor provider) |
| 2 | `CheckoutPricing` (baris 285) | hitung markup jual | **DIPAKAI** (tiap checkout) |
| 3 | `Catalog.vue` `effectiveUpliftPercent` / `effectiveMarkupPercent` (baris 161–170) | pratinjau di admin | **DIPAKAI** (tampilan) |
| 4 | `CatalogPricingSettings::costUpliftFor` / `sellMarkupFor` | versi keempat | **TIDAK DIPAKAI** |

Konsekuensi: **butir 5 bukan "hapus rencana mati" — ini duplikasi aturan yang sedang berlaku.**
Menghapus #4 aman secara teknis (0 pemanggil, 0 tes mengunci), tapi yang lebih benar adalah
memakai #4 dari #1 dan #2 supaya aturan persen cuma ditulis sekali.

### Tambahan: aritmetika pembulatan ditulis 3 kali

`CatalogPricingSettings::applyPercent` (+ `percentUnits`) **hanya dipanggil tes**
(`AdminPanelRestorationTest.php:1341,1343`). Di produksi, hitungan yang sama ditulis ulang manual:

- `DigiflazzCatalogImport::initialStoreCost` baris 25–34
- `CheckoutPricing::margin()` baris 380–386

Kalau aturan pembulatan berubah, tiga tempat harus diubah serempak — dan satu bisa terlewat.

**Status: MENUNGGU KEPUTUSAN PRODUK.** Pilihan: (a) biarkan, (b) satukan ke satu tempat,
(c) hapus salinan #4. Rekomendasi: **(a) biarkan dulu** — menyentuh jalur harga berisiko
tinggi dan tidak mendesak.

---

## E. Butir #2 (Dashboard) — Kenapa Harus PR Sendiri

Yang mati: `_topProducts`, `_integrationSummary` (Dashboard.vue:21, 57), dan query `$top`
di `AdminDashboardController.php:104–109` + prop di baris 138.

**Tapi ada 3 pengunci tes yang akan merah kalau dihapus:**

1. `tests/Feature/AdminDashboardRestorationTest.php:67` → `.where('topProducts.0.fulfilled_orders', ...)`
2. `tests/Feature/AdminDashboardRestorationTest.php:88` → `->has('topProducts', 1)`
3. `tests/Browser/admin-ui-guards.test.mjs:35` → menuntut `Array.isArray(props.topProducts)` ada di `Dashboard.vue`

Jadi paketnya **5 file**, bukan 2. Karena mengubah tes, pisahkan dari PR bersih-bersih lain.

**PERINGATAN:** halaman `Reports.vue` juga memakai prop `topProducts` — di sana **HIDUP**
(ada tabel + ekspor CSV, dikunci `admin-ui-guards.test.mjs:119` dan
`AdminReportRestorationTest.php:164`). **Jangan ikut dihapus.**

---

## F. Nama Route Tidak Dipakai — 188 dari 218

Fakta terkonfirmasi: 231 route total, 218 bernama, **30 dipakai** lewat `route()`/`to_route()`/`routeIs()`,
**188 tidak**. Frontend sama sekali tidak memakai nama route (0 Ziggy/Wayfinder, navigasi pakai URL hardcoded).

**Rekomendasi: JANGAN SENTUH.** Menghapus `->name()` tidak memutus URL, tapi:
- manfaatnya nol secara teknis
- sebagian nama adalah kontrak dokumentasi (webhook `api.*` terdaftar di dashboard Midtrans/DOKU/Digiflazz)
- 188 baris diff untuk keuntungan kosmetik = risiko tidak sepadan

---

## G. Rencana Kerja (BELUM DIEKSEKUSI — menunggu persetujuan)

**PR A — bersih-bersih aman (tanpa menyentuh tes):**
- #3 hapus `AdminDigiflazzController::autoSources` (service `DigiflazzAutoSources` JANGAN disentuh)
- #6 hapus `CheckoutPricing::previewForPackage`
- #7 hapus `resources/js/Pages/Guest/Lookup.vue`
- ~~#5~~ **dicabut** — lihat bagian D, ini duplikasi bukan kode mati

**PR B — Dashboard (terpisah, karena mengubah tes):**
- Hapus 2 computed di `Dashboard.vue` + query `$top` + prop di controller
- Sesuaikan 2 assertion di `AdminDashboardRestorationTest.php` + 1 guard di `admin-ui-guards.test.mjs`
- **JANGAN sentuh `Reports.vue` / `AdminReportRestorationTest.php`**

**Tidak dikerjakan sama sekali:**
- #4 (pola omit) — biarkan
- Butir D (duplikasi harga) — menunggu keputusan produk
- Butir F (188 nama route) — jangan disentuh
- `User::savedGameAccounts()` — biarkan

---

## H. Perintah Verifikasi Ulang

```bash
cd /home/hatch/workspace/LFAMILIA-STORE/rebuild

# 5 method mati
grep -rn "costUpliftFor\|sellMarkupFor\|firstUsable\|tryNormalize" app routes tests resources/js
grep -rn "previewForPackage" app routes tests resources/js
grep -rn "autoSources" routes resources/js tests

# halaman yatim
grep -rn "Guest/Lookup" app routes tests resources/js config

# 3 pengunci butir #2
grep -n "topProducts" tests/Feature/AdminDashboardRestorationTest.php tests/Browser/admin-ui-guards.test.mjs

# bukti aturan harga hidup
sed -n '20,35p' app/Services/DigiflazzCatalogImport.php
sed -n '280,290p' app/Services/CheckoutPricing.php
sed -n '161,170p' resources/js/Pages/Admin/Catalog.vue

# route table
php artisan route:list --json | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d), len([r for r in d if r.get('name')]))"
```
