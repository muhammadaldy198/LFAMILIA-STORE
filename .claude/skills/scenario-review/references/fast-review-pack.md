# Review cepat ala reviewer otomatis (paket teks, tanpa eksplorasi)

Tujuan: review pasca-commit selesai dalam hitungan menit, bukan puluhan menit, tanpa menurunkan mutu.

## 1. Bangun paket teks

```bash
mkdir -p /tmp/rv
git diff <base>..HEAD > /tmp/rv/diff.txt
wc -l /tmp/rv/diff.txt
```

Lalu rakit satu berkas berisi, berurutan:

1. **Konteks tetap** — stack, zona waktu (simpan vs tampil), aturan produk yang berlaku (mis. semua edit lewat pop-up, tidak boleh kode mentah di layar).
2. **Diff lengkap.**
3. **Potongan berkas yang berubah**, bukan berkas penuh kalau berkasnya panjang: bagian skrip + bagian template yang tersentuh, dengan `...` di antaranya. Sertakan nomor baris supaya temuan bisa ditunjuk.
4. **Daftar temuan yang sudah diketahui/diperbaiki** — supaya tidak dilaporkan ulang.

Batas praktis: ~20 KB. Di atas itu pecah per berkas, bukan per agen penuh-alat.

## 2. Brief sub-agen

```
Kamu HANYA membaca <path paket>. Dilarang membuka repo, grep, atau menjalankan alat
selain read_file pada berkas itu. Nilai hanya dari teks di dalamnya.
Untuk tiap temuan: file:baris, skenario pemicu berurutan, akibat yang terlihat
pengguna, perbaikan satu baris. Hanya cacat yang bisa dibuktikan dari teks.
Kalau tidak ada: tulis tepat 'LOLOS - tidak ada cacat terbukti'.
```

Kalimat "dilarang membuka repo" adalah bagian yang membuatnya cepat. Tanpa itu, agen akan menghabiskan waktunya memverifikasi, bukan menilai.

## 3. Skenario lanjutan per hunk (yang wajib dipikirkan)

Untuk setiap hunk, tanyakan berurutan — ini yang membedakan review berguna dari review kosmetik:

1. **Nilai yang dibaca bisa kosong?** Setiap `x().prop` di template/handler: apa yang terjadi saat `x()` bernilai null (panel baru ditutup, baris baru dihapus, props belum ada)? Pakai `?.` atau `v-if`.
2. **Urutan operasi batas waktu.** Konversi zona → `startOfDay`/`endOfDay`: urutan mana yang benar? Hitung angkanya, jangan dikira-kira (lihat §4).
3. **State yang diubah di dua tempat.** Draf vs baris daftar: apakah Batal benar-benar membuang perubahan, dan apakah Simpan tetap memperbarui daftar?
4. **Props refresh saat editor terbuka.** Apakah draf bertahan, atau tertimpa `toItem()`?
5. **Jalur gagal.** 422/500: panel tetap terbuka dengan isian utuh dan pesan galat terlihat?
6. **Tutup lewat jalur lain.** X, overlay, Escape — apakah semuanya melakukan hal yang sama dengan tombol Batal?
7. **Kode/istilah program** yang bisa lolos ke layar (`BLOCKED`, `PENDING`, snake_case, nama bahasa Inggris).

## 4. Buktikan batas waktu dengan angka

Jangan menalar zona waktu di kepala. Cetak hasilnya:

```bash
php -r '
require "vendor/autoload.php";
use Carbon\Carbon;
$s = fn($d) => Carbon::createFromFormat("Y-m-d", $d, "Asia/Jakarta")->startOfDay()->setTimezone("UTC");
$e = fn($d) => Carbon::createFromFormat("Y-m-d", $d, "Asia/Jakarta")->endOfDay()->setTimezone("UTC");
printf("%s .. %s\n", $s("2026-10-22")->toDateTimeString(), $e("2026-10-22")->toDateTimeString());
'
```

Yang benar untuk satu hari WIB penuh: `2026-10-21 17:00:00 .. 2026-10-22 16:59:59` UTC. Kalau hasilnya berakhir `16:00` atau `23:59` di tanggal yang salah, batas harinya salah dan laporan memotong/menggeser data.

Aturan umum: **hitung dulu dalam zona tampilan (WIB), baru konversi ke zona simpan.** Mengonversi lebih dulu lalu memanggil `startOfDay`/`endOfDay` mengubah "akhir hari" menjadi akhir hari zona simpan — bug ini lolos dari test yang hanya mengecek jumlah baris.

## 5. Setelah review

- Temuan → parent memperbaiki, commit, ulangi §1–§3 pada commit baru (diff kecil, biasanya < 5 menit).
- Lolos → push, lalu minta review reviewer otomatis.
- Jangan menyatakan lolos berdasarkan gerbang uji; pernyataan lolos harus datang dari pembacaan diff.
