# AGENTS.md — LFAMILIA STORE

Manual kerja untuk siapa pun yang menulis kode di repo ini: agen AI, asisten, atau manusia.

**Baca ini dulu sebelum menyentuh apa pun.** Kalau ada aturan di file lain yang bertentangan dengan file ini, urutan yang menang ada di §1.

---

## 1. Di mana aturan tinggal

Ada empat lapis. Yang atas menang atas yang bawah.

| Lapis | File | Isi |
|---|---|---|
| 1 | `docs/PRD.md` | **Aturan produk.** Apa yang dijual, alur, uang, status, refund, larangan. |
| 2 | `AGENTS.md` (file ini) | **Cara kerja.** Alur, git, deploy, batas VPS, laporan. |
| 3 | `docs/ADMIN-PANEL.md` | Spesifikasi panel admin (menu, halaman, peran, tampilan). |
| 4 | `rebuild/*.md`, `.claude/agents/*`, `.claude/skills/*` | **Cara kode bekerja** dan cara teknis mengerjakannya. |

Aturan tambahan:

- **Instruksi pemilik pada tugas yang sedang berjalan menang atas semuanya.** Kalau pemilik bilang "jangan sentuh X", X tidak disentuh walau dokumen bilang sebaliknya.
- **`docs/archive/` dan `rebuild/docs/archive/` bukan aturan.** Isinya rekaman keputusan lama. Jangan dipakai sebagai perintah, jangan dikutip sebagai "seharusnya begitu".
- **`CLAUDE.md` bukan aturan.** Isinya hanya penunjuk ke file ini.
- Kalau dokumen bertentangan dan tidak jelas mana yang benar: **berhenti dan tanya**. Jangan pilih sendiri lalu jalan.
- **Khusus PRD vs kode yang berjalan: tidak ada yang otomatis menang** (keputusan pemilik 2026-10-24). Perbedaan dicatat dan diputuskan pemilik kasus per kasus: kode menyimpang dari maksud pemilik → kode diperbaiki; PRD tertinggal keputusan yang lebih baru → PRD diperbarui. Pengecualian: **§19 PRD selalu menang**.

---

## 2. Siapa yang bekerja di sini

- **Pemilik** menentukan arah, menyetujui, dan memutuskan. Pemilik bukan programmer — jelaskan dengan bahasa awam, bukan istilah teknis.
- **Agen** menulis kode, menguji, dan melapor. Tidak memutuskan aturan bisnis sendiri.
- Peran spesialis ada di `.claude/agents/` (8 file). Satu tugas = satu peran utama. Ganti peran secara sadar kalau tugasnya berganti; jangan diam-diam mengerjakan pekerjaan peran lain (mis. deploy sambil mengulas).
- **Jangan pernah mengarang pembagian tugas.** Tidak ada "ChatGPT yang menulis, BETOL yang QA". Yang berlaku: siapa pun yang memegang tugas, mengikuti file ini.

---

## 3. Skill wajib (tidak otomatis)

Skill **tidak** jalan sendiri. Sebelum mulai, baca tabel di bawah, buka `SKILL.md` yang disebut, lalu ikuti. Bukan sekadar menyebut namanya.

Skill ada di `.claude/skills/`.

| Tugas | Skill wajib |
|---|---|
| **Semua tugas** | verification-before-completion — sebelum menyatakan selesai/lulus, tunjukkan buktinya |
| Menulis/mengubah PHP Laravel | laravel-best-practices + karpathy-coder + zero-hallucination-coder + strict-api + laravel-verification |
| Debug / bug sulit | systematic-debugging (+ debug-using-debugbar untuk lambat/N+1) |
| Nomor HP / kode negara | phone-internationalization-guard |
| Menulis/mengubah Vue/Inertia | inertia-vue-development + vue-best-practices + tailwindcss-development (+ frontend-design untuk arah desain) |
| Mockup → kode | ui-to-vue |
| Menyambung frontend ke route | wayfinder-development |
| Bukti tampilan HP + desktop | playwright |
| Aksesibilitas UI | accessibility + web-design-guidelines |
| Menulis/mengubah test | pest-testing + laravel-verification (+ webapp-testing untuk uji browser) |
| Matriks skenario sebelum push | scenario-review |
| Menelusuri alur tombol → DB | click-path-audit |
| QA eksploratif | dogfood |
| Audit/review kode | code-review + differential-review + karpathy-coder + data-fetch-analysis + skill-security-auditor |
| Review lawan PRD/rancangan | spec-to-code-compliance + audit-context-building |
| Cari bug di area berubah | find-bugs |
| Review keamanan & uang | security-review + laravel-security + gha-security-review + supply-chain-risk-auditor + sharp-edges |
| Payment/Midtrans | integrate-midtrans-payments — **hanya** bagian Snap/webhook/verifikasi/status. Bagian refund di skill itu **tidak berlaku** (lihat §5) |
| Digiflazz (harga, sync, produk, nominal) | lfamilia-digiflazz-specialist |
| Uang/ledger/saldo | lfamilia-payment-guardian |
| Fitur/modul baru | code-scaffolding + skill stack di atas |
| Deploy/CI | cicd-deployment |
| Review PR besar | code-tour |
| Rencana sebelum koding | grill-me + grilling + writing-plans |
| Spesifikasi / dokumen keputusan | to-spec + technical-documentation + lfamilia-product-spec |
| Desain UI/UX | frontend-design + ui-ux-pro-max + popular-web-designs + design-md |
| Media/upload | medialibrary-development |
| Auth (login/2FA/passkey) | fortify-development |

