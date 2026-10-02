# Audit admin, konten legal, media, dan kapasitas VPS

Tanggal: 2 Oktober 2026 (WIB). Website: https://lfamiliastore.my.id.
Lingkup: Laravel produksi di VPS, GitHub LFAMILIA-STORE, dan frontend customer.
SSH, backup eksternal, dan layanan Digi Tools tidak termasuk pekerjaan ini.

## Admin dan aturan global

- Tombol Simpan Perubahan pada editor teks admin diuji langsung: perubahan judul FAQ tampil pada frontend, kemudian dikembalikan.
- Perubahan teks global dan ukuran judul diuji pada 390 dan 1440 px. Semua nilai uji dipulihkan.
- Upload logo melalui endpoint admin Spatie diuji menggunakan gambar logo yang sama. URL media berubah dan gambar baru termuat pada mobile serta desktop.
- Ketebalan informasi global diselaraskan ke 500 melalui customer_presentation; ketebalan judul 700, label 600, dan pertanyaan FAQ mobile 16 px tetap memakai pengaturan global.
- Panduan ukuran media terpusat dalam mediaRecommendations.js dan tampil pada kontrol unggah admin.
- Gambar footer mengikuti kotak tampilan customer; dimensi berkas sumber tidak menentukan kotak tampilnya.
- Build frontend dan 15 tes regresi admin, katalog/media, serta deployment lulus (178 assertion). Sebelumnya 120 tes aplikasi lulus (1.095 assertion).

## Rekomendasi gambar

Angka berikut adalah rekomendasi berkas unggahan berdasarkan CSS/area frontend, bukan dimensi gambar database. Area responsif dapat memotong gambar; letakkan subjek penting di tengah.

| Area customer | Rekomendasi unggahan | Dasar area tampil |
| --- | --- | --- |
| Logo | 256 × 256, transparan | Header 34–42 px; footer 50 px |
| Favicon | 512 × 512 | Ikon tab 16–32 px |
| Banner homepage desktop | 2560 × 800, 16:5 | Kontainer hingga 1280 px; tinggi desktop mengikuti proporsi gambar |
| Banner homepage mobile | 1320 × 600, 11:5 | Kotak sekitar 364 × 164 px pada viewport 390 |
| Card produk | 600 × 900, 2:3 | Card sekitar 200 × 300 px desktop; cover checkout memakai crop tengah |
| Banner produk | 2560 × 640 desktop | Hero responsif; mobile memakai 2:1, sekitar 390 × 195 px |
| Ikon kategori | 128 × 128, transparan | Ikon kecil sekitar 17–24 px |
| Ikon nominal | 256 × 256, transparan | Kotak sekitar 36–45 px |
| Gambar berita | 1600 × 900, 16:9 | Card mobile sekitar 364 × 205 px; desktop/sampul dapat memotong |
| Gambar pop-up | 1200 × 800, 3:2 | Panel hingga 570 px; batas tinggi 42vh desktop/34vh mobile |
| Footer mobile | 780 × 140 | Patokan viewport 390 dengan area tinggi 70 px |
| Footer desktop | 3840 × 232 | Patokan viewport 1920 dengan area tinggi 116 px; tinggi responsif 72–116 px |

Format unggah: JPEG, PNG, WebP; batas validasi admin 5 MB. Banner produk memakai satu gambar untuk desktop/mobile sehingga safe area tengah diperlukan.

## Kelengkapan konten legal

Sumber lama: commit 45eb340740ef0a72c02eed5fd3933a8bb7d5c176, app/privacy/page.tsx, app/refund/page.tsx, app/terms/page.tsx.
Dibandingkan dengan database/data/legal-pages-2026-10-02.json, database produksi, dan teks yang dirender browser.

| Halaman | Bagian bernomor sumber lama | Hasil |
| --- | --- | --- |
| Privacy | 12 | Seluruh paragraf, daftar, intro, dan judul cocok |
| Refund | 10 | Seluruh paragraf, daftar, intro, dan judul cocok |
| Terms | 14 | Seluruh paragraf, daftar, intro, dan judul cocok |

Database produksi identik dengan JSON migrasi. Tampilan seluruh teks lulus pada 390 dan 1440 px. Nomor judul ditampilkan sebagai elemen terpisah; isi tulisan tetap sama.

## Kapasitas VPS dan antrean

VPS terdeteksi 2 vCPU, RAM sekitar 4 GB, ruang disk tersedia sekitar 60 GB.
Uji dibatasi pada GET baca-saja melalui Nginx lokal dan Laravel, memakai tujuh halaman utama. Tidak melibatkan pembayaran atau panggilan pemasok.

| Koneksi bersamaan | Permintaan | Sukses / error | p50 | p95 | Laju terukur |
| --- | --- | --- | --- | --- | --- |
| 1 | 40 | 40 / 0 | 50 ms | 104 ms | 13,33 req/detik |
| 5 | 40 | 40 / 0 | 154 ms | 320 ms | 23,47 req/detik |
| 10 | 40 | 40 / 0 | 329 ms | 715 ms | 22,25 req/detik |

Ini adalah baseline singkat, bukan jaminan kapasitas maksimum atau hasil uji transaksi penuh. Di atas lima koneksi terjadi peningkatan antre tunggu; jika trafik tumbuh, ukur beban transaksi sesungguhnya sebelum menaikkan jumlah worker.
Memori tersedia selama uji sekitar 1,6 GB; load average sekitar 1,1.

Tugas uji ringan dikirim ke antrean Redis default dan diproses oleh worker produksi. Tugas hanya menulis kunci uji berumur pendek; kunci kemudian dihapus. Tidak mengirim email atau memanggil provider.

## Pemantauan error

- Worker, scheduler, dan healthcheck berfungsi; failed_jobs kosong setelah pengujian.
- Error Nginx yang ditemukan berasal dari penolakan akses keamanan dan permintaan lama; tidak ada error load test.
- Rotasi log gagal karena konfigurasi MariaDB lama bertabrakan dengan MySQL. Konfigurasi usang dipindahkan keluar dari logrotate.d.
- Rotasi log Laravel ditambahkan: harian, maxsize 10 MB, retensi 14, kompresi, menggunakan identitas akun aplikasi.
- Reload Nginx saat rotasi menggunakan systemd. logrotate.service berhasil dengan exit 0.
- Template rotasi disimpan di deploy/logrotate/lfamilia-app.in dan dipasang oleh installer operasi.
- Notifikasi eksternal dan uji transaksi gateway/provider belum diverifikasi karena kredensial integrasi produksi belum tersedia.
