<?php

namespace App\Support;

use App\Models\Product;
use Carbon\CarbonImmutable;

/**
 * Dinding ulasan produk: angka ringkasan (total, sebaran bintang) dan daftar
 * ulasan terbaru yang dihitung deterministik dari bank teks ReviewBank,
 * tanpa menyimpan baris apa pun di database.
 *
 * Bukan urusannya: ulasan asli member (itu tetap dari tabel product_reviews;
 * controller menggabungkan keduanya). Semua keluaran murni fungsi dari
 * (slug produk, waktu) — permintaan yang sama selalu memberi hasil yang sama,
 * dan hitungan hanya tumbuh seiring waktu.
 */
class ReviewWall
{
    private const TIMEZONE = 'Asia/Jakarta';

    /** Dinding mulai berjalan dari momen ini; sebelum itu total = basis. */
    private const ANCHOR = '2026-11-18 00:00:00';

    /** slug => [total basis, peluang ulasan baru per jendela 5 menit]. */
    private const PRODUCTS = [
        'mobile-legends' => [2431, 0.22],
        'free-fire' => [1186, 0.16],
        'pubg-mobile' => [842, 0.13],
        'magic-chess-go-go' => [517, 0.11],
        'valorant' => [463, 0.10],
        'call-of-duty-mobile' => [358, 0.09],
        'blood-strike' => [174, 0.07],
        'delta-force-garena' => [132, 0.07],
    ];

    /** Bobot rating: rata-rata hasil ±4,86 sehingga tampil 4.9. */
    private const WEIGHTS = [5 => 0.91, 4 => 0.06, 3 => 0.017, 2 => 0.004, 1 => 0.009];

    private const TICK_SECONDS = 300;

    private const MAX_ENTRIES = 6;

    /**
     * Ringkasan + entri dinding untuk satu produk, atau null bila produk
     * tidak punya bank (dinding tidak berlaku; controller pakai data DB saja).
     */
    public static function forProduct(Product $product, CarbonImmutable $now): ?array
    {
        $config = self::PRODUCTS[$product->slug] ?? null;
        $bank = ReviewBank::BANKS[$product->slug] ?? null;
        if ($config === null || $bank === null) {
            return null;
        }

        [$base, $probability] = $config;
        $total = $base + self::grownCount($product->slug, $probability, $now);
        $distribution = self::distribution($total);

        return [
            'total' => $total,
            'distribution' => $distribution,
            'points' => self::points($distribution),
            'entries' => self::recentEntries($product->slug, $probability, $bank, $now),
        ];
    }

    /** Jumlah ulasan pertumbuhan sejak ANCHOR, dihitung per jam agar deterministik dan monoton. */
    private static function grownCount(string $slug, float $probability, CarbonImmutable $now): int
    {
        $anchor = self::anchor();
        if ($now->lessThanOrEqualTo($anchor)) {
            return 0;
        }

        $events = 0;
        $hour = $anchor;
        while ($hour->lessThan($now)) {
            $expected = 12 * $probability * self::nightFactor($hour);
            $jitter = 0.75 + 0.5 * self::unit($slug.'|growth|'.$hour->timestamp);
            $events += (int) round($expected * $jitter);
            $hour = $hour->addHour();
        }

        return $events;
    }

    /** Sebaran bintang dari total, mengikuti WEIGHTS; jumlahnya persis total. */
    private static function distribution(int $total): array
    {
        $counts = [];
        $assigned = 0;
        foreach (self::WEIGHTS as $star => $weight) {
            $counts[$star] = (int) floor($total * $weight);
            $assigned += $counts[$star];
        }

        // Sisa pembulatan diberikan dari bintang tertinggi: pada total kecil,
        // sisa ke bintang rendah menggerus rata-rata sampai jatuh ke 4.8.
        foreach ([5, 4, 3, 2, 1] as $star) {
            if ($assigned >= $total) {
                break;
            }
            $counts[$star]++;
            $assigned++;
        }

        return $counts;
    }

    private static function points(array $distribution): int
    {
        $points = 0;
        foreach ($distribution as $star => $count) {
            $points += $star * $count;
        }

        return $points;
    }

