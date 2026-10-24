---
name: scenario-review
description: "Review before push: scenario matrix, sub-agents."
---

# Scenario review — cari bug perilaku sebelum push

Tujuan: menangkap kelas bug yang **tidak mungkin** ditangkap cek tipe, lint, build, atau test penjaga — panel yang tidak tertutup, state basi setelah props refresh, tombol yang membatalkan aksi tombol lain, label mentah bocor ke layar.

Aturan inti: **review boleh didelegasikan ke sub-agen paralel; perbaikan kode selalu dikerjakan parent sendiri** dengan skill coding yang relevan (`vue-patterns`, `laravel-best-practices`, `laravel-security`, dst). Sub-agen tidak menulis kode.

## Kapan dipakai

- Setiap kali ada commit lokal yang belum di-push (terutama sebelum PR).
- Setelah redesign/refactor yang menyentuh state bersama (ref, props, Sheet, dialog konfirmasi).
- Saat reviewer otomatis (Codex) menemukan bug yang lolos dari test.

## Langkah 0 — inventaris mekanis (wajib, cepat)

```bash
node scripts/inventory.mjs resources/js/Pages/Admin
```

Skrip menandai tiga pola yang paling sering jadi bug:

| Penanda | Arti | Bug yang biasanya muncul |
|---|---|---|
| `OBJEK-DISIMPAN` | `x.value = item` (objek dari daftar) | objek basi setelah props refresh (unggah gambar hilang, data lama tampil) |
| `PANEL-TANPA-RESET` | `:open` bergantung pada ref yang tidak dikosongkan di `onSuccess` | panel kosong tetap terbuka setelah baris dihapus |
| `DELETE-TANPA-ONSUCCESS` | `router.delete(...)` tanpa `onSuccess` | sama seperti di atas, versi tombol hapus |

Setiap temuan inventaris **wajib** diputuskan: bug nyata, atau aman karena alasan yang ditulis (mis. panel ditutup di tempat lain). Jangan lewati tanpa keputusan.

Penanda inventaris adalah **pertanyaan, bukan temuan**. Sebelum melaporkan, baca rantai handler-nya: banyak panel "tanpa reset" ternyata ditutup lewat helper (`closeEditor()`) atau lewat `onSuccess` milik form, bukan lewat assignment ref. Kalau satu penanda membanjiri hasil dengan positif palsu (mis. menandai string pesan sebagai objek), **perketat matcher-nya** — jangan dibiarkan berisik dan jangan diabaikan; output yang berisik membuat penanda asli tenggelam.

## Langkah 0b — sweep komponen bersama (hasil tinggi)

Bug di komponen bersama **berlipat** ke setiap halaman yang memakainya, dan reviewer otomatis sering melewatkannya karena diff-nya tidak menyentuh komponen itu.

```bash
# semua komponen yang dipakai >1 halaman
for f in resources/js/Components/*.vue; do n=$(grep -rl "$(basename $f .vue)" resources/js/Pages | wc -l); [ "$n" -gt 1 ] && echo "$n $f"; done | sort -rn
# sisa dialog bawaan browser di seluruh frontend
grep -rn "window\.confirm\|window\.alert\|window\.prompt\|[^.]confirm(" resources/js --include=*.vue --include=*.js
```

Komponen bersama wajib ikut matriks Langkah 1, minimal: konfirmasi hapus (dialog bertema, bukan `window.confirm`), unggah/hapus gambar, tombol yang dinonaktifkan saat proses, dan pesan galat yang tampil.


## Langkah 1 — matriks skenario (jantung skill ini)

Susun daftar **entitas** × **aksi**, lalu jalankan tiap pasangan sebagai skenario konkret, bukan sebagai pembacaan kode.

Entitas: banner, pop-up, berita, FAQ, ulasan, aset toko, halaman bantuan, kategori, produk, nominal, pemberitahuan produk, voucher, metode pembayaran, penyedia, admin, tiket, tier pelanggan, kode game.

Aksi wajib untuk setiap entitas:

1. buka editor → simpan
2. buka editor → unggah gambar → (props refresh) → apakah gambar langsung terlihat?
3. buka editor → hapus gambar → apakah kontrol hapus ikut hilang?
4. hapus baris yang **sedang terbuka** di panel
5. buka editor A → tutup → buka editor B (apakah isian A terbawa?)
6. buka editor → simpan gagal validasi (422) → tutup → buka lagi (apakah pesan/galat lama tertinggal?)
7. ganti filter/halaman saat panel terbuka
8. klik hapus → batal (tidak boleh ada mutasi)
9. klik hapus → setuju → apakah baris hilang **dan** panel tertutup?
10. simpan lalu langsung hapus
11. daftar kosong (empty state masuk akal, tanpa tombol mati)
12. pengguna tanpa izin: tombol tersembunyi **dan** URL langsung ditolak server
13. lebar 390 px: panel penuh, tidak ada geser mendatar, tombol tidak keluar layar
14. dua tab: tab A menghapus, tab B masih menampilkan baris lama

