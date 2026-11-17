<?php

namespace App\Support;

/**
 * Bank teks ulasan per game untuk dinding ulasan (ReviewWall).
 *
 * Aturan keras isi: semua teks bersih positif. Dilarang kata bermasalah:
 * pending, lama, lambat, gagal, error, ribet, susah, kecewa, mahal, nunggu,
 * antri, bermasalah, komplain, refund, jelek, zonk, kendala, scam, tipu.
 * Daftar ini ditegakkan oleh test (ReviewWallTest memindai seluruh bank).
 */
class ReviewBank
{
    /** Nama depan Indonesia; ditampilkan ter-mask 3 huruf pertama + "*********". */
    public const NAMES = [
        'Rizki', 'Fajar', 'Dimas', 'Putri', 'Bagas', 'Yoga', 'Andi', 'Bima',
        'Citra', 'Dewi', 'Eko', 'Fitri', 'Gilang', 'Hana', 'Ilham', 'Jefri',
        'Kirana', 'Lukman', 'Maya', 'Nanda', 'Oki', 'Prasetyo', 'Rina', 'Satria',
        'Taufik', 'Umar', 'Vina', 'Wahyu', 'Yuda', 'Zahra', 'Aldi', 'Bimo',
        'Cahyo', 'Dedi', 'Erwin', 'Fikri', 'Galih', 'Hendra', 'Ivan', 'Rizal',
    ];

    /** Isi singkat netral-positif untuk ulasan bintang 1-3 (tanpa keluhan). */
    public const NEUTRAL_BODIES = [
        'Lumayan.', 'Oke.', 'Boleh juga.', 'Cukup memuaskan.', 'Not bad.',
        'Sesuai harapan.', 'Bagus kok.', 'Puas sih.', 'Overall oke.', 'Worth it.',
    ];

