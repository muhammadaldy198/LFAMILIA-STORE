<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->clearFaqOverrides();
        $this->updatePersistedRefundPage();
    }

    public function down(): void
    {
        // Persisted copy is not reverted to refund-promising wording.
    }

    /**
     * Saved admin overrides for the FAQ failed-order card would keep showing
     * the old "Gagal / Refund" text, because saved presentation values win
     * over the defaults. Clear exactly those two overrides so the current
     * defaults apply; every other saved value is left untouched.
     */
    private function clearFaqOverrides(): void
    {
        $row = DB::table('system_settings')->where('key', 'store.customer_presentation')->first();
        if ($row === null) {
            return;
        }

        $value = json_decode((string) $row->value, true);
        if (! is_array($value) || ! isset($value['text']) || ! is_array($value['text'])) {
            return;
        }

        unset($value['text']['faq.status.04.title'], $value['text']['faq.status.04.body']);

        DB::table('system_settings')->where('key', 'store.customer_presentation')
            ->update(['value' => json_encode($value), 'updated_at' => now()]);
    }

    /**
     * The /refund page renders from content_pages, so correcting the seed
     * JSON alone never reaches an already-migrated store. Bring the
     * persisted page up to date field by field: a field is replaced with
     * the current seed only while it still holds the exact pre-policy seed
     * text below. Any field an admin has edited differs from that text and
     * is left untouched.
     */
    private function updatePersistedRefundPage(): void
    {
        $page = DB::table('content_pages')->where('key', 'refund')->first();
        if ($page === null) {
            return;
        }

        $seed = json_decode(file_get_contents(database_path('data/legal-pages-2026-10-02.json')), true, 512, JSON_THROW_ON_ERROR);

        $updates = [];
        if ($page->intro === $this->oldSeedIntro()) {
            $updates['intro'] = $seed['refund']['intro'];
        }
        if ($page->body === $this->oldSeedBody()) {
            $updates['body'] = $seed['refund']['body'];
        }
        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = now();
        DB::table('content_pages')->where('key', 'refund')->update($updates);
    }

    private function oldSeedIntro(): string
    {
        return <<<'OLD_SEED_TEXT'
Karena top up, voucher, dan lisensi merupakan produk digital yang dapat dikirim atau digunakan segera, refund hanya tersedia untuk kondisi tertentu. Kebijakan ini menjelaskan kelayakan, cara pengajuan, dan waktu penanganannya.
OLD_SEED_TEXT;
    }

    private function oldSeedBody(): string
    {
        return <<<'OLD_SEED_TEXT'
Terakhir diperbarui: 31 Agustus 2026

## Ringkasan penting

- Produk yang sudah berhasil dikirim atau kode yang sudah digunakan tidak dapat direfund.

- Kesalahan User ID, server, produk, atau nominal menjadi tanggung jawab pembeli.

- Ajukan kendala maksimal 7 hari kalender sejak transaksi dengan nomor invoice.

## 01. Prinsip umum produk digital

Pesanan yang telah berstatus berhasil dan terkirim ke data tujuan dianggap selesai. Produk digital tidak dapat ditarik kembali setelah diserahkan, sehingga perubahan pikiran atau kesalahan pilihan pengguna bukan dasar refund. Setiap permintaan tetap diperiksa berdasarkan catatan sistem, pemasok, dan pembayaran.

## 02. Kondisi yang dapat diajukan

- Pembayaran terverifikasi, tetapi pesanan dinyatakan gagal oleh sistem atau pemasok dan tidak dapat diproses ulang.

- Terjadi pembayaran ganda untuk invoice atau pesanan yang sama.

- Produk ternyata tidak tersedia dan tidak dapat dipenuhi dalam waktu penanganan yang wajar.

- Nominal yang dikirim lebih kecil daripada produk yang dibayar dan selisih tidak dapat dipenuhi.

- Transaksi pembayaran dilaporkan tidak sah sebelum produk dikirim, setelah verifikasi kepemilikan dan pemeriksaan keamanan.

## 03. Kondisi yang tidak dapat direfund

- Produk telah berstatus berhasil atau terkonfirmasi terkirim ke data tujuan.

- Pengguna salah memilih game, produk, nominal, User ID, server, region, email, atau nomor tujuan.

- Kode voucher atau lisensi sudah ditampilkan, dikirim, dibuka, atau digunakan.

- Akun game diblokir, dibatasi, tidak memenuhi syarat wilayah, atau bermasalah karena kebijakan publisher.

- Bonus, event, promosi, atau hadiah dari publisher tidak diterima meskipun produk utama telah terkirim.

- Permintaan diajukan hanya karena perubahan pikiran, menemukan harga lain, atau keterlambatan singkat yang masih dalam proses wajar.

## 04. Kesalahan data tujuan

Sebelum membayar, pengguna wajib memeriksa kembali seluruh data tujuan. Jika pemasok telah mengirim produk sesuai data pada invoice, LFAMILIA tidak dapat memindahkan produk atau mengambilnya kembali. Apabila pesanan belum diproses, segera hubungi bantuan; penghentian pesanan tidak dapat dijamin.

## 05. Cara mengajukan refund

Ajukan melalui halaman Hubungi Kami paling lambat 7 hari kalender sejak transaksi, kecuali gangguan masih berstatus aktif dan telah dilaporkan sebelumnya.

- Cantumkan nomor invoice dan nama pembeli.

- Sertakan bukti pembayaran yang jelas serta screenshot status atau kendala.

- Jelaskan kronologi secara singkat, termasuk waktu kejadian dan data tujuan yang digunakan.

- Gunakan kanal resmi dan jangan pernah mengirim password, PIN, atau kode OTP.

## 06. Pemeriksaan permintaan

Tim akan memeriksa pembayaran, log pesanan, status pemasok, dan bukti yang diberikan. Pemeriksaan umumnya memerlukan 1–7 hari kerja, tetapi dapat lebih lama jika menunggu konfirmasi bank, gateway pembayaran, pemasok, atau publisher. Permintaan tambahan informasi harus dijawab agar pemeriksaan dapat dilanjutkan.

## 07. Keputusan dan metode pengembalian

Jika disetujui, refund diproses ke metode pembayaran asal jika didukung. Metode lain atau saldo akun hanya digunakan setelah disepakati dengan pengguna. Nilai refund mengikuti jumlah yang dinyatakan layak; biaya kanal yang tidak dikembalikan oleh penyedia pembayaran dapat dikecualikan dan akan dijelaskan pada hasil pemeriksaan.

## 08. Waktu dana diterima

Setelah refund diterbitkan oleh LFAMILIA, waktu dana masuk bergantung pada bank, dompet digital, atau gateway pembayaran. Proses pihak tersebut dapat memerlukan hingga 14 hari kerja. Jika melewati estimasi, hubungi bantuan dengan nomor invoice dan bukti keputusan refund agar dapat ditelusuri.

## 09. Pencegahan penyalahgunaan

Kami dapat menolak permintaan dengan bukti palsu, informasi yang tidak konsisten, pola penyalahgunaan promo, atau chargeback yang tidak sah. Akun dan transaksi terkait dapat dibatasi selama pemeriksaan keamanan, tanpa mengurangi hak pengguna yang diberikan oleh hukum.

## 10. Kontak dan perubahan kebijakan

Seluruh pengajuan dan pertanyaan refund harus melalui kanal resmi pada halaman Hubungi Kami. Kebijakan ini dapat diperbarui untuk menyesuaikan produk, mitra pembayaran, pemasok, dan ketentuan yang berlaku; versi terbaru akan menampilkan tanggal pembaruan.
OLD_SEED_TEXT;
    }
};