**Laporan wajib:** di akhir setiap tugas koding/audit, sebutkan skill mana yang dipakai dan mana yang dilewati beserta alasannya. Tidak ada pelewatan diam-diam.

**Pemilik boleh memaksa skill** dengan menyebut namanya. Itu menang atas tabel.

**Skill itu salinan dari luar.** Jangan ubah isinya untuk mengubah maknanya. Kalau skill bertentangan dengan aturan pemilik, aturan pemilik yang menang — catat pertentangannya di laporan.

---

## 4. Cara kerja

```
BACA → VERIFIKASI → RENCANA → UBAH → UJI → TINJAU DIFF → PR → CI → MERGE → DEPLOY → VERIFIKASI
```

Tidak semua tugas sampai DEPLOY. Pekerjaan dokumen boleh berhenti setelah merge.

**Dilarang:** MENGANGGAP → MENGUBAH → MENGAKU SELESAI.

- Jangan mengaku berhasil tanpa bukti dari alat (output, test, tangkapan layar).
- Alat yang gagal, timeout, atau jawabannya tidak jelas **bukan** keberhasilan.
- Jangan lapor PR "sudah merge" padahal masih terbuka. Jangan lapor "sudah deploy" padahal baru merge.
- **Bug sekecil apa pun: audit dulu, bahas dengan pemilik, baru betulkan** — kecuali pemilik sudah memberi izin untuk kasus itu.
- **Jangan pernah menyajikan data karangan sebagai hasil.** Kalau tidak bisa memeriksa, bilang tidak bisa.

---

## 5. Aturan keras pemilik (tidak bisa ditawar)

### Uang & pesanan

- **Refund tidak pernah otomatis.** Tidak ada kode yang memindahkan uang tanpa keputusan admin. Member diselesaikan lewat **penyesuaian saldo oleh Super Admin** (wajib alasan, tercatat di audit). Guest lewat transfer manual di luar sistem.
- **Jangan pernah memicu refund lewat gateway.**
- **Pengiriman tidak pernah dimulai sebelum pembayaran lunas.**
- **Tidak ada pengiriman ganda.** Satu pesanan dikirim sekali.
- **Tidak ada failover otomatis** ke sumber kedua. Penyedia konfirmasi gagal → pesanan jadi "perlu tindakan superadmin", berhenti.
- **Saldo tidak boleh minus.** Semua mutasi lewat ledger, tidak bisa dihapus atau diubah.
- **Harga pesanan tidak pernah berubah setelah pesanan dibuat.**
- **Uji pembayaran hanya simulasi/sandbox.** Tidak ada transaksi uang nyata tanpa izin pemilik.

### Digiflazz

- Endpoint `POST https://api.digiflazz.com/v1/transaction`; `sign = md5(username + apiKey + ref_id)`.
- Respons sinkron (sukses/gagal/pending). Pending dicek ulang dengan **`ref_id` yang sama**, tidak lebih sering dari **1 menit**.
- **Jangan pernah cek transaksi lebih dari 90 hari** — Digiflazz akan membuat transaksi baru.
- Webhook: `X-Hub-Signature: sha1=HMAC-SHA1(body, secret)`, `X-Digiflazz-Event`.
- Daftar harga (`cmd=prepaid`, `sign = md5(username+apiKey+"pricelist")`) **disimpan sendiri**, tidak ditanyakan saat pengunjung membuka toko.
- Satu SKU per nominal. Konsep `_A`/`_B` sudah mati.

