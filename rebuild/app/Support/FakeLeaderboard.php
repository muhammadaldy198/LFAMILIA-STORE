<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Entri sintetis pelengkap papan peringkat pembeli: dihitung deterministik
 * per (periode, tanggal Asia/Jakarta) dari katalog live, tanpa menyimpan
 * baris apa pun di database — pola yang sama dengan dinding ulasan
 * (ReviewWall) dan umpan pesanan sintetis (FakeOrderFeed).
 *
 * Bukan urusannya: peringkat pembeli asli. Controller menggabungkan
 * keduanya; nilai pembeli asli tidak pernah diubah, dan pagar peringkat 1
 * di kelas ini memastikan total sintetis selalu di bawah total pembeli
 * asli teratas. Nama tampil memakai rumus alias yang sama persis dengan
 * pembeli asli (3 huruf pertama local part email + 9 bintang).
 */
class FakeLeaderboard
{
    private const TIMEZONE = 'Asia/Jakarta';

    /** Local part email persona sintetis; domain tidak pernah tampil (alias hanya dari local part). */
    private const PERSONAS = [
        'rizky.pratama', 'dewi.lestari', 'bagus.satria', 'agus.setiawan',
        'siti.rahma', 'dimas.anggara', 'putri.ayu', 'eko.purnomo',
        'maya.sari', 'rudi.hartono', 'andi.wijaya', 'sari.dewi',
        'joko.susilo', 'ratna.sari', 'bayu.aji', 'galih.prasetyo',
        'indah.permatasari', 'reza.fahlevi', 'novi.anggraini', 'dedi.supriadi',
        'wulan.sari', 'ilham.ramadhan', 'citra.lestari', 'fauzi.rahman',
        'mega.putri', 'arief.setiawan', 'sinta.dewi', 'yoga.pratama',
        'rani.anggraini', 'fitri.rahmawati', 'nur.hidayah', 'rifki.maulana',
        'salsa.nabila', 'teguh.santoso', 'vina.melati', 'wahyu.hidayat',
        'yuni.astuti', 'zaki.ramadhan', 'aldo.pratama', 'bella.safira',
        'fajar.nugroho', 'rina.marlina', 'yusuf.hakim', 'lisa.permata',
        'doni.setiawan', 'tika.rahma', 'budi.santoso', 'salsa.putri',
    ];

    /**
     * Rentang total teratas bila belum ada pembeli asli sama sekali —
     * sengaja seukuran belanja top up wajar (keputusan pemilik 19 Nov
     * 2026: total sintetis jangan yang mahal-mahal).
     */
    private const EMPTY_BOARD_TOP_MIN = 150_000;

    private const EMPTY_BOARD_TOP_MAX = 500_000;

    /**
     * @return list<array{name: string, order_count: int, total_spent: int}>
     */
    public static function entries(int $count, string $period, CarbonImmutable $now, ?int $realTopTotal): array
    {
        if ($count <= 0) {
            return [];
        }

        $prices = self::catalogPrices();
        if ($prices === []) {
            return [];
        }

        $date = $now->setTimezone(self::TIMEZONE)->format('Y-m-d');
        $personas = self::rotatedPersonas($period, $date);
        $cap = $realTopTotal === null ? null : (int) floor($realTopTotal * 0.92);
        if ($cap !== null && $cap < min($prices)) {
            // Pagar berada di bawah paket termurah: tidak ada total sintetis
            // yang masuk akal di bawah harga satu paket termurah — papan
            // dibiarkan murni asli daripada menampilkan pembeli yang mustahil.
            return [];
        }
        $emptyBoardTop = self::EMPTY_BOARD_TOP_MIN
            + (int) (self::unit("board|top|{$period}|{$date}") * (self::EMPTY_BOARD_TOP_MAX - self::EMPTY_BOARD_TOP_MIN));

        $entries = [];
        foreach (array_slice($personas, 0, $count) as $slot => $localPart) {
            $entries[] = self::entry($localPart, $slot, $period, $date, $prices, $cap, $emptyBoardTop);
        }

        return $entries;
    }

    /** Alias samaran: rumus yang sama dengan PublicContentController::leaderboardAlias. */
    public static function alias(string $email): string
    {
        $localPart = strstr($email, '@', true);

        return mb_substr($localPart === false ? $email : $localPart, 0, 3).'*********';
    }

