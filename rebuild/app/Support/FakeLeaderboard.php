<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

    /** Rentang total teratas bila belum ada pembeli asli sama sekali. */
    private const EMPTY_BOARD_TOP_MIN = 2_500_000;

    private const EMPTY_BOARD_TOP_MAX = 6_000_000;

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
     * Satu entri sintetis. Total = jumlah pesanan × keranjang rata-rata dari
     * harga paket live + jitter, dibulatkan supaya tidak berakhiran 000
     * persis, lalu dipagari: tidak pernah mencapai total pembeli asli
     * teratas (cap), dan jumlah pesanan diturunkan bila total terpagar
     * tidak lagi masuk akal untuk jumlah itu.
     *
     * @param  list<int>  $prices
     * @return array{name: string, order_count: int, total_spent: int}
     */
    private static function entry(string $localPart, int $slot, string $period, string $date, array $prices, ?int $cap, int $emptyBoardTop): array
    {
        $seed = "{$period}|{$date}|{$slot}";
        $orderCount = 3 + (int) (self::unit("board|count|{$seed}") * 43);
        if ($period === 'all') {
            $orderCount *= 2 + (int) (self::unit("board|allfactor|{$seed}") * 3);
        }

        $basket = 0;
        $basketPicks = 1 + (int) (self::unit("board|picks|{$seed}") * 3);
        for ($pick = 0; $pick < $basketPicks; $pick++) {
            $basket += $prices[(int) (self::unit("board|price|{$seed}|{$pick}") * count($prices))];
        }
        $basket = intdiv($basket, $basketPicks);

        $total = (int) round($orderCount * $basket * (1 + self::unit("board|jitter|{$seed}") * 0.03));
        if ($total % 1000 === 0) {
            $total -= 1 + (int) (self::unit("board|deround|{$seed}") * 99);
        }

        if ($cap === null) {
            // Papan kosong: entri teratas ditambatkan ke rentang yang wajar,
            // entri berikutnya menurun mengikuti pangkat 0.82 per slot.
            $floor = (int) floor($emptyBoardTop * (0.82 ** $slot));
            $total = max($total, $floor);
        } else {
            $total = min($total, $cap);
        }
        $total = max(1, $total);

        $maxPlausibleCount = max(1, intdiv($total, min($prices)));
        $orderCount = min($orderCount, $maxPlausibleCount);

        return [
            'name' => self::alias($localPart.'@gmail.com'),
            'order_count' => $orderCount,
            'total_spent' => $total,
        ];
    }

    /**
     * Harga jual paket aktif dari produk aktif (read-only), untuk keranjang
     * rata-rata dan pagar kewajaran jumlah pesanan.
     *
     * @return list<int>
     */
    private static function catalogPrices(): array
    {
        return DB::table('product_packages')
            ->join('products', 'products.id', '=', 'product_packages.product_id')
            ->where('product_packages.is_active', true)
            ->where('products.is_active', true)
            ->where('product_packages.sell_price_idr', '>', 0)
            ->orderBy('product_packages.id')
            ->pluck('product_packages.sell_price_idr')
            ->map(fn (mixed $price): int => (int) $price)
            ->all();
    }

    /**
     * Hash deterministik ke rentang [0, 1); sama dengan ReviewWall::unit.
     * Wajib sha256: crc32 difusinya lemah untuk kunci yang hanya beda angka tick.
     */
    private static function unit(string $key): float
    {
        return hexdec(substr(hash('sha256', $key), 0, 8)) / 4294967295.0;
    }
}