### Harga, konfigurasi & template

- **Harga warisan (inheritance)**: harga khusus produk yang tidak diisi harus tetap `null` dan ikut harga global. UI yang diam-diam mengirim tarif global akan membekukan harga produk itu selamanya. Uji dua kasus: kosong (warisan) dan diisi (khusus).
- **Backfill konfigurasi bersama** hanya boleh bila **seluruh** record anak sepakat pada satu nilai tidak kosong. Kesepakatan dari sebagian record saja tidak sah — record kosong atau bertentangan membuat nilai itu berbahaya.
- **Memindahkan konfigurasi dari record anak ke record induk** wajib ditelusuri dulu: semua jalur baca, tulis, impor/sync, simpan admin, urutan request UI, dan fixture test. Input induk dan anak yang saling bergantung harus satu request + satu transaksi.
- **Endpoint update sebagian** harus bisa membedakan nilai yang dikirim eksplisit dari nilai lama yang tidak ikut dikirim. Jangan menolak edit yang tidak berhubungan hanya karena konfigurasi lain tidak ada di request.
- **Template fulfillment**: setiap input wajib penyedia harus punya rujukan di template, dan template tidak kosong harus punya minimal satu placeholder sah. Placeholder harus berbeda dari field lain (mis. validasi nickname).
- **Impor**: bila nilai untuk record lama memang tidak boleh diubah, kontrolnya dinonaktifkan dan nilai tersimpan ditampilkan. Pratinjau memakai markup efektif dari backend, bukan field margin lama.
- **Duplikasi record** dengan override harga `null` wajib menyalin override itu; uji keduanya tetap utuh.
- **Migrasi** diuji di database tes khusus, lalu jalur payload service dan suite admin/impor ikut diverifikasi. Migrasi sukses saja bukan bukti semua jalur sudah memakai sumber kebenaran baru.

### Data & rahasia

- **Jangan commit rahasia** (`.env`, API key, token, password, private key). Jangan taruh di Markdown, tangkapan layar, fixture, log, komentar PR, atau output CI.
- **Data pelanggan nyata tidak pernah dipakai** sebagai fixture, tangkapan layar, atau isi laporan publik.
- Jangan pernah minta atau menyimpan password game, PIN, atau kode OTP pelanggan.
- Kredensial integrasi tidak pernah tampil terbuka di layar atau log.

### Kode & tampilan

- **Tidak ada teks dummy** (`'QA'`, `'dummy'`, `'percobaan'`, `'test'`) di antarmuka. Gambar placeholder boleh.
- **Panel admin tidak menampilkan angka palsu.** Belum ada data → tampilkan kosong dengan keterangan.
- Tangkapan layar desktop **dan** HP wajib sebelum PR yang menyentuh tampilan.
- Jangan mematikan test atau pemeriksaan CI untuk membuat CI hijau.

### Lingkungan

- **Jangan menyentuh produksi** tanpa perintah tegas.
- **VPS: hanya baca** secara bawaan. Tidak menghapus/membersihkan tanpa perintah.
- **Jangan mengedit file langsung di produksi.**
- Jangan mengubah Cloudflare Tunnel, DNS, firewall, SSH, atau jaringan saat deploy.
- Jangan menghentikan, mematikan, atau me-restart proses tanpa perintah tegas.

### Komunikasi

- **Tanpa perkiraan.** Jangan bilang "sabar".
- Teks yang harus disalin pemilik ke HP: teks biasa, bukan blok kode. Perintah yang harus disalin berdiri sendiri di barisnya.
- Jangan melaporkan kemajuan berulang-ulang. Laporkan sekali di akhir, atau saat macet.

---

## 6. Alur git

Jangan pernah bekerja langsung di `main`.

```
main → branch fokus → commit terstruktur → PR → CI → review/perbaikan → merge hanya saat hijau
```

- Tidak ada force-push ke `main`.
- Tidak merge dengan pemeriksaan gagal.
- Satu PR = satu urusan. Jangan campur fitur tak berhubungan.
- Pesan commit menjelaskan perubahan sebenarnya.
- Isi PR: apa yang berubah, kenapa, bukti (test/pemeriksaan yang dijalankan), dan apa yang **tidak** berubah.
- **Jangan push, buka PR, atau merge tanpa izin pemilik.** Ini aturan yang berlaku sekarang.
- Setelah PR dibuka, minta review `@codex` di komentar PR tanpa menunggu CI.