Untuk tiap pasangan tulis satu baris: `ENTITAS · AKSI → hasil yang diharapkan`. Yang menyimpang jadi temuan.

## Langkah 2 — empat sumbu tinjauan, paralel

Delegasikan sebagai sub-agen terpisah (2–4 agen, satu kelompok halaman per agen). Semua diberi diff `git diff <base>...HEAD` dan hasil inventaris Langkah 0.

1. **Click-path** (dari skill `click-path-audit`): untuk setiap handler, lacak urutan pemanggilan; state apa yang ditulis/di-reset; apakah panggilan berikutnya membatalkan yang sebelumnya. Enam pola: Sequential Undo, Async Race, Stale Closure, Missing Transition, Dead Path, Effect Interference.
2. **Kontrol diam** (dari `web-issue-investigation`): tombol yang tidak bereaksi, form yang gagal tanpa pesan, `preventDefault` yang mematikan submit, `disabled` yang tidak pernah lepas.
3. **Spec/rancangan** (dari `code-review`): bandingkan dengan `docs/superpowers/plans/*.md` — ada yang hilang, ada yang menyimpang, ada scope creep.
4. **Diff/regresi** (dari `differential-review`): apakah perubahan menghidupkan lagi bug yang pernah diperbaiki, adakah jalur tanpa cek izin, adakah kode mentah (status/action/snake_case) yang bisa sampai ke layar, adakah tanggal tanpa `Asia/Jakarta`.

Brief sub-agen harus memuat: "Laporkan **hanya** cacat yang bisa dibuktikan: file:line, langkah pemicu, perilaku salah yang terlihat. Tanpa saran gaya, tanpa 'sebaiknya'. Kalau tidak ada, tulis tidak ada." Sebutkan juga temuan yang sudah diketahui agar tidak dilaporkan ulang.

## Langkah 3 — keputusan parent (bukan sub-agen)

Untuk setiap temuan: **perbaiki / tolak dengan alasan / tunda**. Temuan yang ditolak wajib punya alasan teknis yang bisa ditunjukkan (mis. "zona waktu aplikasi memang UTC"). Jangan menelan temuan mentah-mentah, dan jangan mengabaikannya tanpa alasan.

## Langkah 3b — perbaiki per kelas, bukan per temuan

Satu temuan hanya menamai satu baris; pola di belakangnya biasanya ada di beberapa berkas. Memperbaiki temuan satu per satu menjamin temuan sekelas muncul lagi di baris lain pada putaran berikutnya, dan setiap push membuat reviewer membaca ulang seluruh berkas — putaran tidak pernah selesai.

Sebelum menambal, cari kelasnya di seluruh permukaan yang berubah (`search_files` atas pola, bukan atas nama variabel), lalu perbaiki semua anggotanya dalam satu commit. Kelas yang paling sering berulang di panel CRUD:

1. **Form → server:** field dikirim, controller tidak memvalidasi/menyimpannya. Simpan melaporkan sukses, nilai tidak pernah masuk.
2. **Server → form:** field wajib di server tidak ada di pop-up, atau kontrol di pop-up tidak punya padanan di payload.
3. **Server → server:** payload edit menimpa kolom yang tidak pernah ditampilkan (nilai jadi `null`/`0`). Diff kunci payload terhadap kontrol yang benar-benar ada di modal.
4. **Dua sumber untuk satu angka/status:** kunci setelan lama diganti tapi prop/kolom lama masih dibaca di suatu tempat. Setelah mengganti sumber, grep kunci lama di backend juga, bukan hanya di frontend.
5. **Penjagaan hanya di klien:** konfirmasi sandi/izin dicek di layar tapi route-nya tidak memakai middleware. Klien adalah kenyamanan; server adalah penegakan.
6. **Endpoint hidup tanpa kontrol:** route ada, tombolnya tidak — atau sebaliknya.

Kalau satu kelas sudah muncul di dua tempat, anggap kelas itu belum tertutup sampai daftar anggotanya habis dicari.

## Langkah 4 — perbaikan oleh parent

- Baca berkas yang terdampak dulu; jangan menambal berdasarkan ringkasan sub-agen.
- Pakai skill coding: `vue-patterns` untuk Vue/Inertia, `laravel-best-practices` + `laravel-security` untuk PHP, `pest-testing` bila menambah test.
- Diff sekecil mungkin, tanpa abstraksi baru.
- Untuk bug state: pilih satu sumber kebenaran — biasanya **simpan ID, turunkan objek lewat `computed` dari props**, bukan menyalin objek ke ref.
- Setiap perbaikan perilaku meninggalkan satu pemeriksaan yang bisa dijalankan ulang (test kecil atau assert di test browser).

## Langkah 5 — gerbang sebelum push

Paralel, bukan berurutan:

```bash
npm run lint && npm run check && npm run build
node --test tests/Browser/admin-ui-guards.test.mjs tests/Browser/admin-relocation.test.mjs
vendor/bin/pint --test
```

Lalu commit (satu topik satu commit), **lalu ulangi review sub-agen atas commit itu** — bukan langsung push.

### Langkah 5b — review pasca-commit (WAJIB, tidak boleh dilewati)