    /** Bank per slug produk: judul + isi, wajib memakai kosakata game-nya. */
    public const BANKS = [
        'mobile-legends' => [
            'titles' => ['Cepet banget', 'Amanah', 'WDP langsung aktif', 'Langganan tetap', 'Selalu instan', 'Recommended', 'Mantap', 'Gercep'],
            'bodies' => [
                'Baru bayar, semenit kemudian diamond udah masuk. Nggak pake drama.',
                'Udah keempat kalinya top up di sini, selalu masuk. Langganan tetap.',
                'Awalnya ragu karena baru pertama kali, ternyata beneran instan. Makasih admin.',
                'Beli Weekly Diamond Pass langsung aktif, kuota di game juga nambah. Recommended.',
                'Diamond masuk full sesuai paket, prosesnya cepet banget.',
                'Top up jam 11 malam tetap diproses instan. Mantap.',
                'Harga bersaing, diamond masuk sebelum sempat pindah aplikasi.',
                'Udah coba beberapa toko, di sini yang paling gercep buat ML.',
                'Isi zona ID bener, langsung masuk ke akun. Aman.',
                'WDP kedua bulan ini, tetap lancar jaya.',
                'Buat push rank enak, diamond selalu ready kapan dibeli.',
                'Pembayaran QRIS gampang, diamond langsung mendarat.',
                'Sering ada event top up, di sini nggak pernah ketinggalan.',
                'Customer servicenya fast respon pas gua nanya zona. Bagus.',
                'Diamond 257 masuk utuh, bonusnya juga sesuai.',
                'Langganan tiap minggu buat WDP, sejauh ini selalu amanah.',
            ],
        ],
        'free-fire' => [
            'titles' => ['Instan', 'Siap Booyah', 'Membership aktif', 'Langganan squad', 'Gercep banget', 'Amanah', 'Puas', 'Recommended'],
            'bodies' => [
                'Diamond FF masuk hitungan detik. Siap Booyah lagi.',
                'Membership mingguan langsung aktif, klaim hariannya jalan.',
                'Udah langganan membership bulanan di sini, selalu aman.',
                'Top up buat beli bundle, diamondnya masuk full.',
                'Prosesnya cepet, tinggal isi ID langsung beres.',
                'Harga diamond di sini bersahabat buat kantong pelajar.',
                'Beli pas event double diamond, masuknya sesuai janji event.',
                'Temen satu squad semua top up di sini sekarang.',
                'Membership bulanan kedua, tetap instan kayak pertama.',
                'Diamond masuk sebelum match berikutnya mulai. Gercep.',
                'Bayar pakai QRIS, langsung bisa gacha bundle baru.',
                'Setiap minggu isi di sini buat membership, selalu beres.',
                'Buat yang sering Booyah, top up di sini enak banget.',
                'ID dicek dulu sama sistem, jadi nggak salah kirim.',
                'Diamond 140 masuk utuh, bonus membership juga jalan.',
                'Top up tengah malam tetap instan. Puas.',
            ],
        ],
        'pubg-mobile' => [
            'titles' => ['UC instan', 'Royale Pass ready', 'Amanah', 'Gercep', 'Langganan musim', 'Mantap', 'Recommended', 'Puas'],
            'bodies' => [
                'UC masuk cepat, langsung bisa buka Royale Pass.',
                'Top up UC buat spin, prosesnya mulus banget.',
                'Udah tiga kali beli UC di sini, selalu utuh masuknya.',
                'Harga UC bersaing, masuknya juga instan.',
                'Beli UC jam segini tetap diproses detik itu juga.',
                'Royale Pass musim ini kebeli dari UC sini. Mantap.',
                'Isi ID bener, UC langsung mendarat di akun.',
                'Buat persiapan turnamen kecil, UC selalu ready di sini.',
                'Pembayaran gampang, UC masuk sebelum lobby mulai.',
                'Langganan UC tiap musim, sejauh ini selalu amanah.',
                'UC 60 masuk, cukup buat buka crate favorit.',
                'Temen mabar rekomendasiin toko ini, ternyata beneran bagus.',
                'Top up pas ada promo UC, dapet harga enak.',
                'Nggak perlu kartu kredit, QRIS aja UC langsung masuk.',
                'Proses dari bayar sampai UC masuk nggak sampai semenit.',
                'Puas, bakal balik lagi musim depan.',
            ],
        ],
        'valorant' => [
            'titles' => ['VP instan', 'Skin kebeli', 'Gercep parah', 'Amanah', 'Langganan bundle', 'Mantap', 'Recommended', 'Bersih banget'],
            'bodies' => [
                'Points masuk instan, langsung checkout skin favorit.',
                'Beli VP buat battle pass, prosesnya bersih banget.',
                'Top up jam 1 pagi tetap langsung masuk. Enak.',
                'Harga VP di sini lebih enak dari in-game store.',
                'Udah dua kali beli Points, dua-duanya detik itu juga masuk.',
                'Skin vandal incaran akhirnya kebeli. Makasih.',
                'Isi Riot ID bener, VP langsung nambah.',
                'Buat night market, VP ready dalam sekejap.',
                'Pembayaran QRIS beres, VP langsung bisa dipakai.',
                'Langganan tiap ada bundle baru, selalu amanah.',
                'Nggak sampai semenit, Points udah di akun.',
                'Temen satu party semua top up di sini.',
                'VP 475 masuk utuh sesuai paket.',
                'Prosesnya rapi, ada invoice jelas di email.',
                'Sekali coba langsung jadi langganan.',
                'Gercep parah, belum sempat alt-tab VP udah masuk.',
            ],
        ],
        'call-of-duty-mobile' => [
            'titles' => ['CP instan', 'Langsung draw', 'Amanah', 'Gercep', 'Langganan season', 'Mantap', 'Recommended', 'Simpel banget'],
            'bodies' => [
                'CP masuk cepat, langsung buka lucky draw.',
                'Top up CP buat battle pass, beres dalam sekejap.',
                'Udah langganan CP di sini tiap season, selalu aman.',
                'Harga CP bersaing, masuknya instan.',
                'Beli CP tengah malam tetap diproses langsung.',
                'Isi UID bener, CP langsung mendarat di akun.',
                'Buat persiapan ranked, CP ready kapan aja.',
                'Pembayaran gampang, CP langsung bisa dibelanjakan.',
                'CP 80 masuk utuh, cukup buat satu draw.',
                'Temen clan rekomendasiin, ternyata beneran gercep.',
                'Season baru, top up di sini lagi. Konsisten bagus.',
                'Prosesnya simpel banget, langsung beres.',
                'Top up pas event CP bonus, masuknya sesuai.',
                'Puas sama kecepatannya, bakal balik lagi.',
                'CP masuk sebelum match dimulai. Mantap.',
                'Sekali transaksi langsung percaya, amanah tokonya.',
            ],
        ],
        'magic-chess-go-go' => [
            'titles' => ['Instan', 'Commander kebeli', 'Amanah', 'Langganan MCGG', 'Gercep', 'Mantap', 'Recommended', 'Puas'],
            'bodies' => [
                'Diamond Magic Chess masuk instan, langsung beli commander.',
                'Top up buat skin chessboard, prosesnya cepet.',
                'Udah langganan di sini tiap update MCGG, selalu aman.',
                'First recharge MCGG di sini, diamond gandanya masuk sesuai.',
                'Harga diamond bersaing buat pemain Magic Chess.',
                'Isi zona bener, diamond langsung nambah.',
                'Beli pass Magic Chess, aktif detik itu juga.',
                'Pembayaran QRIS gampang, diamond langsung bisa dipakai.',
                'Main MCGG tiap hari, top up-nya selalu di sini.',
                'Diamond masuk full, bonus first top up juga dapet.',
                'Proses dari bayar ke masuk nggak sampai semenit.',
                'Temen satu lobi rekomendasiin toko ini.',
                'Top up malam tetap instan. Puas.',
                'Buat koleksi skin commander, diamond selalu ready.',
                'Sekali coba langsung langganan.',
                'Gercep, diamond mendarat sebelum match mulai.',
            ],
        ],
        'blood-strike' => [
            'titles' => ['Golds instan', 'Gercep', 'Amanah', 'Langganan', 'Mantap', 'Recommended', 'Puas', 'Siap ranked'],
            'bodies' => [
                'Golds masuk instan, langsung beli skin senjata.',
                'Top up Golds buat battle pass, beres sekejap.',
                'Udah dua kali isi Golds di sini, selalu utuh.',
                'Harga Golds bersaing, prosesnya gercep.',
                'Isi ID bener, Golds langsung nambah di akun.',
                'Beli pas event, Golds masuk sesuai paket.',
                'Pembayaran gampang, tinggal QRIS langsung beres.',
                'Main tiap hari, top up Golds selalu di sini.',
                'Golds 100 masuk full, siap ranked lagi.',
                'Prosesnya cepat, nggak sampai semenit.',
                'Temen squad rekomendasiin, ternyata amanah.',
                'Top up tengah malam tetap langsung diproses.',
                'Sekali transaksi langsung jadi langganan.',
                'Buat pemain Blood Strike, toko ini recommended.',
                'Invoice jelas masuk email, Golds juga langsung masuk.',
                'Puas, bakal balik lagi pas pass baru.',
            ],
        ],
        'delta-force-garena' => [
            'titles' => ['Coins instan', 'Gercep', 'Amanah', 'Langganan season', 'Mantap', 'Recommended', 'Puas', 'Simpel'],
            'bodies' => [
                'Delta Coins masuk instan, langsung beli bundle operator.',
                'Top up buat battle pass Delta Force, prosesnya cepet.',
                'Udah langganan di sini sejak main DF Garena, selalu aman.',
                'Coins masuk full sesuai paket yang dipilih.',
                'Isi ID bener, coins langsung nambah.',
                'Harga coins bersaing buat pemain DF Indonesia.',
                'Pembayaran QRIS beres, coins langsung bisa dipakai.',
                'Beli coins malam hari tetap diproses detik itu juga.',
                'Proses dari bayar sampai masuk nggak sampai semenit.',
                'Temen satu platoon semua top up di sini.',
                'Sekali coba langsung percaya, tokonya amanah.',
                'Coins ready buat event terbaru. Mantap.',
                'Top up simpel, tinggal isi ID langsung beres.',
                'Puas sama pelayanannya, bakal balik lagi.',
                'Gercep banget, coins mendarat sebelum briefing mulai.',
                'Langganan tiap season, konsisten bagus.',
            ],
        ],
    ];
}