---

## 7. Pemeriksaan lokal sebelum push

- **Gaya PHP:** `vendor/bin/pint --test` pada file PHP yang berubah. Ini pemeriksaan yang sama dengan CI. `php -l` hanya sintaks, **bukan** gaya — jangan andalkan itu saja.
- **Sintaks PHP:** `php -l` pada file yang berubah.
- **Test:** hanya test yang relevan dengan perubahan. Suite penuh dijalankan di CI, bukan di lokal.
- **Frontend:** `npm run lint`, `npm run check`, `npm run build` bila frontend berubah.
- Kalau muncul error lint/build, betulkan **semuanya sekaligus**, bukan satu per satu.
- Jalankan pemeriksaan yang tidak saling bergantung **bersamaan**, bukan berurutan.
- **Hook pre-push tidak boleh dilewati** (`--no-verify` dilarang).

---

## 8. Deploy (hanya atas perintah tegas)

- Deploy hanya kalau CI commit tujuan hijau.
- Backup database dibuat **dan diverifikasi** sebelum deploy.
- Preflight lulus sesuai `rebuild/deploy/README.md`.
- Hanya memakai mekanisme deploy resmi. Backup atau preflight gagal → **jangan deploy**.
- Urutan: PR merge → CI hijau → backup terenkripsi → `deploy/preflight.sh` → `deploy/deploy.sh` → `deploy/healthcheck.sh` → periksa queue, scheduler, log, service.
- Perubahan dokumen saja tidak perlu deploy.
- **PR dan deploy adalah dua hal terpisah.** Merge tidak berarti deploy. Deploy butuh izin sendiri.

---

## 9. Batas VPS

- Bawaan: **baca saja**.
- Tidak ada pembersihan atau penghapusan di VPS tanpa perintah tegas.
- VPS bukan pengganti pekerjaan repo biasa.
- Jangan mengedit file satu per satu di produksi.

---

## 10. Peran

`.claude/agents/` berisi satu file per peran. Baca file perannya sebelum mulai.

| Peran | File | Dipakai untuk |
|---|---|---|
| Coder | `coder.md` | Menulis kode Laravel |
| Reviewer | `reviewer.md` | Review, audit, keamanan |
| Payment | `payment.md` | Midtrans, verifikasi, status (bukan refund otomatis) |
| Frontend | `frontend.md` | Vue/Inertia/Tailwind, arah desain |
| Tester | `tester.md` | Pest, uji browser |
| Deployer | `deployer.md` | CI/CD, runbook deploy |
| Data | `data.md` | Query, rekonsiliasi (baca saja secara bawaan) |
| Planner | `planner.md` | Rencana sebelum koding |

---

## 11. Peta file

| Lokasi | Isi |
|---|---|
| `rebuild/` | Aplikasi aktif — Laravel 12, PHP 8.4, Vue 3 + Inertia v2 + Tailwind v4 |
| `docs/PRD.md` | Aturan produk |
| `docs/ADMIN-PANEL.md` | Spesifikasi panel admin |
| `docs/STATUS.md` | Apa yang sudah jalan, apa yang belum |
| `docs/archive/` | Dokumen lama — bukan aturan |
| `rebuild/*.md` | Dokumen teknis per domain (arsitektur, database, auth, produk, checkout, pembayaran, fulfillment, admin, keamanan, testing, deploy) |
| `.claude/agents/` | Peran |
| `.claude/skills/` | Cara teknis |

---

## 12. Standar selesai

Sebuah pekerjaan selesai kalau:

1. Perilakunya sesuai `docs/PRD.md` (dan `docs/ADMIN-PANEL.md` bila menyentuh panel) — kecuali perbedaan yang sudah diputuskan pemilik untuk kasus itu (lihat §1).
2. Ada test yang membuktikan perilakunya, **termasuk kasus gagal**.
3. Tidak melanggar satu pun aturan di §5.
4. Sudah dilihat di layar HP dan desktop (untuk pekerjaan tampilan).
5. Tidak ada teks dummy, tombol palsu, atau data contoh yang tertinggal.
6. Laporan menyebut: apa yang berubah, bukti, apa yang tidak berubah, skill yang dipakai/dilewati, dan apa yang masih terbuka.