Setelah commit terbentuk, jalankan ulang sub-agen peninjau terhadap `git diff origin/main...HEAD` (atau rentang commit yang belum di-push) **termasuk commit yang baru saja dibuat**. Alasannya konkret: perbaikan yang ditulis di Langkah 4 adalah kode baru, dan kode baru bisa mengandung bug baru — pernah terjadi dua kali berturut-turut (batas hari laporan yang justru memotong data, dan editor Penyedia yang tidak mengembalikan nilai saat Batal).

Aturan:

- Sub-agen membaca **diff commit**, bukan ringkasan pekerjaan. Brief sama seperti Langkah 2.
- Kalau sub-agen menyatakan **lolos** (tidak ada cacat terbukti), baru push lalu minta tinjauan reviewer otomatis (`@codex review`).
- Kalau ada temuan: laporkan daftar temuannya ke pengguna lebih dulu (satu baris per temuan: file:line, langkah pemicu, perilaku salah), lalu parent memperbaiki, commit lagi, lalu **ulangi Langkah 5b pada commit baru itu**. Jangan push di tengah putaran.
- Kalau reviewer otomatis nanti menemukan hal baru, perbaiki dan push saat itu juga tanpa menunggu CI.

#### Ukuran menentukan cara review (jangan salah pilih)

| Diff commit | Cara | Waktu wajar |
|---|---|---|
| ≤ ~200 baris dan ≤ 2 berkas | parent review sendiri, pakai `references/fast-review-pack.md` | 2–5 menit |
| > 200 baris atau banyak berkas | 2–4 sub-agen, masing-masing diberi **paket teks** siap baca | 3–8 menit |

Sub-agen yang diberi repo + alat akan menghabiskan 40–60 menit untuk menelusuri seluruh pohon berkas, membuka puluhan berkas, dan menyusun harness — bukan untuk berpikir. Reviewer otomatis (Codex) cepat justru karena hanya menerima teks diff. Tiru itu: bangun paket teks lebih dulu, lalu larang sub-agen membuka repo. Resep lengkap + daftar skenario lanjutan per hunk ada di `references/fast-review-pack.md`.

Untuk diff kecil, **jangan delegasikan sama sekali** — parent lebih cepat dan bisa langsung memperbaiki. Delegasi hanya untuk cakupan yang benar-benar tidak muat di satu konteks.

Kalau sub-agen mati di tengah jalan (gateway restart, dihentikan), jangan tunggu ulang dari nol: kerjakan reviewnya sendiri. Laporan parsial yang menyebut satu kecurigaan (mis. "footer membaca `selectedProvider().id` tanpa `?.`") tetap harus diverifikasi dan diperbaiki.

Karena itu, minta setiap sub-agen **menulis temuannya ke berkas** (mis. `/tmp/review-<kelompok>.md`) selain mengembalikan ringkasan. Sub-agen yang induknya berhenti sebelum hasil dicatat kembali berstatus `unknown` tanpa ringkasan sama sekali, dan satu-satunya sisa pekerjaannya adalah berkas itu. Setiap klaim dari berkas tetap diverifikasi ulang sendiri sebelum masuk laporan.

Jangan pernah menyatakan "aman" atau membuat PR berdasarkan gerbang uji saja; PR dibuat hanya setelah ada pernyataan lolos dari review pasca-commit.

**Begitu reviewer otomatis mengomentari temuan, mulai kerjakan saat itu juga** — jangan menunggu CI selesai dulu. CI hanya memastikan tidak ada regresi; menunggunya membuat putaran perbaikan berantai (satu temuan per putaran CI).

**Tapi push bukan langkah yang sama dengan commit.** Setelah perbaikan: commit → jalankan Langkah 5b pada commit itu → laporkan temuannya → perbaiki → commit lagi → ulangi sampai satu putaran tidak menemukan skenario baru → baru push. Push tepat setelah commit dibaca sebagai melewati review, dan setiap putaran yang langsung di-push menambah satu putaran reviewer otomatis lagi. Push yang tidak mengembalikan exit code (koneksi putus, job latar mati) belum terjadi — buktikan dengan `git rev-parse HEAD origin/<branch>`.

## Larangan

- Jangan menyatakan "sudah dicek semua" tanpa menjalankan inventaris + matriks + sweep komponen bersama.
- Jangan melaporkan temuan tanpa file:line dan langkah pemicu.
- Jangan menyerahkan penulisan kode ke sub-agen.
- Jangan menutup temuan dengan "test hijau" — test hijau bukan bukti perilaku benar.
- Jangan menyimpulkan "tidak ada masalah" dari keluaran inventaris yang belum dikalibrasi.
- Jangan menyerahkan diff kecil ke sub-agen penuh-alat; bangun paket teks dulu atau kerjakan sendiri.
- Jangan menunggu gerbang yang lambat dalam diam. Sebutkan perintah yang sedang jalan dan mengapa lambat (mis. hook `pre-push` repo menjalankan `php -l` untuk seluruh pohon `app/tests/routes`, lalu Pint) — pengguna membaca diam sebagai macet.