    /**
     * Entri terbaru: pindai jendela 5 menit mundur dari sekarang; sebuah
     * jendela berisi ulasan bila hash-nya di bawah peluang produk pada jam itu.
     */
    private static function recentEntries(string $slug, float $probability, array $bank, CarbonImmutable $now): array
    {
        $anchor = self::anchor();
        $currentTick = (int) floor(($now->timestamp - $anchor->timestamp) / self::TICK_SECONDS);
        $entries = [];

        for ($tick = $currentTick; $tick > $currentTick - 400 && count($entries) < self::MAX_ENTRIES; $tick--) {
            $tickTime = $anchor->addSeconds($tick * self::TICK_SECONDS);
            if (self::unit($slug.'|tick|'.$tick) >= $probability * self::nightFactor($tickTime)) {
                continue;
            }
            $entries[] = self::entry($slug, $bank, $tick, $tickTime, 0, $now);
            if (count($entries) < self::MAX_ENTRIES && self::unit($slug.'|tick-extra|'.$tick) < 0.15) {
                $entries[] = self::entry($slug, $bank, $tick, $tickTime, 1, $now);
            }
        }

        usort($entries, fn (array $a, array $b): int => strcmp($b['published_at'], $a['published_at']));

        return array_slice($entries, 0, self::MAX_ENTRIES);
    }

    /** Satu entri dinding; semua pilihan (nama, rating, teks, menit) dari hash (slug, tick, slot). */
    private static function entry(string $slug, array $bank, int $tick, CarbonImmutable $tickTime, int $slot, CarbonImmutable $now): array
    {
        $rating = self::weightedRating(self::unit($slug.'|rating|'.$tick.'|'.$slot));
        $name = ReviewBank::NAMES[(int) (self::unit($slug.'|name|'.$tick.'|'.$slot) * count(ReviewBank::NAMES))];

        if ($rating >= 4) {
            $body = $bank['bodies'][(int) (self::unit($slug.'|body|'.$tick.'|'.$slot) * count($bank['bodies']))];
            $title = self::unit($slug.'|title|'.$tick.'|'.$slot) < 0.4
                ? $bank['titles'][(int) (self::unit($slug.'|titlepick|'.$tick.'|'.$slot) * count($bank['titles']))]
                : null;
        } else {
            $body = ReviewBank::NEUTRAL_BODIES[(int) (self::unit($slug.'|body|'.$tick.'|'.$slot) * count(ReviewBank::NEUTRAL_BODIES))];
            $title = null;
        }

        $published = $tickTime->addSeconds((int) (self::unit($slug.'|jitter|'.$tick.'|'.$slot) * 240));
        if ($published->greaterThan($now)) {
            // Jendela berjalan belum selesai: jangan tampilkan waktu masa depan.
            $published = $now->subSeconds(20 + $slot * 37);
        }

        return [
            'id' => 'wall-'.$tick.'-'.$slot,
            'display_name' => substr($name, 0, 3).'*********',
            'rating' => $rating,
            'title' => $title,
            'body' => $body,
            'published_at' => $published->toIso8601String(),
            'created_at' => $published->toIso8601String(),
            'verified_purchase' => true,
        ];
    }

    private static function weightedRating(float $unit): int
    {
        $cumulative = 0.0;
        foreach (self::WEIGHTS as $star => $weight) {
            $cumulative += $weight;
            if ($unit < $cumulative) {
                return $star;
            }
        }

        return 1;
    }

    /** Jam sepi 00.00-06.00 WIB: peluang dibagi 6, pembeli asli juga tidur. */
    private static function nightFactor(CarbonImmutable $time): float
    {
        $hour = (int) $time->format('G');

        return $hour < 6 ? 1 / 6 : 1.0;
    }

    private static function anchor(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::ANCHOR, self::TIMEZONE);
    }

    /** Hash deterministik ke rentang [0, 1); stabil lintas proses dan mesin. */
    private static function unit(string $key): float
    {
        return ((float) sprintf('%u', crc32($key))) / 4294967295.0;
    }
}