    /**
     * Urutan persona untuk satu (periode, tanggal): diacak deterministik
     * dari hash, tanpa pengulangan, berputar tiap hari.
     *
     * @return list<string>
     */
    private static function rotatedPersonas(string $period, string $date): array
    {
        $personas = self::PERSONAS;
        usort($personas, fn (string $a, string $b): int => self::unit("board|ord|{$period}|{$date}|{$a}") <=> self::unit("board|ord|{$period}|{$date}|{$b}"));

        return $personas;
    }

    /**
     * Satu entri sintetis. Total ditentukan DULU sebagai pecahan
     * deterministik dari pagarnya, jumlah pesanan diturunkan dari total
     * itu. Urutan ini penting (perbaikan 19 Nov 2026): rumus lama
     * menghitung total dari jumlah pesanan × keranjang, hasilnya hampir
     * selalu melewati pagar lalu terpangkas ke angka pagar yang sama
     * persis — papan tampil seragam. Pecahan 25%–90% dari pagar
     * menyebarkan total ke seluruh rentang di bawah pembeli asli teratas,
     * dan total dijangkarkan ulang ke jumlah pesanan × keranjang supaya
     * pasangan (transaksi, total) tetap masuk akal.
     *
     * @param  list<int>  $prices
     * @return array{name: string, order_count: int, total_spent: int}
     */
    private static function entry(string $localPart, int $slot, string $period, string $date, array $prices, ?int $cap, int $emptyBoardTop): array
    {
        $seed = "{$period}|{$date}|{$slot}";

        // Keranjang rata-rata dari pool paket wajar; bila ada pagar, pilihan
        // dibatasi paket yang harganya tidak melewati pagar itu sendiri.
        $pool = $cap === null ? $prices : array_values(array_filter($prices, fn (int $price): bool => $price <= $cap));
        if ($pool === []) {
            $pool = $prices;
        }
        $basket = 0;
        $basketPicks = 1 + (int) (self::unit("board|picks|{$seed}") * 3);
        for ($pick = 0; $pick < $basketPicks; $pick++) {
            $basket += $pool[(int) (self::unit("board|price|{$seed}|{$pick}") * count($pool))];
        }
        $basket = max(1, intdiv($basket, $basketPicks));

        if ($cap === null) {
            // Papan kosong: total menurun mengikuti pangkat 0.82 per slot
            // dari tambatan atas; jitter ±8% selalu lebih kecil dari jarak
            // antar slot (0.82), jadi urutan antar slot tidak tertukar.
            $total = (int) round($emptyBoardTop * (0.82 ** $slot) * (0.92 + self::unit("board|frac|{$seed}") * 0.08));
            $orderCount = max(1, (int) round($total / $basket));
        } else {
            $total = (int) floor($cap * (0.25 + self::unit("board|frac|{$seed}") * 0.65));
            $orderCount = max(1, (int) round($total / $basket));
            $total = (int) round($orderCount * $basket * (1 + self::unit("board|jitter|{$seed}") * 0.03));
            if ($total >= $cap) {
                // Jangkar ulang bisa menyentuh pagar pada pagar kecil:
                // turunkan tepat di bawahnya, sesuaikan jumlah pesanan.
                $total = $cap - 1 - (int) (self::unit("board|deround|{$seed}") * 99);
                $orderCount = max(1, intdiv($total, $basket));
            }
        }

        if ($total % 1000 === 0) {
            $total -= 1 + (int) (self::unit("board|deround|{$seed}") * 99);
        }
        $total = max(1, $total);

        return [
            'name' => self::alias($localPart.'@gmail.com'),
            'order_count' => $orderCount,
            'total_spent' => $total,
        ];
    }

    /**
     * Harga efektif paket live dari katalog wajar bersama FakeOrderFeed
     * (sudah dihargai CheckoutPricing, ter-cache per tick, dan disaring ke
     * ≤ MODEST_PRICE_CAP_IDR), untuk keranjang rata-rata dan pagar
     * kewajaran jumlah pesanan.
     *
     * @return list<int>
     */
    private static function catalogPrices(): array
    {
        return array_map(
            fn (array $package): int => $package['price'],
            FakeOrderFeed::modestCatalog(),
        );
    }

    /**
     * Hash deterministik ke rentang [0, 1) — ketat di bawah 1 (pembagi 2^32);
     * sama dengan FakeOrderFeed::unit. Wajib sha256: crc32 difusinya lemah
     * untuk kunci yang hanya beda tanggal.
     */
    private static function unit(string $key): float
    {
        return hexdec(substr(hash('sha256', $key), 0, 8)) / 4294967296.0;
    }
}
