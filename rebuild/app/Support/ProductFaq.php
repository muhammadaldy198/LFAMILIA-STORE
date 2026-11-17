<?php

namespace App\Support;

use App\Models\Product;

/**
 * FAQ halaman produk. Data statis (tanpa database, tanpa cache), dua lapis:
 * entri khusus game (dikunci berdasarkan slug produk) lalu entri umum.
 * Terpisah dari tabel faq_entries yang tetap melayani halaman /faq.
 */
final class ProductFaq
{
    /** @var array<string, list<array{question: string, answer: string}>> */
    private const GAME_SPECIFIC = [
        'mobile-legends' => [
            ['question' => 'Apa batas pembelian Weekly Diamond Pass?', 'answer' => 'Weekly Diamond Pass ada batas pembelian dari Moonton, ngikutin kuota di akun kamu. Sisa masa aktif ditambah 7 hari untuk setiap pass baru tidak boleh melewati 70 hari. Cek dulu di dalam game: buka logo diamond, pilih Weekly Diamond Pass — angka kecil di samping sisa hari itu sisa kuota kamu. Contoh (2/10) artinya masih bisa beli 2x lagi. Kalau (0/10), jangan checkout dulu karena WDP barunya bisa gagal masuk.'],
            ['question' => 'Kenapa paket First Recharge / Diamond Ganda saya berwarna abu-abu?', 'answer' => 'Paket itu khusus untuk akun yang belum pernah membeli paket yang sama. Kalau akunmu sudah pernah mengisinya, paket tampil abu-abu dan tidak bisa dipilih. Itu aturan dari Moonton, bukan error.'],
            ['question' => 'Kenapa top up Mobile Legends butuh ID dan Zone ID? Apakah region akun berpengaruh?', 'answer' => 'Mobile Legends memakai User ID dan Zone ID (angka di dalam kurung pada profil kamu). Yang bisa diproses adalah akun region Indonesia; akun region lain ditolak oleh sistem demi keamanan pesananmu.'],
            ['question' => 'Apa arti angka di dalam kurung pada nama paket, misalnya 257 Diamonds (233+24)?', 'answer' => 'Itu rincian isi paket: angka pertama adalah diamond utama, angka sesudah tanda tambah adalah bonusnya. Total diamond yang masuk ke akunmu adalah penjumlahan keduanya.'],
        ],
        'free-fire' => [
            ['question' => 'Apakah Membership Mingguan Free Fire sama dengan Weekly Diamond Pass Mobile Legends?', 'answer' => 'Beda produk, beda aturan. Membership Mingguan Free Fire tidak memakai batas kuota 70 hari seperti Weekly Diamond Pass Mobile Legends; masa aktifnya mengikuti ketentuan di dalam game Free Fire.'],
            ['question' => 'Data apa yang dibutuhkan untuk top up Free Fire?', 'answer' => 'Cukup ID pemain Free Fire kamu. Pastikan ID-nya benar — nama akunmu akan ditampilkan untuk kamu periksa sebelum membayar.'],
        ],
        'pubg-mobile' => [
            ['question' => 'PUBG Mobile di toko ini untuk akun region apa?', 'answer' => 'Katalog kami khusus akun region Indonesia. Akun dari region lain tidak bisa diproses.'],
        ],
        'valorant' => [
            ['question' => 'Data apa yang dibutuhkan untuk top up Valorant?', 'answer' => 'Riot ID lengkap beserta tagline-nya (contoh: NamaKamu#TAG1). Salin persis seperti di profil Riot kamu — kesalahan tagline membuat pengiriman gagal.'],
        ],
        'magic-chess-go-go' => [
            ['question' => 'Kenapa paket First Recharge saya berwarna abu-abu?', 'answer' => 'Paket First Recharge khusus untuk akun yang belum pernah membeli paket yang sama. Kalau akunmu sudah pernah mengisinya, paket tampil abu-abu dan tidak bisa dipilih. Itu aturan dari Moonton, bukan error.'],
        ],
    ];

    /** @var list<array{question: string, answer: string}> */
    private const GENERAL = [
        ['question' => 'Berapa lama top up saya diproses?', 'answer' => 'Otomatis setelah pembayaran terkonfirmasi — umumnya beberapa menit. Kalau sedang antre di provider bisa lebih lama; pantau terus lewat halaman status pesanan.'],
        ['question' => 'Metode pembayaran apa saja yang tersedia?', 'answer' => 'Pilihan metode pembayaran tampil lengkap di langkah pembayaran saat kamu checkout (antara lain QRIS, GoPay, Virtual Account, dan saldo LFAMILIA Coin, sesuai yang sedang aktif). Biaya admin (kalau ada) selalu tampil jelas di ringkasan sebelum kamu menekan tombol pesan — tidak ada biaya tambahan yang muncul sesudahnya.'],
        ['question' => 'Bisakah membeli tanpa membuat akun?', 'answer' => 'Bisa. Checkout tamu cukup memakai email dan nomor WhatsApp yang aktif — invoice pesanan dikirim ke email kamu, jadi pastikan emailnya tidak salah ketik.'],
        ['question' => 'Bagaimana cara memeriksa status pesanan saya?', 'answer' => 'Buka halaman cek status pesanan, lalu masukkan nomor invoice atau nomor WhatsApp yang kamu pakai saat checkout. Kalau kamu punya akun, semua pesananmu juga tampil di menu pesanan saya setelah login.'],
        ['question' => 'Pembayaran saya berhasil tapi pesanan belum masuk. Apa yang harus saya lakukan?', 'answer' => 'Tunggu beberapa menit, lalu cek status pesananmu. Bila statusnya gagal, hubungi dukungan lewat halaman bantuan dan sertakan nomor invoice kamu — tim kami akan memeriksa pesananmu, dan bila kegagalannya sudah terkonfirmasi, memproses pengiriman ulang ke akun tujuan yang sama.'],
        ['question' => 'Apakah saya bisa meminta pengembalian dana (refund)?', 'answer' => 'Semua pembelian yang sudah diproses bersifat final dan tidak dapat dikembalikan. Pesanan yang gagal terkirim diselesaikan lewat dukungan: setelah kegagalannya terkonfirmasi, dukungan memproses pengiriman ulang ke akun tujuan yang sama, bukan pengembalian dana — jadi periksa kembali data akunmu sebelum membayar.'],
        ['question' => 'Saya salah memasukkan ID akun. Bisakah pesanan dibatalkan atau dipindahkan?', 'answer' => 'Pesanan yang sudah terkirim mengikuti data yang kamu masukkan dan tidak bisa ditarik kembali atau dipindahkan ke akun lain. Kalau pesananmu belum terkirim, segera hubungi dukungan — perubahan hanya mungkin selama pesanan belum diproses ke provider.'],
        ['question' => 'Bagaimana cara memakai kode voucher?', 'answer' => 'Masukkan kode di kolom voucher diskon sebelum membayar, tekan gunakan, dan potongan harganya langsung tampil di ringkasan pesanan.'],
    ];

    private const MANUAL_PROCESSING_ANSWER = 'Diproses manual oleh admin toko setelah pembayaran terkonfirmasi. Waktunya bisa lebih lama daripada produk otomatis — pantau terus lewat halaman status pesanan.';

    /**
     * @return list<array{question: string, answer: string}>
     */
    public static function forProduct(Product $product): array
    {
        $general = self::GENERAL;
        if ($product->fulfillment_mode === 'MANUAL') {
            $general[0]['answer'] = self::MANUAL_PROCESSING_ANSWER;
        }

        return [...(self::GAME_SPECIFIC[$product->slug] ?? []), ...$general];
    }
}
